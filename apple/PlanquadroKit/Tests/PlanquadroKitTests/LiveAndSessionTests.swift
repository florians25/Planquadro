import XCTest
@testable import PlanquadroKit

final class SSEParserTests: XCTestCase {
    func testParsesApiDocStreamInArbitraryChunks() {
        let stream = "retry: 3000\n\nid: 1842\nevent: ready\ndata: {\"cursor\":\"1842\"}\n\nid: 1845\nevent: published\ndata: {\"type\":\"published\",\"published\":true,\"months\":[\"2026-11\"],\"cells\":[[12,\"2026-11-02\"]],\"self\":true,\"own\":false}\n\n: hb\n\nevent: bye\ndata: {}\n\n"
        let bytes = Array(stream.utf8)
        for chunkSize in [1, 3, 7, 64, bytes.count] {
            var p = SSEParser()
            var msgs: [SSEMessage] = []
            var i = 0
            while i < bytes.count {
                msgs += p.feed(Data(bytes[i..<min(bytes.count, i + chunkSize)]))
                i += chunkSize
            }
            XCTAssertEqual(msgs.map(\.event), ["ready", "published", "bye"], "Blockgröße \(chunkSize)")
            XCTAssertEqual(msgs[0].id, "1842")
            XCTAssertEqual(msgs[1].id, "1845")
            XCTAssertEqual(p.retry, 3000)
            XCTAssertEqual(p.lastEventID, "1845")
        }
    }

    func testCRLFMultilineDataAndIdOnlyLines() {
        var p = SSEParser()
        let m = p.feed(Data("data: a\r\ndata: b\r\n\r\nid: 99\n\ndata:c\rdata\r\r".utf8))
        XCTAssertEqual(m.map(\.data), ["a\nb", "c\n"])
        XCTAssertEqual(m.map(\.event), ["message", "message"])
        XCTAssertEqual(p.lastEventID, "99", "Zeilen nur mit id: rücken den Cursor vor")
    }

    func testUTF8SplitAcrossChunks() {
        var p = SSEParser()
        let bytes = Array("data: Größe\n\n".utf8)
        var out = p.feed(Data(bytes[0..<9]))
        out += p.feed(Data(bytes[9...]))
        XCTAssertEqual(out.first?.data, "Größe")
    }
}

/// Liefert vorgefertigte Streams je Verbindung.
final class ScriptedStream: EventStreamTransport, @unchecked Sendable {
    private let lock = NSLock()
    private var scripts: [[StreamChunk]]
    private(set) var requests: [HTTPRequest] = []

    init(_ scripts: [[StreamChunk]]) {
        self.scripts = scripts
    }

    func stream(_ request: HTTPRequest) -> AsyncThrowingStream<StreamChunk, Error> {
        lock.lock()
        requests.append(request)
        let script = scripts.isEmpty ? [StreamChunk.response(status: 500, headers: [:])] : scripts.removeFirst()
        lock.unlock()
        return AsyncThrowingStream { c in
            for s in script { c.yield(s) }
            c.finish()
        }
    }

    var urls: [String] {
        lock.lock()
        defer { lock.unlock() }
        return requests.map(\.url.absoluteString)
    }
}

actor EventLog {
    var events: [LiveEvent] = []
    var resets = 0
    var states: [LiveConnection.State] = []
    func add(_ e: LiveEvent) { events.append(e) }
    func reset() { resets += 1 }
    func state(_ s: LiveConnection.State) { states.append(s) }
}

final class LiveConnectionTests: XCTestCase {
    func sse(_ s: String) -> StreamChunk { .data(Data(s.utf8)) }

    func waitUntil(_ cond: @escaping () async -> Bool) async {
        for _ in 0..<400 {
            if await cond() { return }
            try? await Task.sleep(nanoseconds: 5_000_000)
        }
    }

