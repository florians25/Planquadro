import Foundation

/// Demo-/Screenshot-Modus: ein eingebauter Fake-Server, der den API-Vertrag (docs/API.md)
/// mit Beispieldaten nachbildet (Port von `mobile/src/api/mock.ts`, Fixtures aus
/// `mobile/src/api/fixtures/`). Er ersetzt nur den Transport – der komplette Client-Pfad
/// (Header, Fehler, Refresh-Rotation) läuft auch ohne Backend.
///
/// Testzugänge (Passwort immer „demo“):
///   beliebiger Name → angemeldet · „gesperrt“ → 403 account_locked ·
///   „neu“ → 403 password_change_required · „wartung“ → 503 maintenance
///
/// Alle Namen und Firmen sind erfunden.
public final class DemoServer: HTTPTransport, @unchecked Sendable {
    public static let baseURL = URL(string: "https://demo.planquadro.invalid/api/v1/")!
    public static let password = "demo"

    struct Area: Codable {
        var id: Int
        var code: String
        var name: String
        var type: String
        var color: String
        var fg: String
        var remarks: String?
        var customer: AssignmentDetails.Customer?
        var location: AssignmentDetails.Location?
    }

    struct AbsenceType: Codable {
        var code: String
        var label: String
        var color: String
        var fg: String
    }

    struct AbsencesFixture: Codable {
        var types: [String: AbsenceType]
        var vacation: VacationAccount
        var requests: [AbsenceRequestItem]
    }

    struct HolidayFixture: Codable {
        var date: String
        var name: String
        var type: String
    }

    public struct Options: Sendable {
        /// Lebensdauer des Access-Tokens (Standard 15 min wie der Server)
        public var accessTTL: Int
        public var latency: TimeInterval
        public var oidcEnabled: Bool
        public var approvals: Bool
        /// fester „Heute“-Tag (für reproduzierbare Screenshots); nil = Gerätedatum
        public var today: ISODay?

        public init(accessTTL: Int = 900, latency: TimeInterval = 0.15, oidcEnabled: Bool = false, approvals: Bool = true, today: ISODay? = nil) {
            self.accessTTL = accessTTL
            self.latency = latency
            self.oidcEnabled = oidcEnabled
            self.approvals = approvals
            self.today = today
        }
    }

    private let lock = NSLock()
    private let options: Options
    private let now: @Sendable () -> Date
    private let areas: [Area]
    private let holidays: [ISODay: Holiday]
    private let absenceTypes: [String: AbsenceType]
    private let vacation: VacationAccount
    private let meFixture: MeResponse
    private let configFixture: ConfigResponse
    private let team: [TeamUser]

    private var access: [String: (userID: Int, exp: Date)] = [:]
    private var refreshUsed = Set<String>()
    private var revokedFamilies = Set<String>()
    private var notifications: [NotificationItem]
    private var requests: [AbsenceRequestItem]
    private var devices: [Int: String] = [:]
    private var cursor = 1842
    private var pendingChanges: [ISODay] = []
    private var seq = 100
    /// Protokoll für Tests: Methode, Pfad, Header
    public private(set) var calls: [(method: String, path: String, headers: [String: String])] = []

    public init(options: Options = Options(), now: @escaping @Sendable () -> Date = { Date() }) {
        self.options = options
        self.now = now
        func load<T: Decodable>(_ name: String, _ t: T.Type) -> T {
            let url = Bundle.module.url(forResource: name, withExtension: "json", subdirectory: "Fixtures")
                ?? Bundle.module.url(forResource: name, withExtension: "json")!
            return try! JSONDecoder().decode(T.self, from: Data(contentsOf: url))
        }
        areas = load("areas", [Area].self)
        holidays = Dictionary(load("holidays", [HolidayFixture].self).map { ($0.date, Holiday(name: $0.name, type: $0.type)) }, uniquingKeysWith: { a, _ in a })
        let abs = load("absences", AbsencesFixture.self)
        absenceTypes = abs.types
        vacation = abs.vacation
        requests = abs.requests
        meFixture = load("me", MeResponse.self)
        configFixture = load("config", ConfigResponse.self)
        notifications = load("notifications", NotificationsResponse.self).items
        team = load("team", [TeamUser].self)
    }

    public var demoUser: MeResponse { meFixture }

    // MARK: Beispielplan

