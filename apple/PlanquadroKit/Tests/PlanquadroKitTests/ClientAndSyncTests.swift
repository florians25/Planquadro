import XCTest
@testable import PlanquadroKit

/// Verstellbare Uhr für Token-Ablauf.
final class TestClock: @unchecked Sendable {
    private let lock = NSLock()
    private var t: Date

    init(_ start: Date = Date(timeIntervalSince1970: 1_800_000_000)) {
        t = start
    }

    var now: Date {
        lock.lock()
        defer { lock.unlock() }
        return t
    }

    func advance(_ s: TimeInterval) {
        lock.lock()
        t = t.addingTimeInterval(s)
        lock.unlock()
    }
}

/// Zählt Aufrufe und kann Antworten einschleusen.
final class RecordingTransport: HTTPTransport, @unchecked Sendable {
    let inner: HTTPTransport
    private let lock = NSLock()
    private(set) var paths: [String] = []
    var fail: ((HTTPRequest) -> Error?)?

    init(_ inner: HTTPTransport) {
        self.inner = inner
    }

    private func record(_ request: HTTPRequest) -> Error? {
        lock.lock()
        defer { lock.unlock() }
        paths.append("\(request.method) \(request.url.path)")
        return fail?(request)
    }

    func send(_ request: HTTPRequest) async throws -> HTTPResponse {
        if let f = record(request) { throw f }
        return try await inner.send(request)
    }

    func count(_ suffix: String) -> Int {
        lock.lock()
        defer { lock.unlock() }
        return paths.filter { $0.hasSuffix(suffix) }.count
    }
}

final class APIClientTests: XCTestCase {
    func makeClient(ttl: Int = 900, clock: TestClock = TestClock(), store: InMemorySecureStore = InMemorySecureStore(), tenant: String? = nil)
        -> (APIClient, DemoServer, RecordingTransport, InMemorySecureStore) {
        let server = DemoServer(options: .init(accessTTL: ttl, latency: 0, today: "2026-11-02"), now: { clock.now })
        let rec = RecordingTransport(server)
        let api = APIClient(baseURL: DemoServer.baseURL, tenant: tenant, transport: rec, tokenStore: SecureRefreshTokenStore(store: store), now: { clock.now })
        return (api, server, rec, store)
    }

    func testLoginStoresRefreshTokenAndSendsHeaders() async throws {
        let (api, server, _, store) = makeClient(tenant: "acme")
        let t = try await api.login(username: "mmuster", password: "demo", deviceName: "Test-iPhone")
        XCTAssertEqual(t.user.user.username, "mmuster")
        let rt = await store.get(SecureRefreshTokenStore.key)
        XCTAssertEqual(rt, t.refreshToken)
        let me = try await api.me()
        XCTAssertEqual(me.user.id, 12)
        let last = server.callLog.last!
        XCTAssertEqual(last.headers["X-PQ-Tenant"], "acme")
        XCTAssertEqual(last.headers["Authorization"], "Bearer \(t.accessToken)")
        XCTAssertEqual(last.headers["Accept"], "application/json")
    }

    func testLoginErrorCodes() async {
        let (api, _, _, _) = makeClient()
        for (user, pw, code) in [("x", "falsch", APIError.Code.invalidCredentials), ("gesperrt", "demo", .accountLocked),
                                 ("neu", "demo", .passwordChangeRequired), ("wartung", "demo", .maintenance)] {
            do {
                _ = try await api.login(username: user, password: pw, deviceName: "t")
                XCTFail("erwartet Fehler für \(user)")
            } catch {
                XCTAssertEqual(APIError.wrap(error).knownCode, code, user)
            }
        }
    }

    func testProactiveRefreshBeforeExpiryRotatesToken() async throws {
        let clock = TestClock()
        let (api, _, rec, store) = makeClient(ttl: 120, clock: clock)
        let t = try await api.login(username: "a", password: "demo", deviceName: "t")
        clock.advance(100) // innerhalb des 30-s-Sicherheitsabstands
        _ = try await api.me()
        XCTAssertEqual(rec.count("auth/refresh"), 1)
        let rt = await store.get(SecureRefreshTokenStore.key)
        XCTAssertNotEqual(rt, t.refreshToken, "Refresh-Token rotiert")
    }

