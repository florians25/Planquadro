import Foundation
import Observation

/// Biometrische Entsperrung (Face ID / Touch ID) – Umsetzung in der App (LocalAuthentication).
public protocol BiometricAuthenticating: Sendable {
    func availability() -> BiometryInfo
    func authenticate(reason: String) async -> Bool
}

public struct BiometryInfo: Hashable, Sendable {
    public var available: Bool
    /// „Face ID“, „Touch ID“, „Optic ID“ oder „Biometrie“
    public var label: String
    /// Grund, warum nicht verfügbar
    public var reason: String?

    public init(available: Bool, label: String, reason: String? = nil) {
        self.available = available
        self.label = label
        self.reason = reason
    }

    public static let unavailable = BiometryInfo(available: false, label: "Biometrie", reason: "Auf diesem Gerät ist keine Biometrie verfügbar.")
}

public struct NoBiometrics: BiometricAuthenticating {
    public init() {}
    public func availability() -> BiometryInfo { .unavailable }
    public func authenticate(reason: String) async -> Bool { false }
}

/// Plattformdienste, die die App bereitstellt (in Tests ersetzbar).
public struct SessionEnvironment: Sendable {
    public var secureStore: SecureStore
    public var prefs: PreferencesStore
    public var cacheDirectory: URL
    public var biometrics: BiometricAuthenticating
    public var deviceName: String
    public var appVersion: String
    /// Transport für echte Server (Standard: URLSession)
    public var transport: HTTPTransport & EventStreamTransport
    /// Auszug für Widgets/Siri (App-Group-Container); nil = keiner
    public var snapshotStore: PlanSnapshotStore?
    /// Cache-Verschlüsselung aus Schlüsselmaterial
    public var makeCipher: @Sendable (Data) -> CacheCipher
    public var now: @Sendable () -> Date
    public var demoOptions: DemoServer.Options

    public init(secureStore: SecureStore, prefs: PreferencesStore, cacheDirectory: URL, biometrics: BiometricAuthenticating = NoBiometrics(),
                deviceName: String = "iPhone", appVersion: String = "1.0", transport: HTTPTransport & EventStreamTransport = URLSessionTransport(),
                snapshotStore: PlanSnapshotStore? = nil, makeCipher: @escaping @Sendable (Data) -> CacheCipher = SessionEnvironment.defaultCipher,
                now: @escaping @Sendable () -> Date = { Date() }, demoOptions: DemoServer.Options = DemoServer.Options()) {
        self.secureStore = secureStore
        self.prefs = prefs
        self.cacheDirectory = cacheDirectory
        self.biometrics = biometrics
        self.deviceName = deviceName
        self.appVersion = appVersion
        self.transport = transport
        self.snapshotStore = snapshotStore
        self.makeCipher = makeCipher
        self.now = now
        self.demoOptions = demoOptions
    }

    @Sendable public static func defaultCipher(_ key: Data) -> CacheCipher {
        #if canImport(CryptoKit)
        return AESGCMCacheCipher(keyData: key)
        #else
        return PlainCacheCipher()
        #endif
    }
}

/// Zustand und Abläufe der App: Server-Einrichtung, Anmeldung (Passwort/OIDC),
/// Token-Lebenszyklus, biometrische Sperre, Offline-Lesen, Sync, Live-Updates,
/// Push-Registrierung, Abmelden und Löschen lokaler Daten
/// (Gegenstück zu `mobile/src/store/session.ts`).
@MainActor
@Observable
public final class SessionController {
    public enum Status: Equatable, Sendable {
        case launching
        case needsServer
        case needsLogin
        case locked
        case ready
        case unavailable(String)
    }

    public static let refreshTokenKey = SecureRefreshTokenStore.key
    public static let cacheKeyKey = "cache_key"
    /// Nach so langer Zeit im Hintergrund ist bei aktivierter Biometrie erneut zu entsperren.
    public static let lockAfter: TimeInterval = 5 * 60