    /// FNV-1a über UTF-16-Codeeinheiten (wie die Capacitor-Demo).
    static func hash(_ s: String) -> UInt32 {
        var h: UInt32 = 2_166_136_261
        for u in s.utf16 {
            h ^= UInt32(u)
            h = h &* 16_777_619
        }
        return h
    }

    private func absenceEntry(_ type: String, remarks: String? = nil) -> PlanEntry {
        let t = absenceTypes[type] ?? absenceTypes["vertraulich"]!
        return PlanEntry(kind: .absence, type: type, code: t.code, label: t.label, color: t.color, fg: t.fg, remarks: remarks)
    }

    /// Deterministischer Beispielplan für Benutzer/Tag.
    func entry(userID: Int, date: ISODay) -> PlanEntry? {
        guard let wd = ISOCalendar.weekday(date), let week = ISOCalendar.isoWeek(date)?.week else { return nil }
        if wd >= 6 || holidays[date]?.type == "feiertag" { return nil }
        let h = Self.hash("\(userID):\(date)")
        if (week + userID) % 11 == 0 { return absenceEntry("urlaub_genehmigt") }
        if h % 89 == 0 { return absenceEntry("krank") }
        if h % 29 == 0 { return absenceEntry("gleitzeit") }
        let area = areas[(week + userID + (wd >= 4 ? 1 : 0)) % areas.count]
        return PlanEntry(kind: .assignment, areaID: area.id, code: area.code, label: area.name, color: area.color, fg: area.fg, remarks: area.remarks)
    }

    private var todayISO: ISODay { options.today ?? ISOCalendar.today(now: now()) }

    private func meResponse() -> MeResponse {
        var m = meFixture
        m.modules.approvals = options.approvals
        m.serverTime = ISO8601DateFormatter().string(from: now())
        return m
    }

    private func planMy(from: ISODay, to: ISODay) -> MyPlanResponse {
        let days = ISOCalendar.eachDay(from: from, to: to).map {
            PlanDay(date: $0, weekday: ISOCalendar.weekday($0) ?? 1, holiday: holidays[$0], entry: entry(userID: 12, date: $0))
        }
        return MyPlanResponse(from: from, to: to, publishedAt: ISO8601DateFormatter().string(from: now().addingTimeInterval(-3600)), days: days)
    }

    private func teamPlan(_ ym: YearMonth) -> TeamPlanResponse {
        let days = ym.days
        var hol: [ISODay: Holiday] = [:]
        var cells: [String: TeamCell] = [:]
        for d in days {
            if let h = holidays[d] { hol[d] = h }
            for u in team {
                guard var e = entry(userID: u.id, date: d) else { continue }
                // Wie der Server für Mitarbeitende: Gründe anderer (Krankheit, Urlaub) nur als „A“
                let confidential = !u.isMe && e.kind == .absence && Confidentiality.isConfidentialType(e.type)
                if confidential { e = absenceEntry("vertraulich") }
                cells[TeamPlanResponse.cellKey(userID: u.id, date: d)] = TeamCell(kind: e.kind, code: e.code, color: e.color, fg: e.fg, confidential: confidential)
            }
        }
        return TeamPlanResponse(year: ym.year, month: ym.month, days: days, holidays: hol, users: team, cells: cells)
    }

    private func details(date: ISODay, userID: Int) -> AssignmentDetails {
        guard let e = entry(userID: userID, date: date) else {
            return AssignmentDetails(date: date, userID: userID, entry: nil)
        }
        if e.kind == .absence {
            let shown = userID == 12 ? e : PlanEntry(kind: .absence, code: "A", label: "Abwesend", color: "#ef4444", fg: "#ffffff", confidential: true)
            return AssignmentDetails(date: date, userID: userID, entry: shown)
        }
        let area = areas.first { $0.id == e.areaID }!
        let colleagues = team.filter { $0.id != userID }.filter { u in
            let o = entry(userID: u.id, date: date)
            return o?.kind == .assignment && o?.areaID == area.id
        }.map { AssignmentDetails.Colleague(id: $0.id, name: $0.name) }
        return AssignmentDetails(date: date, userID: userID, entry: e, area: .init(id: area.id, code: area.code, name: area.name, type: area.type),
                                 customer: area.customer, location: area.location, colleagues: colleagues)
    }