    func testParallelRequestsTriggerExactlyOneRefresh() async throws {
        let clock = TestClock()
        let (api, _, rec, _) = makeClient(ttl: 60, clock: clock)
        try await api.login(username: "a", password: "demo", deviceName: "t")
        clock.advance(120) // abgelaufen
        try await withThrowingTaskGroup(of: Void.self) { g in
            for _ in 0..<8 { g.addTask { _ = try await api.me() } }
            try await g.waitForAll()
        }
        XCTAssertEqual(rec.count("auth/refresh"), 1, "Single-Flight: genau ein Refresh")
    }

    func testReactiveRefreshOnTokenExpired() async throws {
        let clock = TestClock()
        let server = DemoServer(options: .init(accessTTL: 900, latency: 0), now: { clock.now })
        // Client glaubt, das Token sei noch lange gültig (Uhr des Clients steht)
        let api = APIClient(baseURL: DemoServer.baseURL, transport: server, tokenStore: SecureRefreshTokenStore(store: InMemorySecureStore()), now: { Date(timeIntervalSince1970: 1_800_000_000) })
        try await api.login(username: "a", password: "demo", deviceName: "t")
        clock.advance(1000) // Server: abgelaufen → 401 token_expired → Refresh + Wiederholung
        let me = try await api.me()
        XCTAssertEqual(me.user.id, 12)
        XCTAssertEqual(server.callLog.filter { $0.path == "auth/refresh" }.count, 1)
    }

    func testReusedRefreshTokenLocksFamilyAndExpiresSession() async throws {
        let clock = TestClock()
        let store = InMemorySecureStore()
        let (api, _, _, _) = makeClient(ttl: 60, clock: clock, store: store)
        let t = try await api.login(username: "a", password: "demo", deviceName: "t")
        clock.advance(120)
        _ = try await api.me() // rotiert
        // Angreifer/alte Kopie verwendet das alte Refresh-Token erneut → Familie gesperrt
        await store.set(SecureRefreshTokenStore.key, t.refreshToken)
        clock.advance(120)
        let expired = Expectation()
        await api.setHandlers(onSessionExpired: { expired.fulfill() }, onTokens: nil)
        do {
            _ = try await api.me()
            XCTFail("erwartet Fehler")
        } catch {
            XCTAssertEqual(APIError.wrap(error).knownCode, .sessionExpired)
        }
        XCTAssertTrue(expired.done)
        let left = await store.get(SecureRefreshTokenStore.key)
        XCTAssertNil(left, "Refresh-Token lokal verworfen")
    }

    func testNetworkErrorDuringRefreshKeepsRefreshToken() async throws {
        let clock = TestClock()
        let (api, _, rec, store) = makeClient(ttl: 60, clock: clock)
        try await api.login(username: "a", password: "demo", deviceName: "t")
        clock.advance(120)
        rec.fail = { $0.url.path.hasSuffix("auth/refresh") ? URLError(.notConnectedToInternet) : nil }
        do {
            _ = try await api.me()
            XCTFail("erwartet Fehler")
        } catch {
            XCTAssertEqual(APIError.wrap(error).knownCode, .networkError)
        }
        let rt = await store.get(SecureRefreshTokenStore.key)
        XCTAssertNotNil(rt)
        rec.fail = nil
        _ = try await api.me()
    }

    func testLogoutRevokesFamily() async throws {
        let (api, _, _, store) = makeClient()
        let t = try await api.login(username: "a", password: "demo", deviceName: "t")
        await api.logout()
        let rt = await store.get(SecureRefreshTokenStore.key)
        XCTAssertNil(rt)
        let hasToken = await api.hasAccessToken
        XCTAssertFalse(hasToken)
        // Familie ist gesperrt: altes Refresh-Token funktioniert nicht mehr
        await store.set(SecureRefreshTokenStore.key, t.refreshToken)
        do {
            try await api.refresh()
            XCTFail("erwartet Fehler")
        } catch {
            XCTAssertEqual(APIError.wrap(error).status, 401)
        }
    }