    public private(set) var status: Status = .launching
    public private(set) var prefs: AppPrefs
    public private(set) var config: ConfigResponse?
    public private(set) var me: MeResponse?
    public private(set) var online = true
    /// Hinweis für den Login-Bildschirm (z. B. „Sitzung abgelaufen“)
    public var loginMessage: String?
    public private(set) var liveState: LiveConnection.State = .stopped
    /// Zähler, der bei jeder Datenänderung durch Sync/Live steigt (Ansichten laden neu)
    public private(set) var dataVersion = 0
    /// zuletzt durch Sync/Live erneuerte Cache-Schlüssel
    public private(set) var lastRefreshedKeys: [String] = []
    public private(set) var unreadCount = 0
    public private(set) var lastSync: SyncResult?

    @ObservationIgnored public let env: SessionEnvironment
    @ObservationIgnored public private(set) var api: APIClient?
    @ObservationIgnored public private(set) var cache: ScopedCache?
    @ObservationIgnored public private(set) var demoServer: DemoServer?
    @ObservationIgnored private var offline: OfflineCache?
    @ObservationIgnored private var syncEngine: SyncEngine?
    @ObservationIgnored private var live: LiveConnection?
    @ObservationIgnored fileprivate var unlocked = false
    @ObservationIgnored private var backgroundSince: Date?
    @ObservationIgnored private var pkce: PKCE.Session?
    @ObservationIgnored private var pendingPushToken: String?
    /// Wird nach jedem neuen Widget-Auszug aufgerufen (App: WidgetCenter neu laden).
    @ObservationIgnored public var onSnapshotUpdated: (@MainActor (PlanSnapshot?) -> Void)?
    /// Wird beim Abmelden aufgerufen (App: Spotlight leeren, Live-Aktivitäten beenden …).
    @ObservationIgnored public var onSignedOut: (@MainActor () -> Void)?

    public init(env: SessionEnvironment) {
        self.env = env
        prefs = env.prefs.load()
    }

    public var isDemo: Bool { prefs.demo }
    public var isReady: Bool { status == .ready }

    /// Name und Farben des Mandanten (Planquadro-Standard ohne Angabe).
    public var tenantName: String {
        me?.tenant.name ?? config?.tenant.name ?? prefs.branding?.name ?? "Planquadro"
    }

    public var branding: Branding {
        if let b = me?.tenant.branding ?? config?.tenant.branding { return b }
        if let s = prefs.branding { return Branding(primary: s.primary, accent: s.accent, logoURL: s.logoURL) }
        return .planquadro
    }

    public var serverDisplay: String {
        if isDemo { return "Demo-Modus" }
        guard let s = prefs.serverURL, let u = URL(string: s) else { return "" }
        return ServerAddress.display(u)
    }

    public var myID: Int? { me?.user.id }

    private func savePrefs() { env.prefs.save(prefs) }

    // MARK: Schlüssel und Cache

    private func cacheCipher() async throws -> CacheCipher {
        if isDemo { return PlainCacheCipher() }
        if let b64 = try await env.secureStore.get(Self.cacheKeyKey), let data = Data(base64Encoded: b64) {
            return env.makeCipher(data)
        }
        let key = PKCE.randomBytes(32)
        try await env.secureStore.set(Self.cacheKeyKey, key.base64EncodedString())
        return env.makeCipher(key)
    }

    private var apiBaseString: String { isDemo ? DemoServer.baseURL.absoluteString : (prefs.serverURL ?? "") }

    private func openCache(userID: Int) async {
        do {
            let dir = env.cacheDirectory.appendingPathComponent(isDemo ? "demo" : "data", isDirectory: true)
            let oc = OfflineCache(directory: dir, cipher: try await cacheCipher(), now: env.now)
            offline = oc
            let scoped = oc.scoped(OfflineCache.scopeID(apiBase: apiBaseString, tenant: isDemo ? nil : prefs.tenant, userID: userID))
            cache = scoped
            if let api { syncEngine = SyncEngine(api: api, cache: scoped) }
        } catch {
            cache = nil
            syncEngine = nil
        }
    }

    private func clearAllCaches() async {
        await offline?.clearAll()
        try? FileManager.default.removeItem(at: env.cacheDirectory.appendingPathComponent("data", isDirectory: true))
        try? FileManager.default.removeItem(at: env.cacheDirectory.appendingPathComponent("demo", isDirectory: true))
        offline = nil
        cache = nil
        syncEngine = nil
    }

    // MARK: Client

