import Foundation

/// Live-Aktualisierung im Vordergrund per Server-Sent Events (`GET events`, docs/API.md).
///
/// Vor jeder Verbindung holt die App ein kurzlebiges Einmal-Ticket (`POST events/ticket`) –
/// das Access-Token erscheint nie in einer URL. Der Server beendet jede Verbindung nach
/// kurzer Laufzeit (`bye`); die App baut dann mit neuem Ticket und `last_event_id` neu auf.
/// Ereignisse nennen nur betroffene Zellen; Inhalte lädt die App über `sync`, `plan/team`
/// und `absences/my` nach. Bei dauerhaften Fehlern gibt die Verbindung auf und es bleibt
/// beim Sync (Push, App-Start, Pull-to-Refresh).
public actor LiveConnection {
    public enum State: Hashable, Sendable {
        case stopped
        case connecting
        case connected
        /// Server hat Live-Aktualisierung abgeschaltet (204) oder zu viele Fehler
        case unavailable
    }

    public struct Handlers: Sendable {
        public var onEvent: @Sendable (LiveEvent) async -> Void
        /// Server kennt den Cursor nicht mehr → alles neu laden
        public var onReset: @Sendable () async -> Void
        public var onState: @Sendable (State) async -> Void

        public init(onEvent: @escaping @Sendable (LiveEvent) async -> Void,
                    onReset: @escaping @Sendable () async -> Void,
                    onState: @escaping @Sendable (State) async -> Void = { _ in }) {
            self.onEvent = onEvent
            self.onReset = onReset
            self.onState = onState
        }
    }

    public static let defaultBackoff: [TimeInterval] = [1, 2, 5, 10, 30, 60]

    private let ticket: @Sendable () async throws -> EventsTicketResponse
    private let makeURL: @Sendable (EventsTicketResponse, String?) -> URL
    private let transport: EventStreamTransport
    private let extraHeaders: [String: String]
    private let handlers: Handlers
    private let backoff: [TimeInterval]
    private let sleep: @Sendable (TimeInterval) async throws -> Void

    private var loop: Task<Void, Never>?
    public private(set) var state: State = .stopped
    public private(set) var lastEventID: String?
    private var failures = 0

    public init(ticket: @escaping @Sendable () async throws -> EventsTicketResponse,
                makeURL: @escaping @Sendable (EventsTicketResponse, String?) -> URL,
                transport: EventStreamTransport,
                headers: [String: String] = [:],
                handlers: Handlers,
                backoff: [TimeInterval] = LiveConnection.defaultBackoff,
                sleep: @escaping @Sendable (TimeInterval) async throws -> Void = { try await Task.sleep(nanoseconds: UInt64($0 * 1_000_000_000)) }) {
        self.ticket = ticket
        self.makeURL = makeURL
        self.transport = transport
        extraHeaders = headers
        self.handlers = handlers
        self.backoff = backoff
        self.sleep = sleep
    }

    /// Bequemer Aufbau mit einem APIClient.
    public init(api: APIClient, transport: EventStreamTransport, handlers: Handlers, backoff: [TimeInterval] = LiveConnection.defaultBackoff) {
        var headers = ["Accept": "text/event-stream"]
        if let t = api.tenant { headers["X-PQ-Tenant"] = t }
        self.init(ticket: { try await api.eventsTicket() },
                  makeURL: { t, last in api.eventsURL(ticket: t, lastEventID: last) },
                  transport: transport, headers: headers, handlers: handlers, backoff: backoff)
    }

    public var isRunning: Bool { loop != nil }

    /// Startet die Verbindung (idempotent). Nach `unavailable` erst nach `reset()` wieder.
    public func start() {
        guard loop == nil, state != .unavailable else { return }
        failures = 0
        loop = Task { await self.run() }
    }

    public func stop() async {
        loop?.cancel()
        loop = nil
        await setState(.stopped)
    }

    /// Erlaubt nach `unavailable` einen neuen Versuch (z. B. nach Wiederverbindung).
    public func reset() {
        if state == .unavailable { state = .stopped }
        failures = 0
    }

    private func setState(_ s: State) async {
        guard state != s else { return }
        state = s
        await handlers.onState(s)
    }

    private enum Outcome {
        /// Verbindung lief regulär (ready empfangen) → sofort neu verbinden
        case healthy
        case failed
        case disabled
    }

    private func run() async {
        while !Task.isCancelled {
            await setState(.connecting)
            let outcome = await connectOnce()
            if Task.isCancelled { break }
            switch outcome {
            case .disabled:
                loop = nil
                await setState(.unavailable)
                return
            case .healthy:
                failures = 0
                try? await sleep(0.25)
            case .failed:
                guard failures < backoff.count else {
                    loop = nil
                    await setState(.unavailable)
                    return
                }
                let delay = backoff[failures]
                failures += 1
                await setState(.connecting)
                do { try await sleep(delay) } catch { break }
            }
        }
    }

    private func connectOnce() async -> Outcome {
        let t: EventsTicketResponse
        do {
            t = try await ticket()
        } catch {
            let e = APIError.wrap(error)
            // Endpunkt fehlt / Live abgeschaltet → nur Sync
            if e.knownCode == .notFound || e.knownCode == .moduleDisabled { return .disabled }
            return .failed
        }
        if Task.isCancelled { return .failed }
        var headers = extraHeaders
        headers["Cache-Control"] = "no-cache"
        let req = HTTPRequest(method: "GET", url: makeURL(t, lastEventID), headers: headers, timeout: 60)
        var parser = SSEParser(lastEventID: lastEventID)
        var healthy = false
        do {
            for try await chunk in transport.stream(req) {
                if Task.isCancelled { return healthy ? .healthy : .failed }
                switch chunk {
                case let .response(status, _):
                    if status == 204 { return .disabled }
                    if status != 200 { return .failed }
                case let .data(d):
                    for msg in parser.feed(d) {
                        lastEventID = parser.lastEventID
                        switch msg.event {
                        case "ready":
                            healthy = true
                            failures = 0
                            await setState(.connected)
                        case "bye":
                            return .healthy
                        case "reset":
                            await handlers.onReset()
                        case "published", "cells_changed", "request_changed":
                            if let ev = try? JSONDecoder().decode(LiveEvent.self, from: Data(msg.data.utf8)) {
                                await handlers.onEvent(ev)
                            }
                        default:
                            break
                        }
                    }
                    // Zeilen nur mit `id:` rücken den Cursor ebenfalls vor
                    if let id = parser.lastEventID { lastEventID = id }
                }
            }
        } catch {
            return healthy ? .healthy : .failed
        }
        return healthy ? .healthy : .failed
    }
}