    func testEndpointsAgainstDemoServer() async throws {
        let (api, server, _, _) = makeClient()
        try await api.login(username: "a", password: "demo", deviceName: "t")
        let plan = try await api.myPlan(month: YearMonth(year: 2026, month: 11))
        XCTAssertEqual(plan.days.count, 30)
        XCTAssertNil(plan.days.first { $0.date == "2026-11-01" }?.entry, "Sonntag/Feiertag frei")
        XCTAssertEqual(plan.days.first { $0.date == "2026-11-01" }?.holiday?.name, "Allerheiligen")
        let team = try await api.teamPlan(YearMonth(year: 2026, month: 11))
        XCTAssertEqual(team.users.count, 8)
        let det = try await api.assignment(date: "2026-11-02")
        XCTAssertEqual(det.date, "2026-11-02")
        let abs = try await api.myAbsences(year: 2026)
        XCTAssertEqual(abs.vacation.entitlement, 30)
        let created = try await api.createAbsenceRequest(AbsenceRequestCreate(from: "2026-12-21", to: "2026-12-27", type: .vacationFull, remarks: "Weihnachten"))
        XCTAssertEqual(created.created + created.skipped, 4, "Mo–Mi + Do, Wochenende und Feiertage übersprungen")
        let n = try await api.notifications()
        XCTAssertEqual(n.unread, 2)
        try await api.markNotificationRead(5)
        try await api.markAllNotificationsRead()
        let v1 = try await api.notifications().unread
        XCTAssertEqual(v1, 0)
        let dev = try await api.registerDevice(DeviceRegistration(token: "abcd", appVersion: "7.0.0"))
        XCTAssertEqual(server.registeredDevices[dev.id], "abcd")
        try await api.unregisterDevice(dev.id)
        XCTAssertNil(server.registeredDevices[dev.id])
        let cal = try await api.calendarSubscription()
        XCTAssertTrue(cal.enabled)
        let v2 = try await api.health()
        XCTAssertEqual(v2, true)
        XCTAssertEqual(server.callLog.last { $0.path.hasPrefix("assignments/") }?.path, "assignments/2026-11-02")
    }

    func testEventsURLFromTicket() {
        let api = APIClient(baseURL: URL(string: "https://pq.example/api/v1/")!, transport: DemoServer(), tokenStore: SecureRefreshTokenStore(store: InMemorySecureStore()))
        let u = api.eventsURL(ticket: EventsTicketResponse(ticket: "pqe.abc", expiresIn: 30, url: "events?ticket=pqe.abc&tenant=acme"), lastEventID: "1845")
        XCTAssertEqual(u.absoluteString, "https://pq.example/api/v1/events?ticket=pqe.abc&tenant=acme&last_event_id=1845")
        let v = api.eventsURL(ticket: EventsTicketResponse(ticket: "t", expiresIn: 30, url: "events?ticket=t"), lastEventID: nil)
        XCTAssertEqual(v.absoluteString, "https://pq.example/api/v1/events?ticket=t")
    }
}

/// Einfache, threadsichere Erwartung ohne XCTestExpectation (auch unter Linux synchron prüfbar).
final class Expectation: @unchecked Sendable {
    private let lock = NSLock()
    private var flag = false
    func fulfill() {
        lock.lock()
        flag = true
        lock.unlock()
    }

    var done: Bool {
        lock.lock()
        defer { lock.unlock() }
        return flag
    }
}

final class OfflineCacheTests: XCTestCase {
    var dir: URL!

    override func setUp() {
        dir = FileManager.default.temporaryDirectory.appendingPathComponent("pq-cache-\(UUID().uuidString)")
    }

    override func tearDown() {
        try? FileManager.default.removeItem(at: dir)
    }

    /// Prüfbarer „Schlüssel“: XOR + Prüfsumme über AAD (Vertauschung wird erkannt).
    struct XORCipher: CacheCipher {
        let k: UInt8
        func seal(_ p: Data, aad: Data) throws -> Data { Data(p.map { $0 ^ k }) + PQSHA256.hash(aad) }
        func open(_ c: Data, aad: Data) throws -> Data {
            guard c.count >= 32, c.suffix(32) == PQSHA256.hash(aad) else { throw CocoaError(.coderValueNotFound) }
            return Data(c.prefix(c.count - 32).map { $0 ^ k })
        }
    }