    func testDeliversEventsReconnectsAfterByeWithLastEventIDAndHandlesReset() async {
        let ok = StreamChunk.response(status: 200, headers: ["content-type": "text/event-stream"])
        let stream = ScriptedStream([
            [ok, sse("id: 1842\nevent: ready\ndata: {\"cursor\":\"1842\"}\n\n"),
             sse("id: 1845\nevent: published\ndata: {\"type\":\"published\",\"published\":true,\"months\":[\"2026-11\"],\"cells\":null,\"self\":true,\"own\":false}\n\n"),
             sse(": hb\n\nid: 1846\n\nevent: bye\ndata: {}\n\n")],
            [ok, sse("event: ready\ndata: {}\n\nevent: reset\ndata: {}\n\nevent: bye\ndata: {}\n\n")],
        ])
        let log = EventLog()
        let tickets = Counter()
        let live = LiveConnection(ticket: {
            let n = tickets.next()
            return EventsTicketResponse(ticket: "t\(n)", expiresIn: 30, url: "events?ticket=t\(n)")
        }, makeURL: { t, last in
            var c = URLComponents(string: "https://pq.example/api/v1/" + t.url)!
            if let last { c.queryItems! += [URLQueryItem(name: "last_event_id", value: last)] }
            return c.url!
        }, transport: stream, handlers: .init(onEvent: { await log.add($0) }, onReset: { await log.reset() }, onState: { await log.state($0) }),
           backoff: [0, 0], sleep: { _ in })
        await live.start()
        await waitUntil { await log.resets == 1 }
        await live.stop()
        let events = await log.events
        XCTAssertEqual(events.count, 1)
        XCTAssertEqual(events.first?.type, .published)
        let v1 = await log.resets
        XCTAssertEqual(v1, 1)
        let urls = stream.urls
        XCTAssertEqual(urls.first, "https://pq.example/api/v1/events?ticket=t1")
        XCTAssertEqual(urls.dropFirst().first, "https://pq.example/api/v1/events?ticket=t2&last_event_id=1846", "neues Ticket, Cursor fortgesetzt")
        let v2 = await log.states.contains(.connected)
        XCTAssertTrue(v2)
    }

    func testServerDisabled204StopsWithUnavailable() async {
        let stream = ScriptedStream([[.response(status: 204, headers: [:])]])
        let log = EventLog()
        let live = LiveConnection(ticket: { EventsTicketResponse(ticket: "t", expiresIn: 30, url: "events?ticket=t") },
                                  makeURL: { _, _ in URL(string: "https://pq.example/api/v1/events")! }, transport: stream,
                                  handlers: .init(onEvent: { await log.add($0) }, onReset: {}, onState: { await log.state($0) }), sleep: { _ in })
        await live.start()
        await waitUntil { await live.state == .unavailable }
        let v3 = await live.state
        XCTAssertEqual(v3, .unavailable)
        XCTAssertEqual(stream.urls.count, 1)
    }

    func testGivesUpAfterBackoffAndTicketNotFoundDisables() async {
        let stream = ScriptedStream([])
        let live = LiveConnection(ticket: { EventsTicketResponse(ticket: "t", expiresIn: 30, url: "events?ticket=t") },
                                  makeURL: { _, _ in URL(string: "https://pq.example/api/v1/events")! }, transport: stream,
                                  handlers: .init(onEvent: { _ in }, onReset: {}), backoff: [0, 0, 0], sleep: { _ in })
        await live.start()
        await waitUntil { await live.state == .unavailable }
        XCTAssertEqual(stream.urls.count, 4, "1 Versuch + 3 Wiederholungen")

        let off = LiveConnection(ticket: { throw APIError(status: 404, .notFound) },
                                 makeURL: { _, _ in URL(string: "https://x")! }, transport: stream,
                                 handlers: .init(onEvent: { _ in }, onReset: {}), sleep: { _ in })
        await off.start()
        await waitUntil { await off.state == .unavailable }
        let v4 = await off.state
        XCTAssertEqual(v4, .unavailable)
    }
}