    private func buildClient() {
        let transport: HTTPTransport
        let base: URL
        if isDemo {
            let d = demoServer ?? DemoServer(options: env.demoOptions, now: env.now)
            demoServer = d
            transport = d
            base = DemoServer.baseURL
        } else {
            guard let s = prefs.serverURL, let u = URL(string: s) else {
                api = nil
                return
            }
            transport = env.transport
            base = u
        }
        let ref = WeakSession(self)
        let store: RefreshTokenStore = GatedRefreshTokenStore(inner: SecureRefreshTokenStore(store: env.secureStore), isUnlocked: {
            await ref.isUnlocked()
        })
        api = APIClient(baseURL: base, tenant: isDemo ? nil : prefs.tenant, transport: transport, tokenStore: store, now: env.now,
                        onSessionExpired: { await ref.sessionExpired() },
                        onTokens: { t in await ref.tokens(t) })
    }

    // MARK: Start

    /// Ermittelt beim App-Start den Zustand. `forceDemo` z. B. über `-PQDemo YES`.
    public func start(forceDemo: Bool = false) async {
        prefs = env.prefs.load()
        if forceDemo && !prefs.demo {
            await resetForNewServer()
            prefs.demo = true
            savePrefs()
        }
        if prefs.serverURL == nil && !prefs.demo {
            status = .needsServer
            return
        }
        buildClient()
        // Bei aktiver Biometrie wird das Refresh-Token vor der Prüfung nicht einmal gelesen
        if prefs.biometric && prefs.lastUserID != nil {
            status = .locked
            return
        }
        let hasToken = ((try? await env.secureStore.get(Self.refreshTokenKey)) ?? nil) != nil
        if !hasToken {
            status = .needsLogin
            Task { try? await self.loadConfig() }
            return
        }
        await afterUnlock()
    }

    private func afterUnlock() async {
        unlocked = true
        if let uid = prefs.lastUserID { await openCache(userID: uid) }
        guard let api else {
            status = .needsServer
            return
        }
        do {
            me = try await api.me()
            await enterReady()
        } catch {
            let e = APIError.wrap(error)
            if e.allowsCachedRead {
                if let cached = await cache?.get(CacheKeys.me, as: MeResponse.self) {
                    me = cached.data
                    if e.isConnectivity { online = false }
                    status = .ready
                    return
                }
                status = .unavailable(e.knownCode == .maintenance ? e.userMessage : "Keine Verbindung zum Server und noch keine Offline-Daten vorhanden.")
                return
            }
            await toLogin(e.requiresLogin || e.status == 401 ? "Bitte melden Sie sich erneut an." : e.userMessage)
        }
    }

    private func enterReady() async {
        guard let me else { return }
        if prefs.lastUserID != me.user.id {
            prefs.lastUserID = me.user.id
        }
        prefs.branding = .init(name: me.tenant.name, primary: me.tenant.branding.primary, accent: me.tenant.branding.accent, logoURL: me.tenant.branding.logoURL)
        savePrefs()
        await openCache(userID: me.user.id)
        try? await cache?.put(CacheKeys.me, me)
        status = .ready
        if let t = pendingPushToken { await registerPushToken(t) }
    }

    /// Erneuter Versuch aus dem Status `unavailable`.
    public func retry() async {
        await afterUnlock()
    }

    // MARK: Server

    @discardableResult
    public func loadConfig() async throws -> ConfigResponse {
        if api == nil { buildClient() }
        guard let api else { throw APIError(.notConfigured) }
        let c = try await api.config()
        config = c
        prefs.branding = .init(name: c.tenant.name, primary: c.tenant.branding.primary, accent: c.tenant.branding.accent, logoURL: c.tenant.branding.logoURL)
        savePrefs()
        return c
    }