    func testPutGetKeysAndScopes() async throws {
        let c = OfflineCache(directory: dir, cipher: XORCipher(k: 0x5a))
        let a = c.scoped(OfflineCache.scopeID(apiBase: "https://a/api/v1/", tenant: nil, userID: 12))
        let b = c.scoped(OfflineCache.scopeID(apiBase: "https://a/api/v1/", tenant: nil, userID: 14))
        try await a.put(CacheKeys.plan("2026-11"), ["x": 1])
        try await a.put(CacheKeys.details("2026-11-02", 12), "d")
        try await b.put(CacheKeys.plan("2026-11"), ["x": 2])
        let got = await a.get(CacheKeys.plan("2026-11"), as: [String: Int].self)
        XCTAssertEqual(got?.data["x"], 1)
        let v3 = await a.keys()
        XCTAssertEqual(v3, ["det:2026-11-02:12", "plan:2026-11"])
        let v4 = await a.keys(prefix: "plan:")
        XCTAssertEqual(v4, ["plan:2026-11"])
        await a.clear()
        let v5 = await a.keys().isEmpty
        XCTAssertTrue(v5)
        let v6 = await b.get(CacheKeys.plan("2026-11"), as: [String: Int].self)?.data["x"]
        XCTAssertEqual(v6, 2, "andere Benutzer unberührt")
    }

    func testEncryptedOnDiskAndTamperingDropsRecord() async throws {
        let c = OfflineCache(directory: dir, cipher: XORCipher(k: 0x5a))
        let s = c.scoped("scope-1")
        try await s.put("plan:2026-11", "Kunde Nord GmbH")
        // Klartext darf nicht auf der Platte stehen
        let files = FileManager.default.enumerator(atPath: dir.path)!.compactMap { $0 as? String }.filter { $0.hasSuffix(".pqc") }
        XCTAssertEqual(files.count, 1)
        let raw = try Data(contentsOf: dir.appendingPathComponent(files[0]))
        XCTAssertFalse(String(decoding: raw, as: UTF8.self).contains("Kunde Nord"))
        // Datei in anderen Scope kopieren → AAD passt nicht → verworfen
        let other = c.scoped("scope-2")
        try await other.put("plan:2026-11", "x")
        let otherFile = FileManager.default.enumerator(atPath: dir.path)!.compactMap { $0 as? String }.first { $0.hasSuffix(".pqc") && $0 != files[0] }!
        try raw.write(to: dir.appendingPathComponent(otherFile))
        let v7 = await other.get("plan:2026-11", as: String.self)
        XCTAssertNil(v7)
        let v8 = await other.keys().isEmpty
        XCTAssertTrue(v8, "manipulierter Datensatz gelöscht")
    }

    func testScopeIDIsStableAndNotReversible() {
        let a = OfflineCache.scopeID(apiBase: "https://pq.firma.de/api/v1/", tenant: "acme", userID: 12)
        XCTAssertEqual(a, OfflineCache.scopeID(apiBase: "https://pq.firma.de/api/v1/", tenant: "acme", userID: 12))
        XCTAssertNotEqual(a, OfflineCache.scopeID(apiBase: "https://pq.firma.de/api/v1/", tenant: nil, userID: 12))
        XCTAssertFalse(a.contains("firma"))
        XCTAssertTrue(a.hasSuffix("-12"))
    }

    func testLoadCachedFallsBackOfflineAndInMaintenance() async throws {
        let c = OfflineCache(directory: dir, cipher: PlainCacheCipher()).scoped("s")
        let fresh = try await loadCached(c, key: "k", online: true) { "neu" }
        XCTAssertFalse(fresh.fromCache)
        let off = try await loadCached(c, key: "k", online: false) { "nie" }
        XCTAssertTrue(off.fromCache)
        XCTAssertEqual(off.data, "neu")
        XCTAssertEqual(off.offlineReason, "Offline")
        let maint = try await loadCached(c, key: "k", online: true) { () async throws -> String in throw APIError(status: 503, .maintenance) }
        XCTAssertEqual(maint.offlineReason, "Wartungsmodus")
        do {
            _ = try await loadCached(c, key: "k", online: true) { () async throws -> String in throw APIError(status: 403, .forbidden) }
            XCTFail("403 wird nicht aus dem Cache bedient")
        } catch {
            XCTAssertEqual(APIError.wrap(error).knownCode, .forbidden)
        }
        do {
            _ = try await loadCached(c, key: "fehlt", online: false) { "x" }
            XCTFail("ohne Cache: Fehler")
        } catch {
            XCTAssertEqual(APIError.wrap(error).knownCode, .networkError)
        }
        XCTAssertThrowsError(try requireOnline(false))
    }
}