final class Counter: @unchecked Sendable {
    private let lock = NSLock()
    private var n = 0
    func next() -> Int {
        lock.lock()
        defer { lock.unlock() }
        n += 1
        return n
    }
}

final class ConfidentialityTests: XCTestCase {
    func testTeamCellDisplay() {
        let conf = TeamCell(kind: .absence, code: "A", color: "#ef4444", fg: "#ffffff", confidential: true)
        let d = Confidentiality.display(conf, isMe: false, userName: "Beispiel, Erika", date: "2026-11-02")
        XCTAssertEqual(d.code, "A")
        XCTAssertFalse(d.opensDetails)
        XCTAssertEqual(d.accessibilityLabel, "Beispiel, Erika, 02.11.2026: abwesend")
        // auch eine fälschlich mit Grund gelieferte, als vertraulich markierte Zelle bleibt „A“
        let leak = TeamCell(kind: .absence, code: "K", color: "#ef4444", fg: "#ffffff", confidential: true)
        XCTAssertEqual(Confidentiality.display(leak, isMe: false, userName: "x", date: "2026-11-02").code, "A")
        let asg = TeamCell(kind: .assignment, code: "K1", color: "#dc2626", fg: "#ffffff")
        XCTAssertTrue(Confidentiality.display(asg, isMe: false, userName: "x", date: "2026-11-02").opensDetails)
        let gz = TeamCell(kind: .absence, code: "GZ", color: "#0ea5e9", fg: "#ffffff")
        XCTAssertEqual(Confidentiality.display(gz, isMe: false, userName: "x", date: "2026-11-02").code, "GZ", "Gleitzeit ist nicht vertraulich")
    }

    func testForeignEntryAndPublicEntry() {
        let sick = PlanEntry(kind: .absence, type: "krank_au", code: "K", label: "Krank", color: "#ef4444", fg: "#fff", remarks: "Arzttermin")
        let foreign = Confidentiality.sanitizeForeignEntry(sick, isMe: false)
        XCTAssertEqual(foreign?.label, "Abwesend")
        XCTAssertNil(foreign?.remarks)
        XCTAssertNil(foreign?.type)
        XCTAssertEqual(Confidentiality.sanitizeForeignEntry(sick, isMe: true)?.remarks, "Arzttermin", "eigene Details bleiben")
        // Außerhalb der App: auch eigene Abwesenheit nur neutral, Einsatz ohne Bemerkung
        XCTAssertEqual(Confidentiality.publicEntry(sick), PublicEntry(kind: .absence, code: "A", label: "Abwesend", color: Confidentiality.neutralColor, fg: "#ffffff"))
        let asg = PlanEntry(kind: .assignment, areaID: 3, code: "K1", label: "Kunde Nord", color: "#facc15", fg: "#ffffff", remarks: "geheim")
        let pub = Confidentiality.publicEntry(asg)!
        XCTAssertEqual(pub.label, "Kunde Nord")
        XCTAssertEqual(pub.fg, "#111827", "unlesbares fg korrigiert")
        let encoded = String(decoding: try! JSONEncoder().encode(pub), as: UTF8.self)
        XCTAssertFalse(encoded.contains("geheim"))
    }

    func testDemoServerMasksOthersReasonsInTeamView() async throws {
        let server = DemoServer(options: .init(latency: 0))
        let api = APIClient(baseURL: DemoServer.baseURL, transport: server, tokenStore: SecureRefreshTokenStore(store: InMemorySecureStore()))
        try await api.login(username: "a", password: "demo", deviceName: "t")
        var sawConfidential = false
        for m in 1...12 {
            let t = try await api.teamPlan(YearMonth(year: 2026, month: m))
            for (key, cell) in t.cells where !key.hasPrefix("12_") && cell.kind == .absence {
                XCTAssertNotEqual(cell.code, "U", key)
                XCTAssertNotEqual(cell.code, "K", key)
                if cell.confidential { sawConfidential = true }
            }
        }
        XCTAssertTrue(sawConfidential)
    }

