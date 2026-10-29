import Foundation

/// Client der Planquadro API v1.
///
/// - Access-Token nur im Arbeitsspeicher, Refresh-Token im `RefreshTokenStore` (Keychain).
/// - Refresh proaktiv 30 s vor Ablauf und reaktiv bei `401 token_expired`.
/// - **Single-Flight:** parallele Anfragen lösen genau einen Refresh aus – ein bereits
///   verwendetes Refresh-Token würde serverseitig die ganze Token-Familie sperren.
/// - Das neue Refresh-Token wird **vor** der Verwendung des Access-Tokens gespeichert.
/// - `401 invalid_token` bzw. gescheiterter Refresh → lokale Tokens weg, `onSessionExpired`.
///   Netzwerkfehler beim Refresh behalten das Refresh-Token.
public actor APIClient {
    public nonisolated let baseURL: URL
    public nonisolated let tenant: String?
    private let transport: HTTPTransport
    private let store: RefreshTokenStore
    private let timeout: TimeInterval
    private let now: @Sendable () -> Date
    private var onSessionExpired: (@Sendable () async -> Void)?
    private var onTokens: (@Sendable (TokenResponse) async -> Void)?

    private var accessToken: String?
    private var accessExpiresAt: Date = .distantPast
    private var refreshTask: Task<String, Error>?

    /// Sicherheitsabstand vor Ablauf des Access-Tokens.
    public static let expirySkew: TimeInterval = 30

    public init(baseURL: URL, tenant: String? = nil, transport: HTTPTransport, tokenStore: RefreshTokenStore,
                timeout: TimeInterval = 20, now: @escaping @Sendable () -> Date = { Date() },
                onSessionExpired: (@Sendable () async -> Void)? = nil, onTokens: (@Sendable (TokenResponse) async -> Void)? = nil) {
        self.onSessionExpired = onSessionExpired
        self.onTokens = onTokens
        self.baseURL = baseURL
        self.tenant = tenant
        self.transport = transport
        store = tokenStore
        self.timeout = timeout
        self.now = now
    }

    public func setHandlers(onSessionExpired: (@Sendable () async -> Void)?, onTokens: (@Sendable (TokenResponse) async -> Void)?) {
        self.onSessionExpired = onSessionExpired
        self.onTokens = onTokens
    }

    // MARK: Token-Verwaltung

    public var hasAccessToken: Bool { accessToken != nil }

    /// Verwirft das Access-Token (z. B. bei App-Sperre).
    public func dropAccessToken() {
        accessToken = nil
        accessExpiresAt = .distantPast
    }

    private func accept(_ t: TokenResponse) async throws {
        // Rotierendes Refresh-Token: zuerst persistieren, dann verwenden.
        try await store.set(t.refreshToken)
        accessToken = t.accessToken
        accessExpiresAt = now().addingTimeInterval(TimeInterval(max(0, t.expiresIn)))
        if let onTokens { await onTokens(t) }
    }

    /// Erneuert das Token-Paar (Single-Flight).
    @discardableResult
    public func refresh() async throws -> String {
        if let task = refreshTask { return try await task.value }
        let task = Task { try await self.performRefresh() }
        refreshTask = task
        defer { refreshTask = nil }
        return try await task.value
    }

    private func performRefresh() async throws -> String {
        guard let refreshToken = try await store.get() else {
            dropAccessToken()
            throw APIError(status: 401, .sessionExpired)
        }
        let tokens: TokenResponse
        do {
            tokens = try await send("POST", "auth/refresh", body: ["refresh_token": refreshToken])
        } catch {
            let e = APIError.wrap(error)
            if e.status == 401 || e.knownCode == .accountLocked || e.knownCode == .passwordChangeRequired {
                // Refresh-Token ungültig/gesperrt → lokal verwerfen, neu anmelden
                try? await store.set(nil)
                dropAccessToken()
                if let onSessionExpired { await onSessionExpired() }
                throw APIError(status: 401, e.knownCode == .accountLocked ? .accountLocked : .sessionExpired)
            }
            throw e // z. B. offline: Refresh-Token behalten
        }
        try await accept(tokens)
        return tokens.accessToken
    }

    private func validAccessToken() async throws -> String {
        if let t = accessToken, now() < accessExpiresAt.addingTimeInterval(-Self.expirySkew) { return t }
        return try await refresh()
    }

    // MARK: HTTP-Kern

    nonisolated func url(_ path: String, query: [String: String?] = [:]) -> URL {
        let clean = path.hasPrefix("/") ? String(path.drop(while: { $0 == "/" })) : path
        let (p, existingQuery) = clean.split(separator: "?", maxSplits: 1).map(String.init).splitPair()
        var c = URLComponents(url: baseURL.appendingPathComponentsPreservingSlashes(p), resolvingAgainstBaseURL: false)!
        var items = existingQuery.map { URLComponents(string: "?" + $0)?.queryItems ?? [] } ?? []
        for (k, v) in query.sorted(by: { $0.key < $1.key }) {
            if let v, !v.isEmpty { items.append(URLQueryItem(name: k, value: v)) }
        }
        c.queryItems = items.isEmpty ? nil : items
        return c.url!
    }

    nonisolated func headers(token: String?, json: Bool) -> [String: String] {
        var h = ["Accept": "application/json"]
        if let tenant { h["X-PQ-Tenant"] = tenant }
        if let token { h["Authorization"] = "Bearer \(token)" }
        if json { h["Content-Type"] = "application/json" }
        return h
    }

    private static let encoder: JSONEncoder = {
        let e = JSONEncoder()
        e.outputFormatting = [.sortedKeys]
        return e
    }()

    /// Einzelner Aufruf ohne Refresh-Logik.
    private func sendRaw(_ method: String, _ path: String, query: [String: String?] = [:], bodyData: Data?, token: String?) async throws -> HTTPResponse {
        let req = HTTPRequest(method: method, url: url(path, query: query), headers: headers(token: token, json: bodyData != nil), body: bodyData, timeout: timeout)
        let res: HTTPResponse
        do {
            res = try await transport.send(req)
        } catch {
            throw APIError.wrap(error)
        }
        guard (200..<300).contains(res.status) else {
            throw APIError.from(status: res.status, body: res.body, retryAfterHeader: res.header("Retry-After"), now: now())
        }
        return res
    }

    private func decode<T: Decodable>(_ res: HTTPResponse) throws -> T {
        if T.self == EmptyResponse.self { return EmptyResponse() as! T }
        do {
            return try JSONDecoder().decode(T.self, from: res.body)
        } catch {
            throw APIError(status: res.status, .serverError, serverMessage: "Ungültige Antwort vom Server.")
        }
    }

    private func send<T: Decodable, B: Encodable>(_ method: String, _ path: String, query: [String: String?] = [:], body: B?, token: String? = nil) async throws -> T {
        let data = try body.map { try Self.encoder.encode($0) }
        return try decode(try await sendRaw(method, path, query: query, bodyData: data, token: token))
    }

    private func send<T: Decodable>(_ method: String, _ path: String, query: [String: String?] = [:], token: String? = nil) async throws -> T {
        try decode(try await sendRaw(method, path, query: query, bodyData: nil, token: token))
    }

    /// Geschützter Aufruf: Bearer-Token, bei `401 token_expired` genau ein Refresh + Wiederholung.
    private func authed<T: Decodable>(_ method: String, _ path: String, query: [String: String?] = [:], bodyData: Data? = nil) async throws -> T {
        let token = try await validAccessToken()
        do {
            return try decode(try await sendRaw(method, path, query: query, bodyData: bodyData, token: token))
        } catch let e as APIError where e.status == 401 && e.knownCode == .tokenExpired {
            // Falls ein paralleler Aufruf bereits erneuert hat, dessen Token nutzen.
            let fresh: String
            if let t = accessToken, t != token { fresh = t } else { fresh = try await refresh() }
            return try decode(try await sendRaw(method, path, query: query, bodyData: bodyData, token: fresh))
        } catch let e as APIError where e.status == 401 && e.knownCode == .invalidToken {
            try? await store.set(nil)
            dropAccessToken()
            if let onSessionExpired { await onSessionExpired() }
            throw e
        }
    }

    private func authed<T: Decodable, B: Encodable>(_ method: String, _ path: String, body: B) async throws -> T {
        try await authed(method, path, bodyData: try Self.encoder.encode(body))
    }

    // MARK: Öffentliche Endpunkte

    public func config() async throws -> ConfigResponse { try await send("GET", "config") }

    public func health() async throws -> Bool {
        struct H: Decodable { var status: String }
        let h: H = try await send("GET", "health")
        return h.status == "ok"
    }

    @discardableResult
    public func login(username: String, password: String, deviceName: String) async throws -> TokenResponse {
        let t: TokenResponse = try await send("POST", "auth/login", body: LoginRequest(username: username, password: password, deviceName: deviceName))
        try await accept(t)
        return t
    }

    @discardableResult
    public func oidcToken(_ req: OIDCTokenRequest) async throws -> TokenResponse {
        let t: TokenResponse = try await send("POST", "auth/oidc/token", body: req)
        try await accept(t)
        return t
    }

    // MARK: Geschützte Endpunkte

    /// Meldet ab (sperrt die Token-Familie) und verwirft lokale Tokens – auch bei Fehlern.
    public func logout() async {
        let refreshToken = try? await store.get()
        let body = refreshToken.map { ["refresh_token": $0] } ?? [:]
        do {
            if let token = accessToken {
                let _: EmptyResponse = try await send("POST", "auth/logout", body: body, token: token)
            } else if refreshToken != nil {
                let _: EmptyResponse = try await authed("POST", "auth/logout", body: body)
            }
        } catch {
            // Abmeldung lokal trotzdem durchführen
        }
        dropAccessToken()
        try? await store.set(nil)
    }

    public func me() async throws -> MeResponse { try await authed("GET", "me") }

    public func myPlan(from: ISODay, to: ISODay) async throws -> MyPlanResponse {
        try await authed("GET", "plan/my", query: ["from": from, "to": to])
    }

    public func myPlan(month: YearMonth) async throws -> MyPlanResponse {
        try await myPlan(from: month.first, to: month.last)
    }

    public func teamPlan(_ ym: YearMonth) async throws -> TeamPlanResponse {
        try await authed("GET", "plan/team", query: ["year": String(ym.year), "month": String(ym.month)])
    }

    public func assignment(date: ISODay, userID: Int? = nil) async throws -> AssignmentDetails {
        try await authed("GET", "assignments/\(date)", query: ["user_id": userID.map(String.init)])
    }

    public func myAbsences(year: Int) async throws -> MyAbsencesResponse {
        try await authed("GET", "absences/my", query: ["year": String(year)])
    }

    public func createAbsenceRequest(_ req: AbsenceRequestCreate) async throws -> AbsenceRequestCreated {
        try await authed("POST", "absences/requests", body: req)
    }

    public func holidays(year: Int) async throws -> HolidaysResponse {
        try await authed("GET", "holidays", query: ["year": String(year)])
    }

    public func notifications(limit: Int = 30) async throws -> NotificationsResponse {
        try await authed("GET", "notifications", query: ["limit": String(limit)])
    }

    public func markNotificationRead(_ id: Int) async throws {
        let _: EmptyResponse = try await authed("POST", "notifications/\(id)/read")
    }

    public func markAllNotificationsRead() async throws {
        let _: EmptyResponse = try await authed("POST", "notifications/read-all")
    }

    public func sync(since: String?) async throws -> SyncResponse {
        try await authed("GET", "sync", query: ["since": since])
    }

    public func eventsTicket() async throws -> EventsTicketResponse {
        try await authed("POST", "events/ticket")
    }

    public func registerDevice(_ reg: DeviceRegistration) async throws -> DeviceRegistered {
        try await authed("POST", "devices", body: reg)
    }

    public func unregisterDevice(_ id: Int) async throws {
        let _: EmptyResponse = try await authed("DELETE", "devices/\(id)")
    }

    /// Persönliches Kalender-Abo (iCal) abfragen.
    public func calendarSubscription() async throws -> CalendarSubscription {
        try await authed("GET", "calendar")
    }

    /// Kalender-Abo anlegen (bestehender Link bleibt erhalten).
    public func createCalendarSubscription() async throws -> CalendarSubscription {
        try await authed("POST", "calendar")
    }

    /// Ereignisstrom-URL aus einem Ticket (relativ zur API-Basis) inkl. `last_event_id`.
    public nonisolated func eventsURL(ticket: EventsTicketResponse, lastEventID: String?) -> URL {
        url(ticket.url, query: ["last_event_id": lastEventID])
    }
}

/// Platzhalter für Antworten ohne Inhalt (204).
public struct EmptyResponse: Decodable, Sendable {
    public init() {}
}

extension Array where Element == String {
    fileprivate func splitPair() -> (String, String?) {
        (first ?? "", count > 1 ? self[1] : nil)
    }
}

extension URL {
    /// Hängt einen relativen Pfad (z. B. `assignments/2026-11-02`) an die API-Basis an.
    fileprivate func appendingPathComponentsPreservingSlashes(_ path: String) -> URL {
        var u = self
        for part in path.split(separator: "/") where !part.isEmpty {
            u.appendPathComponent(String(part))
        }
        return u
    }
}

extension APIClient {
    /// API-Basis aus einer Server-Eingabe (`https://planquadro.firma.de` → `…/api/v1/`).
    public static func baseURL(forServer input: String) throws -> URL {
        try ServerAddress.normalize(input)
    }
}