    /// Prüft die Server-Adresse (lädt `GET config`) und übernimmt sie erst bei Erfolg.
    /// Ein Serverwechsel verwirft alle lokalen Daten des alten Servers.
    @discardableResult
    public func setServer(_ urlInput: String, tenant tenantInput: String) async throws -> ConfigResponse {
        let url = try ServerAddress.normalize(urlInput)
        let tenant = try ServerAddress.normalizeTenant(tenantInput)
        let probe = APIClient(baseURL: url, tenant: tenant, transport: env.transport, tokenStore: SecureRefreshTokenStore(store: InMemorySecureStore()))
        let c: ConfigResponse
        do {
            c = try await probe.config()
        } catch {
            let e = APIError.wrap(error)
            if e.knownCode == .notFound { throw APIError(status: 404, .notFound, serverMessage: "Unter dieser Adresse wurde keine Planquadro-API (v1) gefunden.") }
            throw e
        }
        guard c.apiVersion == 1 else { throw APIError(.serverError, serverMessage: "Nicht unterstützte API-Version \(c.apiVersion).") }
        if url.absoluteString != prefs.serverURL || tenant != prefs.tenant || prefs.demo { await resetForNewServer() }
        prefs.serverURL = url.absoluteString
        prefs.tenant = tenant
        prefs.demo = false
        savePrefs()
        buildClient()
        config = c
        status = .needsLogin
        return c
    }

    public func startDemo() async {
        await resetForNewServer()
        prefs.demo = true
        savePrefs()
        buildClient()
        _ = try? await loadConfig()
        status = .needsLogin
    }

    /// Zurück zur Server-Einrichtung (behält nichts vom alten Server).
    public func changeServer() async {
        await logout()
        await resetForNewServer()
        prefs.serverURL = nil
        prefs.tenant = nil
        prefs.demo = false
        prefs.branding = nil
        savePrefs()
        api = nil
        config = nil
        status = .needsServer
    }

    private func resetForNewServer() async {
        await stopLive()
        await clearAllCaches()
        try? await env.secureStore.remove(Self.refreshTokenKey)
        prefs.lastUserID = nil
        prefs.deviceID = nil
        prefs.biometric = false
        me = nil
        demoServer = nil
        env.snapshotStore?.remove()
    }

    // MARK: Anmeldung

    public func login(username: String, password: String) async throws {
        if api == nil { buildClient() }
        guard let api else { throw APIError(.notConfigured) }
        unlocked = true
        let t = try await api.login(username: username.trimmingCharacters(in: .whitespacesAndNewlines), password: password, deviceName: env.deviceName)
        await finishLogin(t)
    }

    private func finishLogin(_ t: TokenResponse) async {
        // Benutzerwechsel auf demselben Gerät: Cache des Vorgängers verwerfen
        if let last = prefs.lastUserID, last != t.user.user.id { await clearAllCaches() }
        me = t.user
        loginMessage = nil
        await enterReady()
    }

    /// Startet die Anmeldung per Firmenkonto: liefert die Autorisierungs-URL (für
    /// ASWebAuthenticationSession) und merkt sich PKCE-Verifier, state und nonce.
    public func beginOIDC() async throws -> URL {
        let cfg: ConfigResponse
        if let c = config { cfg = c } else { cfg = try await loadConfig() }
        guard cfg.oidcAvailable, let authorize = cfg.auth.oidc.authorizeURL else { throw APIError(.moduleDisabled) }
        let req = try PKCE.authorizeRequest(authorizeURL: authorize, now: env.now())
        pkce = req.session
        return req.url
    }

    /// Verarbeitet den Rücksprung `planquadro://auth?code=…&state=…`.
    public func completeOIDC(callback: URL) async throws {
        let session = pkce
        pkce = nil
        let req = try PKCE.tokenRequest(callback: callback, session: session, deviceName: env.deviceName, now: env.now())
        if api == nil { buildClient() }
        guard let api else { throw APIError(.notConfigured) }
        unlocked = true
        let t = try await api.oidcToken(req)
        await finishLogin(t)
    }

    public func cancelOIDC() { pkce = nil }

    private func toLogin(_ message: String) async {
        await stopLive()
        await api?.dropAccessToken()
        try? await env.secureStore.remove(Self.refreshTokenKey)
        me = nil
        loginMessage = message
        status = .needsLogin
    }

    // MARK: Biometrie / Sperre

    public var biometryInfo: BiometryInfo { env.biometrics.availability() }