    func testSnapshotContainsOnlyPublicData() throws {
        let plan = MyPlanResponse(from: "2026-11-02", to: "2026-11-04", publishedAt: nil, days: [
            PlanDay(date: "2026-11-02", weekday: 1, entry: PlanEntry(kind: .assignment, areaID: 3, code: "K1", label: "Kunde Nord", color: "#dc2626", fg: "#fff", remarks: "Tor 3 Code 4711")),
            PlanDay(date: "2026-11-03", weekday: 2, entry: PlanEntry(kind: .absence, type: "krank", code: "K", label: "Krank", color: "#ef4444", fg: "#fff", remarks: "Grippe")),
            PlanDay(date: "2026-11-04", weekday: 3),
        ])
        let s = PlanSnapshot.make(plans: [plan], me: nil, tenantName: "T", branding: .planquadro, from: "2026-11-02", dayCount: 5, places: ["2026-11-02": "Köln Nord", "2026-11-03": "Praxis"], now: Date(timeIntervalSince1970: 1_800_000_000))
        let json = String(decoding: try JSONEncoder().encode(s), as: UTF8.self)
        for secret in ["4711", "Grippe", "Krank", "krank", "Praxis"] { XCTAssertFalse(json.contains(secret), secret) }
        XCTAssertEqual(s.days.count, 5)
        XCTAssertEqual(s.day("2026-11-02")?.place, "Köln Nord")
        XCTAssertEqual(s.spokenSummary(for: "2026-11-03", today: "2026-11-02"), "Morgen (Di, 03.11.) sind Sie abwesend.")
        XCTAssertEqual(s.spokenSummary(for: "2026-11-02", today: "2026-11-02"), "Heute (Mo, 02.11.): Kunde Nord (K1) in Köln Nord.")
        XCTAssertEqual(s.spokenSummary(for: "2026-11-04", today: "2026-11-02"), "Mittwoch, 04.11.2026 ist kein Einsatz geplant.")
        XCTAssertEqual(s.nextAssignment(from: "2026-11-03")?.date, nil)
        XCTAssertTrue(s.spokenSummary(for: "2026-12-24", today: "2026-11-02").contains("noch kein Plan"))

        let dir = FileManager.default.temporaryDirectory.appendingPathComponent("pq-snap-\(UUID().uuidString)")
        defer { try? FileManager.default.removeItem(at: dir) }
        let store = PlanSnapshotStore(directory: dir)
        try store.save(s)
        XCTAssertEqual(store.load(), s)
        store.remove()
        XCTAssertNil(store.load())
    }
}

/// Biometrie-Attrappe.
final class FakeBiometrics: BiometricAuthenticating, @unchecked Sendable {
    var available = true
    var succeed = true
    func availability() -> BiometryInfo { available ? BiometryInfo(available: true, label: "Face ID") : .unavailable }
    func authenticate(reason: String) async -> Bool { succeed }
}

/// Transport-Attrappe für Live (nie verbunden).
struct NoStream: HTTPTransport, EventStreamTransport {
    func send(_ request: HTTPRequest) async throws -> HTTPResponse { throw URLError(.notConnectedToInternet) }
    func stream(_ request: HTTPRequest) -> AsyncThrowingStream<StreamChunk, Error> { AsyncThrowingStream { $0.finish(throwing: URLError(.notConnectedToInternet)) } }
}

@MainActor
final class SessionControllerTests: XCTestCase {
    var dir: URL!

    override func setUp() async throws {
        dir = FileManager.default.temporaryDirectory.appendingPathComponent("pq-session-\(UUID().uuidString)")
    }

    override func tearDown() async throws {
        try? FileManager.default.removeItem(at: dir)
    }