    private func absences(year: Int) -> MyAbsencesResponse {
        var items: [AbsenceItem] = []
        var cur: (start: ISODay, end: ISODay, days: Double, type: String)?
        func flush() {
            if let c = cur { items.append(AbsenceItem(start: c.start, end: c.end, days: c.days, type: c.type, label: absenceTypes[c.type]?.label ?? c.type, published: true)) }
        }
        for d in ISOCalendar.eachDay(from: "\(year)-01-01", to: "\(year)-12-31") {
            let e = entry(userID: 12, date: d)
            let t = e?.kind == .absence ? e?.type : nil
            if let t, var c = cur, c.type == t {
                // zusammenhängend: Folgetag oder über das Wochenende (wie das Abwesenheitsmodul)
                let gap = ISOCalendar.daysBetween(c.end, d) ?? 99
                if gap == 1 || (gap <= 3 && ISOCalendar.weekday(c.end) == 5) {
                    c.end = d
                    c.days += 1
                    cur = c
                    continue
                }
            }
            // Wochenenden unterbrechen Blöcke nicht
            if t == nil, cur != nil, (ISOCalendar.weekday(d) ?? 0) >= 6 { continue }
            flush()
            cur = t.map { (d, d, 1, $0) }
        }
        flush()
        let sick = items.filter { $0.type == "krank" }.reduce(0) { $0 + $1.days }
        return MyAbsencesResponse(year: year, vacation: vacation, sickDays: sick, items: items,
                                  requests: requests.filter { $0.date.hasPrefix(String(year)) })
    }

    // MARK: Routing

    private static func json<T: Encodable>(_ status: Int, _ body: T) -> HTTPResponse {
        let enc = JSONEncoder()
        enc.outputFormatting = [.sortedKeys]
        return HTTPResponse(status: status, headers: ["Content-Type": "application/json"], body: (try? enc.encode(body)) ?? Data())
    }

    private static func err(_ status: Int, _ code: String, _ message: String) -> HTTPResponse {
        json(status, ["error": ["code": code, "message": message]])
    }

    private static func tokenID(_ prefix: String) -> String {
        prefix + PKCE.randomBytes(16).map { b in
            let s = String(b, radix: 16)
            return s.count == 1 ? "0" + s : s
        }.joined()
    }

    private func issue(userID: Int, family: String? = nil) -> TokenResponse {
        let fam = family ?? Self.tokenID("fam-")
        let at = Self.tokenID("demo-at-")
        access[at] = (userID, now().addingTimeInterval(TimeInterval(options.accessTTL)))
        return TokenResponse(accessToken: at, expiresIn: options.accessTTL, refreshToken: "demo-rt-\(fam).\(Self.tokenID(""))",
                             refreshExpiresIn: 2_592_000, user: meResponse())
    }

    private static func family(of rt: String) -> String? {
        guard rt.hasPrefix("demo-rt-"), let dot = rt.firstIndex(of: ".") else { return nil }
        return String(rt[rt.index(rt.startIndex, offsetBy: 8)..<dot])
    }

    public func send(_ request: HTTPRequest) async throws -> HTTPResponse {
        if options.latency > 0 { try await Task.sleep(nanoseconds: UInt64(options.latency * 1_000_000_000)) }
        return handleLocked(request)
    }

    private func handleLocked(_ request: HTTPRequest) -> HTTPResponse {
        lock.lock()
        defer { lock.unlock() }
        return handle(request)
    }