    @discardableResult
    public func unlock() async -> Bool {
        let info = env.biometrics.availability()
        guard info.available else {
            // Biometrie nicht (mehr) verfügbar: zurück zur Passwort-Anmeldung
            prefs.biometric = false
            savePrefs()
            await toLogin("Biometrie ist auf diesem Gerät nicht verfügbar. Bitte melden Sie sich mit Ihrem Passwort an.")
            return false
        }
        guard await env.biometrics.authenticate(reason: "Zum Entsperren von Planquadro") else { return false }
        await afterUnlock()
        return true
    }

    public func setBiometric(_ enabled: Bool) async -> (ok: Bool, message: String) {
        if !enabled {
            prefs.biometric = false
            savePrefs()
            return (true, "Entsperren mit Biometrie deaktiviert.")
        }
        let info = env.biometrics.availability()
        guard info.available else { return (false, info.reason ?? "Auf diesem Gerät ist keine Biometrie verfügbar.") }
        guard await env.biometrics.authenticate(reason: "\(info.label) für Planquadro aktivieren") else { return (false, "Bestätigung abgebrochen.") }
        prefs.biometric = true
        savePrefs()
        return (true, "Entsperren mit \(info.label) aktiviert.")
    }

    /// App geht in den Hintergrund.
    public func didEnterBackground() async {
        backgroundSince = env.now()
        await stopLive()
    }

    /// App kommt in den Vordergrund: ggf. sperren, sonst Sync und Live-Verbindung.
    public func didBecomeActive() async {
        let since = backgroundSince
        backgroundSince = nil
        if status == .ready, prefs.biometric, let since, env.now().timeIntervalSince(since) > Self.lockAfter {
            await api?.dropAccessToken()
            unlocked = false
            status = .locked
            return
        }
        guard status == .ready else { return }
        _ = await sync()
        startLive()
    }

    // MARK: Netzwerk

    public func setOnline(_ value: Bool) async {
        let was = online
        online = value
        guard status == .ready else { return }
        if value && !was {
            _ = await sync()
            await live?.reset()
            startLive()
        } else if !value && was {
            await stopLive()
        }
    }

    // MARK: Laden

    /// Lädt mit Offline-Fallback (verschlüsselter Cache).
    public func load<T: Codable & Sendable>(_ key: String, fetch: @escaping @Sendable (APIClient) async throws -> T) async throws -> Loaded<T> {
        guard let api else { throw APIError(.notConfigured) }
        let r = try await loadCached(cache, key: key, online: online, now: env.now()) { try await fetch(api) }
        if r.fromCache == false, key == CacheKeys.notifications, let n = r.data as? NotificationsResponse { unreadCount = n.unread }
        return r
    }

    public func myPlan(_ ym: YearMonth) async throws -> Loaded<MyPlanResponse> {
        let r = try await load(CacheKeys.plan(ym.key)) { try await $0.myPlan(month: ym) }
        if ym == YearMonth(key: today) { await updateSnapshot(current: r.data) }
        return r
    }

    public func teamPlan(_ ym: YearMonth) async throws -> Loaded<TeamPlanResponse> {
        try await load(CacheKeys.team(ym.key)) { try await $0.teamPlan(ym) }
    }

    public func details(date: ISODay, userID: Int?) async throws -> Loaded<AssignmentDetails> {
        let uid = userID ?? myID ?? 0
        let own = uid == myID
        var r = try await load(CacheKeys.details(date, uid)) { try await $0.assignment(date: date, userID: own ? nil : uid) }
        r.data.entry = Confidentiality.sanitizeForeignEntry(r.data.entry, isMe: own)
        return r
    }

    public func absences(year: Int) async throws -> Loaded<MyAbsencesResponse> {
        try await load(CacheKeys.absences(year)) { try await $0.myAbsences(year: year) }
    }

    public func notifications() async throws -> Loaded<NotificationsResponse> {
        let r = try await load(CacheKeys.notifications) { try await $0.notifications(limit: 30) }
        unreadCount = r.data.unread
        return r
    }

    public func calendarSubscription(create: Bool) async throws -> CalendarSubscription {
        guard let api else { throw APIError(.notConfigured) }
        if create {
            try requireOnline(online)
            return try await api.createCalendarSubscription()
        }
        return try await load(CacheKeys.calendar) { try await $0.calendarSubscription() }.data
    }

    // MARK: Schreiben (nur online)