    func makeEnv(secure: InMemorySecureStore = InMemorySecureStore(), prefs: PreferencesStore = InMemoryPreferencesStore(),
                 bio: FakeBiometrics = FakeBiometrics(), clock: TestClock = TestClock()) -> SessionEnvironment {
        SessionEnvironment(secureStore: secure, prefs: prefs, cacheDirectory: dir, biometrics: bio, deviceName: "Test", appVersion: "7.0.0",
                           transport: NoStream(), snapshotStore: PlanSnapshotStore(directory: dir.appendingPathComponent("group")),
                           makeCipher: { _ in PlainCacheCipher() }, now: { clock.now }, demoOptions: .init(latency: 0, today: "2026-11-02"))
    }

    func testFirstStartNeedsServerThenDemoLoginLoadsAndWritesSnapshot() async throws {
        let s = SessionController(env: makeEnv())
        await s.start()
        XCTAssertEqual(s.status, .needsServer)
        await s.startDemo()
        XCTAssertEqual(s.status, .needsLogin)
        XCTAssertEqual(s.tenantName, "Planquadro Demo GmbH")
        do {
            try await s.login(username: "gesperrt", password: "demo")
            XCTFail("erwartet Fehler")
        } catch {
            XCTAssertTrue(loginErrorMessage(error).contains("gesperrt"))
        }
        try await s.login(username: "mmuster", password: "demo")
        XCTAssertEqual(s.status, .ready)
        XCTAssertEqual(s.myID, 12)
        let plan = try await s.myPlan(YearMonth(year: 2026, month: 11))
        XCTAssertFalse(plan.fromCache)
        let snap = s.env.snapshotStore!.load()
        XCTAssertNotNil(snap, "Widget-Auszug geschrieben")
        XCTAssertEqual(snap?.days.first?.date, "2026-11-02")
        XCTAssertTrue(snap?.demo ?? false)
        let n = try await s.notifications()
        XCTAssertEqual(n.data.unread, 2)
        XCTAssertEqual(s.unreadCount, 2)
    }

    func testOfflineReadUsesCacheAndBlocksWrites() async throws {
        let s = SessionController(env: makeEnv())
        await s.startDemo()
        try await s.login(username: "a", password: "demo")
        _ = try await s.teamPlan(YearMonth(year: 2026, month: 11))
        await s.setOnline(false)
        let t = try await s.teamPlan(YearMonth(year: 2026, month: 11))
        XCTAssertTrue(t.fromCache)
        XCTAssertEqual(t.offlineReason, "Offline")
        do {
            _ = try await s.createAbsenceRequest(AbsenceRequestCreate(from: "2026-12-01", to: "2026-12-01", type: .flextime))
            XCTFail("offline darf nichts senden")
        } catch {
            XCTAssertEqual(APIError.wrap(error).knownCode, .offlineWrite)
        }
    }

    func testBiometricLockOnRestartAndAfterBackground() async throws {
        let secure = InMemorySecureStore()
        let prefs = InMemoryPreferencesStore()
        let bio = FakeBiometrics()
        let clock = TestClock()
        let s = SessionController(env: makeEnv(secure: secure, prefs: prefs, bio: bio, clock: clock))
        await s.startDemo()
        try await s.login(username: "a", password: "demo")
        bio.succeed = false
        let denied = await s.setBiometric(true)
        XCTAssertFalse(denied.ok)
        bio.succeed = true
        let r = await s.setBiometric(true)
        XCTAssertTrue(r.ok)
        XCTAssertTrue(s.prefs.biometric)

        // Neustart mit aktiver Biometrie: gesperrt, Refresh-Token wird erst nach Entsperren gelesen
        let s2 = SessionController(env: makeEnv(secure: secure, prefs: prefs, bio: bio, clock: clock))
        await s2.start()
        XCTAssertEqual(s2.status, .locked)
        bio.succeed = false
        let failed = await s2.unlock()
        XCTAssertFalse(failed)
        XCTAssertEqual(s2.status, .locked)
        bio.succeed = true
        let ok = await s2.unlock()
        XCTAssertTrue(ok)
        XCTAssertEqual(s2.status, .ready)
        XCTAssertEqual(s2.myID, 12)

        // > 5 min im Hintergrund → wieder gesperrt; kurz → nicht
        await s2.didEnterBackground()
        clock.advance(60)
        await s2.didBecomeActive()
        XCTAssertEqual(s2.status, .ready)
        await s2.didEnterBackground()
        clock.advance(6 * 60)
        await s2.didBecomeActive()
        XCTAssertEqual(s2.status, .locked)

        // Biometrie nicht mehr verfügbar → Passwort-Anmeldung
        bio.available = false
        _ = await s2.unlock()
        XCTAssertEqual(s2.status, .needsLogin)
        XCTAssertFalse(s2.prefs.biometric)
    }