    private func handle(_ request: HTTPRequest) -> HTTPResponse {
        let base = Self.baseURL.absoluteString
        let full = request.url.absoluteString
        guard full.hasPrefix(base) else { return Self.err(404, "not_found", "Unbekannter Host.") }
        let comps = URLComponents(url: request.url, resolvingAgainstBaseURL: false)
        let path = String(comps?.path.dropFirst(Self.baseURL.path.count) ?? "").trimmingCharacters(in: CharacterSet(charactersIn: "/"))
        let q = Dictionary((comps?.queryItems ?? []).map { ($0.name, $0.value ?? "") }, uniquingKeysWith: { a, _ in a })
        let method = request.method.uppercased()
        calls.append((method, path, request.headers))
        let body = request.body.flatMap { try? JSONSerialization.jsonObject(with: $0) } as? [String: Any] ?? [:]
        let route = "\(method) \(path)"

        // ---- öffentlich
        switch route {
        case "GET config":
            var c = configFixture
            c.modules.approvals = options.approvals
            if options.oidcEnabled {
                c.auth.oidc = .init(enabled: true, label: "Mit Firmenkonto anmelden", authorizeURL: "https://login.demo.invalid/authorize?client_id=planquadro-app")
            }
            return Self.json(200, c)
        case "GET health":
            return Self.json(200, ["status": "ok"])
        case "POST auth/login":
            let u = (body["username"] as? String ?? "").trimmingCharacters(in: .whitespaces).lowercased()
            let p = body["password"] as? String ?? ""
            if u.isEmpty || p.isEmpty { return Self.err(400, "invalid_request", "Benutzername und Passwort angeben.") }
            if u == "wartung" { return Self.err(503, "maintenance", "Wartungsarbeiten bis 20:00 Uhr.") }
            if p != Self.password { return Self.err(401, "invalid_credentials", "Benutzername oder Passwort ist falsch.") }
            if u == "gesperrt" { return Self.err(403, "account_locked", "Konto gesperrt.") }
            if u == "neu" { return Self.err(403, "password_change_required", "Passwort muss geändert werden.") }
            return Self.json(200, issue(userID: 12))
        case "POST auth/refresh":
            let rt = body["refresh_token"] as? String ?? ""
            guard let fam = Self.family(of: rt), !revokedFamilies.contains(fam) else { return Self.err(401, "invalid_token", "Refresh-Token ungültig.") }
            if refreshUsed.contains(rt) {
                revokedFamilies.insert(fam) // Wiederverwendung → ganze Familie sperren
                return Self.err(401, "invalid_token", "Refresh-Token wurde bereits verwendet.")
            }
            refreshUsed.insert(rt)
            return Self.json(200, issue(userID: 12, family: fam))
        case "POST auth/oidc/token":
            guard options.oidcEnabled else { return Self.err(404, "not_found", "SSO ist nicht aktiviert.") }
            let code = body["code"] as? String ?? "", verifier = body["code_verifier"] as? String ?? "", nonce = body["nonce"] as? String ?? ""
            if code.isEmpty || verifier.count < 43 || nonce.isEmpty { return Self.err(400, "invalid_request", "code, code_verifier und nonce erforderlich.") }
            if body["redirect_uri"] as? String != PKCE.redirectURI { return Self.err(400, "invalid_request", "Unzulässige redirect_uri.") }
            return Self.json(200, issue(userID: 12))
        default:
            break
        }

        // ---- geschützt
        guard let auth = request.header("Authorization"), auth.hasPrefix("Bearer ") else { return Self.err(401, "invalid_token", "Anmeldung erforderlich.") }
        let token = String(auth.dropFirst(7))
        guard let t = access[token] else { return Self.err(401, "invalid_token", "Token ungültig.") }
        if now() >= t.exp { return Self.err(401, "token_expired", "Token abgelaufen.") }
        let myID = t.userID

        switch route {
        case "POST auth/logout":
            if let fam = Self.family(of: body["refresh_token"] as? String ?? "") { revokedFamilies.insert(fam) }
            access[token] = nil
            return HTTPResponse(status: 204)
        case "GET me":
            return Self.json(200, meResponse())
        case "GET plan/my":
            let from = q["from"] ?? "", to = q["to"] ?? ""
            guard ISOCalendar.isValid(from), ISOCalendar.isValid(to), to >= from else { return Self.err(400, "invalid_request", "from/to ungültig.") }
            if (ISOCalendar.daysBetween(from, to) ?? 0) > 92 { return Self.err(400, "invalid_request", "Maximal 93 Tage je Abfrage.") }
            return Self.json(200, planMy(from: from, to: to))
        case "GET plan/team":
            guard let y = Int(q["year"] ?? ""), let m = Int(q["month"] ?? ""), (1...12).contains(m) else { return Self.err(400, "invalid_request", "year/month ungültig.") }
            return Self.json(200, teamPlan(YearMonth(year: y, month: m)))
        case "GET absences/my":
            let y = Int(q["year"] ?? "") ?? Int(todayISO.prefix(4))!
            return Self.json(200, absences(year: y))
        case "POST absences/requests":
            guard options.approvals else { return Self.err(403, "module_disabled", "Anträge sind nicht aktiviert.") }
            let from = body["from"] as? String ?? "", to = body["to"] as? String ?? "", type = body["type"] as? String ?? ""
            guard ISOCalendar.isValid(from), ISOCalendar.isValid(to), to >= from else { return Self.err(400, "invalid_request", "Zeitraum ungültig.") }
            guard RequestType(rawValue: type) != nil else { return Self.err(400, "invalid_request", "Art ungültig.") }
            var created = 0, skipped = 0
            for d in ISOCalendar.eachDay(from: from, to: to) {
                if (ISOCalendar.weekday(d) ?? 7) >= 6 || holidays[d]?.type == "feiertag" { continue }
                if requests.contains(where: { $0.date == d && $0.status != "rejected" }) {
                    skipped += 1
                    continue
                }
                seq += 1
                requests.insert(AbsenceRequestItem(id: seq, date: d, type: type, status: "pending", createdAt: ISO8601DateFormatter().string(from: now())), at: 0)
                created += 1
            }
            return Self.json(201, AbsenceRequestCreated(created: created, skipped: skipped))
        case "GET holidays":
            let y = q["year"] ?? ""
            let items = holidays.filter { $0.key.hasPrefix(y) }.sorted { $0.key < $1.key }
                .map { ["date": $0.key, "name": $0.value.name, "type": $0.value.type] }
            return Self.json(200, ["items": items])
        case "GET notifications":
            let limit = Int(q["limit"] ?? "") ?? 30
            return Self.json(200, NotificationsResponse(unread: notifications.filter { !$0.isRead }.count, items: Array(notifications.prefix(limit))))
        case "POST notifications/read-all":
            for i in notifications.indices { notifications[i].isRead = true }
            return HTTPResponse(status: 204)
        case "GET sync":
            guard let since = q["since"], !since.isEmpty else { return Self.json(200, SyncResponse(cursor: String(cursor), changedDates: [], fullResync: false)) }
            if (Int(since) ?? 0) < cursor - 1000 { return Self.json(200, SyncResponse(cursor: String(cursor), changedDates: [], fullResync: true)) }
            let changed = pendingChanges
            pendingChanges.removeAll()
            if !changed.isEmpty { cursor += 1 }
            return Self.json(200, SyncResponse(cursor: String(cursor), changedDates: changed, fullResync: false))
        case "POST events/ticket":
            // Im Demo-Modus keine Live-Verbindung (wie die Capacitor-Demo) → nur Sync
            return Self.err(404, "not_found", "Live-Aktualisierung ist im Demo-Modus aus.")
        case "POST devices":
            seq += 1
            devices[seq] = body["token"] as? String ?? ""
            return Self.json(201, ["id": seq])
        case "GET calendar", "POST calendar":
            return Self.json(route.hasPrefix("POST") ? 201 : 200, CalendarSubscription(enabled: true, url: "https://demo.planquadro.invalid/api/ical?token=demo"))
        default:
            break
        }
        if method == "GET", path.hasPrefix("assignments/") {
            let date = String(path.dropFirst("assignments/".count))
            guard ISOCalendar.isValid(date) else { return Self.err(400, "invalid_request", "Datum ungültig.") }
            let uid = Int(q["user_id"] ?? "") ?? myID
            guard team.contains(where: { $0.id == uid }) else { return Self.err(404, "not_found", "Mitarbeiter nicht gefunden.") }
            return Self.json(200, details(date: date, userID: uid))
        }
        if method == "POST", path.hasPrefix("notifications/"), path.hasSuffix("/read") {
            let id = Int(path.dropFirst("notifications/".count).dropLast("/read".count)) ?? -1
            guard let i = notifications.firstIndex(where: { $0.id == id }) else { return Self.err(404, "not_found", "Benachrichtigung nicht gefunden.") }
            notifications[i].isRead = true
            return HTTPResponse(status: 204)
        }
        if method == "DELETE", path.hasPrefix("devices/"), let id = Int(path.dropFirst("devices/".count)) {
            devices[id] = nil
            return HTTPResponse(status: 204)
        }
        return Self.err(404, "not_found", "Unbekannter Endpunkt \(route)")
    }

    // MARK: Steuerung für Demo und Tests

    /// Simuliert eine veröffentlichte Planänderung (für Sync-/Push-Demo).
    public func publishChange(_ dates: [ISODay]) {
        lock.lock()
        defer { lock.unlock() }
        pendingChanges += dates
        seq += 1
        notifications.insert(NotificationItem(id: seq, title: "Plan geändert",
                                              message: "Ihr Plan wurde geändert (\(dates.count) \(dates.count == 1 ? "Tag" : "Tage")).",
                                              link: "mein_plan", isRead: false, createdAt: ISO8601DateFormatter().string(from: now())), at: 0)
    }

    public var registeredDevices: [Int: String] {
        lock.lock()
        defer { lock.unlock() }
        return devices
    }

    public var callLog: [(method: String, path: String, headers: [String: String])] {
        lock.lock()
        defer { lock.unlock() }
        return calls
    }
}