    public func createAbsenceRequest(_ req: AbsenceRequestCreate) async throws -> AbsenceRequestCreated {
        guard let api else { throw APIError(.notConfigured) }
        try requireOnline(online)
        let r = try await api.createAbsenceRequest(req)
        if let y = Int(req.from.prefix(4)) { _ = try? await absences(year: y) }
        bump([CacheKeys.absences(Int(req.from.prefix(4)) ?? 0)])
        return r
    }

    public func markNotificationRead(_ id: Int) async throws {
        guard let api else { throw APIError(.notConfigured) }
        try requireOnline(online)
        try await api.markNotificationRead(id)
        unreadCount = max(0, unreadCount - 1)
    }

    public func markAllNotificationsRead() async throws {
        guard let api else { throw APIError(.notConfigured) }
        try requireOnline(online)
        try await api.markAllNotificationsRead()
        unreadCount = 0
    }

    // MARK: Sync & Live

    private func bump(_ keys: [String]) {
        lastRefreshedKeys = keys
        dataVersion += 1
    }

    /// `GET sync` → betroffene, bereits gecachte Monate neu laden.
    @discardableResult
    public func sync() async -> SyncResult? {
        guard status == .ready, online, let engine = syncEngine else { return nil }
        do {
            let r = try await engine.run()
            lastSync = r
            if !r.refreshed.isEmpty || r.fullResync { bump(r.refreshed) }
            await updateSnapshot()
            return r
        } catch {
            if APIError.wrap(error).isConnectivity { online = false }
            return nil
        }
    }

    /// Startet die Live-Verbindung (nur im Vordergrund, nicht im Demo-Modus).
    public func startLive() {
        guard status == .ready, online, !isDemo, let api else { return }
        if live == nil {
            let ref = WeakSession(self)
            live = LiveConnection(api: api, transport: env.transport, handlers: .init(
                onEvent: { ev in await ref.live(ev) },
                onReset: { await ref.liveReset() },
                onState: { st in await ref.liveState(st) }
            ))
        }
        let l = live!
        Task { await l.start() }
    }

    public func stopLive() async {
        await live?.stop()
        live = nil
        liveState = .stopped
    }

    fileprivate func expire() async {
        await toLogin("Ihre Sitzung ist abgelaufen. Bitte melden Sie sich erneut an.")
    }

    fileprivate func acceptMe(_ m: MeResponse) { me = m }
    fileprivate func setLiveState(_ st: LiveConnection.State) { liveState = st }

    fileprivate func handleLive(_ ev: LiveEvent) async {
        guard status == .ready, let engine = syncEngine, let myID else { return }
        if let keys = try? await engine.apply(ev, myID: myID), !keys.isEmpty {
            bump(keys)
            await updateSnapshot()
        }
        if ev.type == .requestChanged || ev.published { _ = try? await notifications() }
    }

    /// Push `plan_changed` (auch im Hintergrund): Sync ausführen.
    public func handlePlanChangedPush(_ push: PlanChangedPush) async -> Bool {
        guard status == .ready else { return false }
        return await sync() != nil
    }

    // MARK: Push-Registrierung

    /// APNs-Geräte-Token (hex) bei der API anmelden.
    public func registerPushToken(_ hex: String) async {
        pendingPushToken = hex
        guard status == .ready, !isDemo || demoServer != nil, let api else { return }
        do {
            let r = try await api.registerDevice(DeviceRegistration(token: hex, appVersion: env.appVersion))
            prefs.deviceID = r.id
            savePrefs()
            pendingPushToken = nil
        } catch {
            // nächster Start versucht es erneut
        }
    }

    // MARK: Widget-Auszug