    func testOIDCFlowInDemo() async throws {
        let env = makeEnv()
        var e = env
        e.demoOptions = .init(latency: 0, oidcEnabled: true, today: "2026-11-02")
        let s = SessionController(env: e)
        await s.startDemo()
        let url = try await s.beginOIDC()
        let items = URLComponents(url: url, resolvingAgainstBaseURL: false)!.queryItems!
        let state = items.first { $0.name == "state" }!.value!
        do {
            try await s.completeOIDC(callback: URL(string: "planquadro://auth?code=x&state=falsch")!)
            XCTFail("falscher state")
        } catch {}
        _ = try await s.beginOIDC()
        let state2 = URLComponents(url: try await s.beginOIDC(), resolvingAgainstBaseURL: false)!.queryItems!.first { $0.name == "state" }!.value!
        XCTAssertNotEqual(state, state2)
        try await s.completeOIDC(callback: URL(string: "planquadro://auth?code=abc&state=\(state2)")!)
        XCTAssertEqual(s.status, .ready)
    }

    func testDemoChangeSyncAndLogoutWipes() async throws {
        let s = SessionController(env: makeEnv())
        var signedOut = false
        s.onSignedOut = { signedOut = true }
        await s.startDemo()
        try await s.login(username: "a", password: "demo")
        _ = try await s.myPlan(YearMonth(year: 2026, month: 11))
        _ = await s.sync() // erster Sync merkt nur den Cursor
        let before = s.dataVersion
        let r = await s.simulateDemoChange()
        XCTAssertEqual(r?.changedDates, ["2026-11-03", "2026-11-04"])
        XCTAssertGreaterThan(s.dataVersion, before)
        XCTAssertEqual(s.unreadCount, 3)
        await s.registerPushToken("deadbeef")
        XCTAssertNotNil(s.prefs.deviceID)
        let devID = s.prefs.deviceID!
        let server = s.demoServer!
        XCTAssertEqual(server.registeredDevices[devID], "deadbeef")
        await s.logout()
        XCTAssertTrue(signedOut)
        XCTAssertEqual(s.status, .needsLogin)
        XCTAssertNil(server.registeredDevices[devID], "Gerät abgemeldet")
        XCTAssertNil(s.env.snapshotStore!.load(), "Widget-Auszug gelöscht")
        XCTAssertNil(s.prefs.deviceID)
        let secrets = await (s.env.secureStore as! InMemorySecureStore).snapshot
        XCTAssertTrue(secrets.isEmpty)
        await s.wipeLocal()
        XCTAssertEqual(s.status, .needsServer)
        XCTAssertFalse(s.prefs.demo)
    }

    func testSetServerValidatesInput() async {
        let s = SessionController(env: makeEnv())
        do {
            try await s.setServer("http://firma.de", tenant: "")
            XCTFail("http nicht erlaubt")
        } catch {
            XCTAssertTrue(error is ServerAddress.InvalidAddress)
        }
        do {
            try await s.setServer("https://pq.firma.de", tenant: "")
            XCTFail("offline")
        } catch {
            XCTAssertEqual(APIError.wrap(error).knownCode, .networkError)
        }
        XCTAssertEqual(s.status, .launching)
    }
}