final class SyncTests: XCTestCase {
    func testSyncPlan() {
        let keys = ["plan:2026-11", "plan:2026-12", "team:2026-11", "abs:2026", "abs:2025", "det:2026-11-02:12", "det:2026-11-05:12", "me", "meta:cursor"]
        let p = SyncPlan.make(SyncResponse(cursor: "2", changedDates: ["2026-11-02", "2026-11-03"], fullResync: false), cachedKeys: keys)
        XCTAssertEqual(p.planMonths, ["2026-11"])
        XCTAssertEqual(p.teamMonths, ["2026-11"])
        XCTAssertEqual(p.absenceYears, [2026])
        XCTAssertEqual(p.dropKeys, ["det:2026-11-02:12"])
        XCTAssertTrue(SyncPlan.make(SyncResponse(cursor: "2", changedDates: [], fullResync: true), cachedKeys: keys).fullResync)
    }

    func testLivePlan() {
        let keys = ["team:2026-11", "abs:2026", "plan:2026-11"]
        let other = LiveEvent(type: .published, published: true, months: ["2026-11"], cells: [.init(userID: 14, date: "2026-11-02")], selfAffected: false)
        XCTAssertEqual(LivePlan.make(other, myID: 12, cachedKeys: keys), LivePlan(sync: false, teamMonths: ["2026-11"], absenceYears: []))
        let mine = LiveEvent(type: .published, published: true, months: ["2026-11"], cells: [.init(userID: 12, date: "2026-11-02")], selfAffected: true)
        XCTAssertEqual(LivePlan.make(mine, myID: 12, cachedKeys: keys), LivePlan(sync: true, teamMonths: ["2026-11"], absenceYears: [2026]))
        let req = LiveEvent(type: .requestChanged, published: false, months: ["2026-12"], cells: [.init(userID: 12, date: "2026-12-23")], selfAffected: true)
        XCTAssertEqual(LivePlan.make(req, myID: 12, cachedKeys: keys), LivePlan(sync: false, teamMonths: [], absenceYears: [2026]))
        // Entwurfsänderung (nicht veröffentlicht) eines anderen: nichts
        let draft = LiveEvent(type: .cellsChanged, published: false, months: ["2026-11"], cells: nil, selfAffected: nil)
        XCTAssertEqual(LivePlan.make(draft, myID: 12, cachedKeys: keys), LivePlan(sync: false, teamMonths: [], absenceYears: []))
        // nicht gecachte Monate werden nicht geladen
        let uncached = LiveEvent(type: .published, published: true, months: ["2027-01"], cells: nil, selfAffected: false)
        XCTAssertEqual(LivePlan.make(uncached, myID: 12, cachedKeys: keys).teamMonths, [])
    }

    func testSyncEngineAgainstDemoServer() async throws {
        let dir = FileManager.default.temporaryDirectory.appendingPathComponent("pq-sync-\(UUID().uuidString)")
        defer { try? FileManager.default.removeItem(at: dir) }
        let server = DemoServer(options: .init(latency: 0))
        let api = APIClient(baseURL: DemoServer.baseURL, transport: server, tokenStore: SecureRefreshTokenStore(store: InMemorySecureStore()))
        try await api.login(username: "a", password: "demo", deviceName: "t")
        let cache = OfflineCache(directory: dir, cipher: PlainCacheCipher()).scoped("s")
        let engine = SyncEngine(api: api, cache: cache)
        let first = try await engine.run()
        XCTAssertTrue(first.initial)
        try await cache.put(CacheKeys.plan("2026-11"), try await api.myPlan(month: YearMonth(year: 2026, month: 11)))
        try await cache.put(CacheKeys.details("2026-11-03", 12), try await api.assignment(date: "2026-11-03"))
        server.publishChange(["2026-11-03"])
        let r = try await engine.run()
        XCTAssertEqual(r.changedDates, ["2026-11-03"])
        XCTAssertEqual(r.refreshed, ["plan:2026-11"])
        let v9 = await cache.get(CacheKeys.details("2026-11-03", 12), as: AssignmentDetails.self)
        XCTAssertNil(v9, "Details des Tages verworfen")
        let v10 = await cache.get(CacheKeys.cursor, as: String.self)?.data
        XCTAssertEqual(v10, "1843")
        let none = try await engine.run()
        XCTAssertTrue(none.refreshed.isEmpty)
    }
}