    /// Schreibt den nicht vertraulichen Auszug für Widgets/Siri (heute + 20 Tage).
    public func updateSnapshot(current: MyPlanResponse? = nil) async {
        guard let store = env.snapshotStore, let cache, status == .ready else { return }
        let t = today
        guard let ym = YearMonth(key: t) else { return }
        var plans: [MyPlanResponse] = []
        for m in [ym, ym.adding(months: 1)] {
            if m == ym, let current {
                plans.append(current)
            } else if let c = await cache.get(CacheKeys.plan(m.key), as: MyPlanResponse.self) {
                plans.append(c.data)
            } else if online, let api, let p = try? await api.myPlan(month: m) {
                try? await cache.put(CacheKeys.plan(m.key), p)
                plans.append(p)
            }
        }
        guard !plans.isEmpty else { return }
        var places: [ISODay: String] = [:]
        if let myID {
            for d in (0..<21).map({ ISOCalendar.addDays(t, $0) }) {
                if let det = await cache.get(CacheKeys.details(d, myID), as: AssignmentDetails.self)?.data, let name = det.location?.name {
                    places[d] = name
                }
            }
        }
        let snap = PlanSnapshot.make(plans: plans, me: me, tenantName: tenantName, branding: branding, from: t, places: places, demo: isDemo, now: env.now())
        try? store.save(snap)
        onSnapshotUpdated?(snap)
    }

    /// „Heute“ (im Demo-Modus ggf. fest eingestellt).
    public var today: ISODay { env.demoOptions.today.flatMap { isDemo ? $0 : nil } ?? ISOCalendar.today(now: env.now()) }

    // MARK: Abmelden / Löschen

    /// Abmelden: Push-Registrierung entfernen, Server-Abmeldung, alle lokalen Benutzerdaten löschen.
    public func logout() async {
        await stopLive()
        if let api, status == .ready {
            if let id = prefs.deviceID { try? await api.unregisterDevice(id) }
            await api.logout()
        } else {
            try? await env.secureStore.remove(Self.refreshTokenKey)
        }
        await clearAllCaches()
        try? await env.secureStore.clear()
        env.snapshotStore?.remove()
        prefs.lastUserID = nil
        prefs.deviceID = nil
        prefs.biometric = false
        savePrefs()
        me = nil
        unlocked = false
        unreadCount = 0
        loginMessage = "Sie wurden abgemeldet."
        status = prefs.serverURL != nil || prefs.demo ? .needsLogin : .needsServer
        onSnapshotUpdated?(nil)
        onSignedOut?()
    }

    /// „Lokale Daten löschen“: Abmelden und vollständiger Reset inkl. Server-Einstellungen.
    public func wipeLocal() async {
        await logout()
        env.prefs.clear()
        prefs = AppPrefs()
        api = nil
        demoServer = nil
        config = nil
        loginMessage = nil
        status = .needsServer
    }

    public func setTheme(_ t: ThemeMode) {
        prefs.theme = t
        savePrefs()
    }

    public func setSpotlight(_ on: Bool) {
        prefs.spotlight = on
        savePrefs()
    }

    public func setLiveActivity(_ on: Bool) {
        prefs.liveActivity = on
        savePrefs()
    }

    /// Demo: veröffentlichte Planänderung simulieren und synchronisieren.
    public func simulateDemoChange() async -> SyncResult? {
        guard let demoServer else { return nil }
        demoServer.publishChange([ISOCalendar.addDays(today, 1), ISOCalendar.addDays(today, 2)])
        let r = await sync()
        _ = try? await notifications()
        return r
    }
}

/// Schwache Referenz für Rückrufe aus Actors (kein Haltezyklus, Sendable).
final class WeakSession: @unchecked Sendable {
    weak var session: SessionController?

    init(_ s: SessionController) {
        session = s
    }

    @MainActor func isUnlocked() -> Bool { session?.unlocked ?? false }

    @MainActor func sessionExpired() async {
        guard let s = session, s.status == .ready else { return }
        await s.expire()
    }

    @MainActor func tokens(_ t: TokenResponse) { session?.acceptMe(t.user) }
    @MainActor func live(_ ev: LiveEvent) async { await session?.handleLive(ev) }
    @MainActor func liveReset() async { _ = await session?.sync() }
    @MainActor func liveState(_ st: LiveConnection.State) { session?.setLiveState(st) }
}

/// Gibt das Refresh-Token nur frei, wenn die App entsperrt ist (Biometrie).
struct GatedRefreshTokenStore: RefreshTokenStore {
    let inner: RefreshTokenStore
    let isUnlocked: @Sendable () async -> Bool

    func get() async throws -> String? {
        guard await isUnlocked() else { throw APIError(.appLocked) }
        return try await inner.get()
    }

    func set(_ token: String?) async throws { try await inner.set(token) }
}
