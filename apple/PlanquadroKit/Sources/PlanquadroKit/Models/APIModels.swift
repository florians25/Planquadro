import Foundation

// Typen der Planquadro API v1 – 1:1 nach docs/API.md (verbindlicher Vertrag).
// Datumsangaben `Y-m-d` (ohne Uhrzeit, Zeitzone des Mandanten) bleiben Strings
// (`ISODay`), Zeitstempel ISO 8601 mit Offset ebenfalls (`ISODateTime`) – so geht
// beim Cachen nichts verloren. Schlüssel werden über explizite CodingKeys abgebildet
// (keine `convertFromSnakeCase`-Strategie: sie würde auch Wörterbuch-Schlüssel wie
// `12_2026-11-02` verändern).

/// `Y-m-d`
public typealias ISODay = String
/// ISO 8601 mit Offset
public typealias ISODateTime = String

public struct Branding: Codable, Hashable, Sendable {
    public var primary: String
    public var accent: String
    public var logoURL: String?

    public init(primary: String, accent: String, logoURL: String?) {
        self.primary = primary
        self.accent = accent
        self.logoURL = logoURL
    }

    enum CodingKeys: String, CodingKey {
        case primary, accent
        case logoURL = "logo_url"
    }

    public init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        primary = (try c.decodeIfPresent(String.self, forKey: .primary)) ?? BrandColors.quadroBlueHex
        accent = (try c.decodeIfPresent(String.self, forKey: .accent)) ?? BrandColors.signalOrangeHex
        logoURL = try c.decodeIfPresent(String.self, forKey: .logoURL)
    }

    /// Planquadro-Standard (Quadro-Blau, Signal-Orange, kein Logo).
    public static let planquadro = Branding(primary: BrandColors.quadroBlueHex, accent: BrandColors.signalOrangeHex, logoURL: nil)
}

public struct TenantInfo: Codable, Hashable, Sendable {
    public var name: String
    public var slug: String
    public var branding: Branding

    public init(name: String, slug: String, branding: Branding) {
        self.name = name
        self.slug = slug
        self.branding = branding
    }
}

public struct Modules: Codable, Hashable, Sendable {
    public var absences: Bool
    public var approvals: Bool
    public var ical: Bool
    public var assistant: Bool
    public var mobile: Bool

    public init(absences: Bool = true, approvals: Bool = false, ical: Bool = false, assistant: Bool = false, mobile: Bool = true) {
        self.absences = absences
        self.approvals = approvals
        self.ical = ical
        self.assistant = assistant
        self.mobile = mobile
    }

    enum CodingKeys: String, CodingKey { case absences, approvals, ical, assistant, mobile }

    public init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        absences = try c.decodeIfPresent(Bool.self, forKey: .absences) ?? true
        approvals = try c.decodeIfPresent(Bool.self, forKey: .approvals) ?? false
        ical = try c.decodeIfPresent(Bool.self, forKey: .ical) ?? false
        assistant = try c.decodeIfPresent(Bool.self, forKey: .assistant) ?? false
        mobile = try c.decodeIfPresent(Bool.self, forKey: .mobile) ?? true
    }
}

/// `GET config`
public struct ConfigResponse: Codable, Hashable, Sendable {
    public struct OIDC: Codable, Hashable, Sendable {
        public var enabled: Bool
        public var label: String?
        public var authorizeURL: String?

        public init(enabled: Bool, label: String?, authorizeURL: String?) {
            self.enabled = enabled
            self.label = label
            self.authorizeURL = authorizeURL
        }

        enum CodingKeys: String, CodingKey {
            case enabled, label
            case authorizeURL = "authorize_url"
        }
    }

    public struct Auth: Codable, Hashable, Sendable {
        public var password: Bool
        public var oidc: OIDC

        public init(password: Bool, oidc: OIDC) {
            self.password = password
            self.oidc = oidc
        }
    }

    public var apiVersion: Int
    public var tenant: TenantInfo
    public var auth: Auth
    public var modules: Modules

    public init(apiVersion: Int, tenant: TenantInfo, auth: Auth, modules: Modules) {
        self.apiVersion = apiVersion
        self.tenant = tenant
        self.auth = auth
        self.modules = modules
    }

    enum CodingKeys: String, CodingKey {
        case apiVersion = "api_version"
        case tenant, auth, modules
    }

    /// Anmeldung per Firmenkonto möglich (aktiv und Autorisierungs-URL vorhanden).
    public var oidcAvailable: Bool {
        auth.oidc.enabled && (auth.oidc.authorizeURL?.isEmpty == false)
    }
}

public struct User: Codable, Hashable, Sendable, Identifiable {
    public var id: Int
    public var username: String
    public var firstName: String
    public var lastName: String
    public var role: String
    public var roleLabel: String

    public init(id: Int, username: String, firstName: String, lastName: String, role: String, roleLabel: String) {
        self.id = id
        self.username = username
        self.firstName = firstName
        self.lastName = lastName
        self.role = role
        self.roleLabel = roleLabel
    }

    enum CodingKeys: String, CodingKey {
        case id, username, role
        case firstName = "first_name"
        case lastName = "last_name"
        case roleLabel = "role_label"
    }

    public var fullName: String { "\(firstName) \(lastName)".trimmingCharacters(in: .whitespaces) }

    /// Initialen für Avatare (z. B. „MM“).
    public var initials: String {
        let f = firstName.first.map(String.init) ?? ""
        let l = lastName.first.map(String.init) ?? ""
        let s = (f + l).uppercased()
        return s.isEmpty ? String(username.prefix(2)).uppercased() : s
    }
}

public struct Permissions: Codable, Hashable, Sendable {
    public var planView: Bool
    public var planEdit: Bool
    public var approveRequests: Bool
    public var viewAllEmployees: Bool

    public init(planView: Bool = false, planEdit: Bool = false, approveRequests: Bool = false, viewAllEmployees: Bool = false) {
        self.planView = planView
        self.planEdit = planEdit
        self.approveRequests = approveRequests
        self.viewAllEmployees = viewAllEmployees
    }

    enum CodingKeys: String, CodingKey {
        case planView = "plan_view"
        case planEdit = "plan_edit"
        case approveRequests = "approve_requests"
        case viewAllEmployees = "view_all_employees"
    }

    public init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        planView = try c.decodeIfPresent(Bool.self, forKey: .planView) ?? false
        planEdit = try c.decodeIfPresent(Bool.self, forKey: .planEdit) ?? false
        approveRequests = try c.decodeIfPresent(Bool.self, forKey: .approveRequests) ?? false
        viewAllEmployees = try c.decodeIfPresent(Bool.self, forKey: .viewAllEmployees) ?? false
    }
}

/// `GET me`
public struct MeResponse: Codable, Hashable, Sendable {
    public var user: User
    public var permissions: Permissions
    public var modules: Modules
    public var tenant: TenantInfo
    public var serverTime: ISODateTime?

    public init(user: User, permissions: Permissions, modules: Modules, tenant: TenantInfo, serverTime: ISODateTime?) {
        self.user = user
        self.permissions = permissions
        self.modules = modules
        self.tenant = tenant
        self.serverTime = serverTime
    }

    enum CodingKeys: String, CodingKey {
        case user, permissions, modules, tenant
        case serverTime = "server_time"
    }
}

/// `POST auth/login`
public struct LoginRequest: Codable, Hashable, Sendable {
    public var username: String
    public var password: String
    public var deviceName: String

    public init(username: String, password: String, deviceName: String) {
        self.username = username
        self.password = password
        self.deviceName = deviceName
    }

    enum CodingKeys: String, CodingKey {
        case username, password
        case deviceName = "device_name"
    }
}

/// Antwort von `auth/login`, `auth/refresh`, `auth/oidc/token`.
public struct TokenResponse: Codable, Hashable, Sendable {
    public var accessToken: String
    public var tokenType: String
    public var expiresIn: Int
    public var refreshToken: String
    public var refreshExpiresIn: Int
    /// Laut Vertrag „wie GET me“.
    public var user: MeResponse

    public init(accessToken: String, tokenType: String = "Bearer", expiresIn: Int, refreshToken: String, refreshExpiresIn: Int, user: MeResponse) {
        self.accessToken = accessToken
        self.tokenType = tokenType
        self.expiresIn = expiresIn
        self.refreshToken = refreshToken
        self.refreshExpiresIn = refreshExpiresIn
        self.user = user
    }

    enum CodingKeys: String, CodingKey {
        case user
        case accessToken = "access_token"
        case tokenType = "token_type"
        case expiresIn = "expires_in"
        case refreshToken = "refresh_token"
        case refreshExpiresIn = "refresh_expires_in"
    }
}

public struct OIDCTokenRequest: Codable, Hashable, Sendable {
    public var code: String
    public var codeVerifier: String
    public var nonce: String
    public var redirectURI: String
    public var deviceName: String?

    public init(code: String, codeVerifier: String, nonce: String, redirectURI: String, deviceName: String? = nil) {
        self.code = code
        self.codeVerifier = codeVerifier
        self.nonce = nonce
        self.redirectURI = redirectURI
        self.deviceName = deviceName
    }

    enum CodingKeys: String, CodingKey {
        case code, nonce
        case codeVerifier = "code_verifier"
        case redirectURI = "redirect_uri"
        case deviceName = "device_name"
    }
}

public struct Holiday: Codable, Hashable, Sendable {
    public var name: String
    /// `feiertag` oder `halbtag`
    public var type: String

    public init(name: String, type: String) {
        self.name = name
        self.type = type
    }

    public var isFullHoliday: Bool { type == "feiertag" }
}

/// Eintrag eines Tages: Einsatz oder Abwesenheit.
public struct PlanEntry: Codable, Hashable, Sendable {
    public enum Kind: String, Codable, Sendable {
        case assignment
        case absence
    }

    public var kind: Kind
    public var areaID: Int?
    /// Abwesenheitsart (fehlt bei vertraulichen Abwesenheiten anderer)
    public var type: String?
    public var code: String
    public var label: String
    public var color: String
    public var fg: String
    public var remarks: String?
    /// Vertrauliche Abwesenheit einer anderen Person: nur „A“, keine Details.
    public var confidential: Bool

    public init(kind: Kind, areaID: Int? = nil, type: String? = nil, code: String, label: String, color: String, fg: String, remarks: String? = nil, confidential: Bool = false) {
        self.kind = kind
        self.areaID = areaID
        self.type = type
        self.code = code
        self.label = label
        self.color = color
        self.fg = fg
        self.remarks = remarks
        self.confidential = confidential
    }

    enum CodingKeys: String, CodingKey {
        case kind, type, code, label, color, fg, remarks, confidential
        case areaID = "area_id"
    }

    public init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        kind = (try? c.decode(Kind.self, forKey: .kind)) ?? .absence
        areaID = try c.decodeIfPresent(Int.self, forKey: .areaID)
        type = try c.decodeIfPresent(String.self, forKey: .type)
        code = try c.decodeIfPresent(String.self, forKey: .code) ?? "?"
        label = try c.decodeIfPresent(String.self, forKey: .label) ?? code
        color = try c.decodeIfPresent(String.self, forKey: .color) ?? "#64748b"
        fg = try c.decodeIfPresent(String.self, forKey: .fg) ?? "#ffffff"
        remarks = try c.decodeIfPresent(String.self, forKey: .remarks)
        confidential = try c.decodeIfPresent(Bool.self, forKey: .confidential) ?? false
    }

    public func encode(to encoder: Encoder) throws {
        var c = encoder.container(keyedBy: CodingKeys.self)
        try c.encode(kind, forKey: .kind)
        try c.encodeIfPresent(areaID, forKey: .areaID)
        try c.encodeIfPresent(type, forKey: .type)
        try c.encode(code, forKey: .code)
        try c.encode(label, forKey: .label)
        try c.encode(color, forKey: .color)
        try c.encode(fg, forKey: .fg)
        try c.encodeIfPresent(remarks, forKey: .remarks)
        if confidential { try c.encode(true, forKey: .confidential) }
    }

    public var isAssignment: Bool { kind == .assignment }
    public var isAbsence: Bool { kind == .absence }
}

public struct PlanDay: Codable, Hashable, Sendable, Identifiable {
    public var date: ISODay
    /// 1 = Montag … 7 = Sonntag
    public var weekday: Int
    public var holiday: Holiday?
    public var entry: PlanEntry?

    public var id: ISODay { date }

    public init(date: ISODay, weekday: Int, holiday: Holiday? = nil, entry: PlanEntry? = nil) {
        self.date = date
        self.weekday = weekday
        self.holiday = holiday
        self.entry = entry
    }

    public var isWeekend: Bool { weekday >= 6 }
}

/// `GET plan/my?from&to`
public struct MyPlanResponse: Codable, Hashable, Sendable {
    public var from: ISODay
    public var to: ISODay
    public var publishedAt: ISODateTime?
    public var days: [PlanDay]

    public init(from: ISODay, to: ISODay, publishedAt: ISODateTime?, days: [PlanDay]) {
        self.from = from
        self.to = to
        self.publishedAt = publishedAt
        self.days = days
    }

    enum CodingKeys: String, CodingKey {
        case from, to, days
        case publishedAt = "published_at"
    }

    /// Alle Tage von `from` bis `to` – Lücken (vom Server weggelassene freie Tage) aufgefüllt.
    public var filledDays: [PlanDay] {
        let byDate = Dictionary(days.map { ($0.date, $0) }, uniquingKeysWith: { a, _ in a })
        return ISOCalendar.eachDay(from: from, to: to).map { d in
            byDate[d] ?? PlanDay(date: d, weekday: ISOCalendar.weekday(d) ?? 1)
        }
    }
}

public struct TeamUser: Codable, Hashable, Sendable, Identifiable {
    public var id: Int
    public var name: String
    public var roleLabel: String
    public var isMe: Bool

    public init(id: Int, name: String, roleLabel: String, isMe: Bool) {
        self.id = id
        self.name = name
        self.roleLabel = roleLabel
        self.isMe = isMe
    }

    enum CodingKeys: String, CodingKey {
        case id, name
        case roleLabel = "role_label"
        case isMe = "is_me"
    }

    public init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = try c.decode(Int.self, forKey: .id)
        name = try c.decode(String.self, forKey: .name)
        roleLabel = try c.decodeIfPresent(String.self, forKey: .roleLabel) ?? ""
        isMe = try c.decodeIfPresent(Bool.self, forKey: .isMe) ?? false
    }
}

public struct TeamCell: Codable, Hashable, Sendable {
    public var kind: PlanEntry.Kind
    public var code: String
    public var color: String
    public var fg: String
    public var confidential: Bool

    public init(kind: PlanEntry.Kind, code: String, color: String, fg: String, confidential: Bool = false) {
        self.kind = kind
        self.code = code
        self.color = color
        self.fg = fg
        self.confidential = confidential
    }

    enum CodingKeys: String, CodingKey { case kind, code, color, fg, confidential }

    public init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        kind = (try? c.decode(PlanEntry.Kind.self, forKey: .kind)) ?? .absence
        code = try c.decodeIfPresent(String.self, forKey: .code) ?? "?"
        color = try c.decodeIfPresent(String.self, forKey: .color) ?? "#64748b"
        fg = try c.decodeIfPresent(String.self, forKey: .fg) ?? "#ffffff"
        confidential = try c.decodeIfPresent(Bool.self, forKey: .confidential) ?? false
    }

    public func encode(to encoder: Encoder) throws {
        var c = encoder.container(keyedBy: CodingKeys.self)
        try c.encode(kind, forKey: .kind)
        try c.encode(code, forKey: .code)
        try c.encode(color, forKey: .color)
        try c.encode(fg, forKey: .fg)
        if confidential { try c.encode(true, forKey: .confidential) }
    }
}

/// `GET plan/team?year&month`
public struct TeamPlanResponse: Codable, Hashable, Sendable {
    public var year: Int
    public var month: Int
    public var days: [ISODay]
    public var holidays: [ISODay: Holiday]
    public var users: [TeamUser]
    /// Schlüssel: `<user_id>_<Y-m-d>`
    public var cells: [String: TeamCell]

    public init(year: Int, month: Int, days: [ISODay], holidays: [ISODay: Holiday], users: [TeamUser], cells: [String: TeamCell]) {
        self.year = year
        self.month = month
        self.days = days
        self.holidays = holidays
        self.users = users
        self.cells = cells
    }

    enum CodingKeys: String, CodingKey { case year, month, days, holidays, users, cells }

    public init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        year = try c.decode(Int.self, forKey: .year)
        month = try c.decode(Int.self, forKey: .month)
        days = try c.decodeIfPresent([ISODay].self, forKey: .days) ?? []
        // PHP liefert ein leeres Objekt als `[]` – beides akzeptieren
        holidays = (try? c.decodeIfPresent([ISODay: Holiday].self, forKey: .holidays)) ?? [:]
        users = try c.decodeIfPresent([TeamUser].self, forKey: .users) ?? []
        cells = (try? c.decodeIfPresent([String: TeamCell].self, forKey: .cells)) ?? [:]
    }

    public static func cellKey(userID: Int, date: ISODay) -> String { "\(userID)_\(date)" }

    public func cell(userID: Int, date: ISODay) -> TeamCell? { cells[Self.cellKey(userID: userID, date: date)] }
}

/// `GET assignments/{date}?user_id`
public struct AssignmentDetails: Codable, Hashable, Sendable {
    public struct Area: Codable, Hashable, Sendable {
        public var id: Int
        public var code: String
        public var name: String
        public var type: String?

        public init(id: Int, code: String, name: String, type: String?) {
            self.id = id
            self.code = code
            self.name = name
            self.type = type
        }
    }

    public struct Customer: Codable, Hashable, Sendable {
        public var name: String
        public var address: String?
        public var contact: String?
        public var phone: String?
        public var email: String?
        public var notes: String?

        public init(name: String, address: String? = nil, contact: String? = nil, phone: String? = nil, email: String? = nil, notes: String? = nil) {
            self.name = name
            self.address = address
            self.contact = contact
            self.phone = phone
            self.email = email
            self.notes = notes
        }
    }

    public struct Location: Codable, Hashable, Sendable {
        public var name: String
        public var address: String?
        public var lat: Double?
        public var lng: Double?

        public init(name: String, address: String? = nil, lat: Double? = nil, lng: Double? = nil) {
            self.name = name
            self.address = address
            self.lat = lat
            self.lng = lng
        }

        public var hasCoordinates: Bool { lat != nil && lng != nil }
    }

    public struct Colleague: Codable, Hashable, Sendable, Identifiable {
        public var id: Int
        public var name: String

        public init(id: Int, name: String) {
            self.id = id
            self.name = name
        }
    }

    public var date: ISODay
    public var userID: Int
    public var entry: PlanEntry?
    public var area: Area?
    public var customer: Customer?
    public var location: Location?
    public var colleagues: [Colleague]

    public init(date: ISODay, userID: Int, entry: PlanEntry?, area: Area? = nil, customer: Customer? = nil, location: Location? = nil, colleagues: [Colleague] = []) {
        self.date = date
        self.userID = userID
        self.entry = entry
        self.area = area
        self.customer = customer
        self.location = location
        self.colleagues = colleagues
    }

    enum CodingKeys: String, CodingKey {
        case date, entry, area, customer, location, colleagues
        case userID = "user_id"
    }

    public init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        date = try c.decode(ISODay.self, forKey: .date)
        userID = try c.decode(Int.self, forKey: .userID)
        entry = try c.decodeIfPresent(PlanEntry.self, forKey: .entry)
        area = try c.decodeIfPresent(Area.self, forKey: .area)
        customer = try c.decodeIfPresent(Customer.self, forKey: .customer)
        location = try c.decodeIfPresent(Location.self, forKey: .location)
        colleagues = try c.decodeIfPresent([Colleague].self, forKey: .colleagues) ?? []
    }
}

public struct AbsenceItem: Codable, Hashable, Sendable, Identifiable {
    public var start: ISODay
    public var end: ISODay
    public var days: Double
    public var type: String
    public var label: String
    public var published: Bool

    public var id: String { "\(start)|\(type)" }

    public init(start: ISODay, end: ISODay, days: Double, type: String, label: String, published: Bool) {
        self.start = start
        self.end = end
        self.days = days
        self.type = type
        self.label = label
        self.published = published
    }
}

public enum RequestType: String, Codable, CaseIterable, Sendable, Identifiable {
    case vacationFull = "urlaub_ganz"
    case vacationHalf = "urlaub_halb"
    case flextime = "gleitzeit"

    public var id: String { rawValue }

    public var label: String {
        switch self {
        case .vacationFull: return "Urlaub (ganzer Tag)"
        case .vacationHalf: return "Urlaub (halber Tag)"
        case .flextime: return "Gleitzeit"
        }
    }

    public static func label(for raw: String) -> String {
        RequestType(rawValue: raw)?.label ?? raw
    }
}

public struct AbsenceRequestItem: Codable, Hashable, Sendable, Identifiable {
    public enum Status: String, Sendable {
        case pending, approved, rejected
    }

    public var id: Int
    public var date: ISODay
    public var type: String
    public var status: String
    public var createdAt: ISODateTime?

    public init(id: Int, date: ISODay, type: String, status: String, createdAt: ISODateTime?) {
        self.id = id
        self.date = date
        self.type = type
        self.status = status
        self.createdAt = createdAt
    }

    enum CodingKeys: String, CodingKey {
        case id, date, type, status
        case createdAt = "created_at"
    }

    public var knownStatus: Status? { Status(rawValue: status) }

    public var statusLabel: String {
        switch knownStatus {
        case .pending: return "Offen"
        case .approved: return "Genehmigt"
        case .rejected: return "Abgelehnt"
        case nil: return status
        }
    }
}

public struct VacationAccount: Codable, Hashable, Sendable {
    public var entitlement: Double
    public var used: Double
    public var planned: Double
    public var left: Double

    public init(entitlement: Double, used: Double, planned: Double, left: Double) {
        self.entitlement = entitlement
        self.used = used
        self.planned = planned
        self.left = left
    }
}

/// `GET absences/my?year`
public struct MyAbsencesResponse: Codable, Hashable, Sendable {
    public var year: Int
    public var vacation: VacationAccount
    public var sickDays: Double
    public var items: [AbsenceItem]
    public var requests: [AbsenceRequestItem]

    public init(year: Int, vacation: VacationAccount, sickDays: Double, items: [AbsenceItem], requests: [AbsenceRequestItem]) {
        self.year = year
        self.vacation = vacation
        self.sickDays = sickDays
        self.items = items
        self.requests = requests
    }

    enum CodingKeys: String, CodingKey {
        case year, vacation, items, requests
        case sickDays = "sick_days"
    }
}

/// `POST absences/requests`
public struct AbsenceRequestCreate: Codable, Hashable, Sendable {
    public var from: ISODay
    public var to: ISODay
    public var type: RequestType
    public var remarks: String?

    public init(from: ISODay, to: ISODay, type: RequestType, remarks: String? = nil) {
        self.from = from
        self.to = to
        self.type = type
        self.remarks = remarks
    }
}

public struct AbsenceRequestCreated: Codable, Hashable, Sendable {
    public var created: Int
    public var skipped: Int

    public init(created: Int, skipped: Int) {
        self.created = created
        self.skipped = skipped
    }
}

/// `GET holidays?year`
public struct HolidaysResponse: Codable, Hashable, Sendable {
    public struct Item: Codable, Hashable, Sendable {
        public var date: ISODay
        public var name: String
        public var type: String
    }

    public var items: [Item]
}

public struct NotificationItem: Codable, Hashable, Sendable, Identifiable {
    public var id: Int
    public var title: String
    public var message: String
    public var link: String?
    public var isRead: Bool
    public var createdAt: ISODateTime?

    public init(id: Int, title: String, message: String, link: String?, isRead: Bool, createdAt: ISODateTime?) {
        self.id = id
        self.title = title
        self.message = message
        self.link = link
        self.isRead = isRead
        self.createdAt = createdAt
    }

    enum CodingKeys: String, CodingKey {
        case id, title, message, link
        case isRead = "is_read"
        case createdAt = "created_at"
    }
}

/// `GET notifications?limit`
public struct NotificationsResponse: Codable, Hashable, Sendable {
    public var unread: Int
    public var items: [NotificationItem]

    public init(unread: Int, items: [NotificationItem]) {
        self.unread = unread
        self.items = items
    }
}

/// `GET sync?since`
public struct SyncResponse: Codable, Hashable, Sendable {
    public var cursor: String
    public var changedDates: [ISODay]
    public var fullResync: Bool

    public init(cursor: String, changedDates: [ISODay], fullResync: Bool) {
        self.cursor = cursor
        self.changedDates = changedDates
        self.fullResync = fullResync
    }

    enum CodingKeys: String, CodingKey {
        case cursor
        case changedDates = "changed_dates"
        case fullResync = "full_resync"
    }

    public init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        // Cursor kommt als String; Zahlen tolerieren
        if let s = try? c.decode(String.self, forKey: .cursor) {
            cursor = s
        } else {
            cursor = String(try c.decode(Int.self, forKey: .cursor))
        }
        changedDates = try c.decodeIfPresent([ISODay].self, forKey: .changedDates) ?? []
        fullResync = try c.decodeIfPresent(Bool.self, forKey: .fullResync) ?? false
    }
}

/// `POST events/ticket` – Einmal-Ticket für den Ereignisstrom
public struct EventsTicketResponse: Codable, Hashable, Sendable {
    public var ticket: String
    public var expiresIn: Int
    /// relativ zur API-Basis, z. B. `events?ticket=…`
    public var url: String

    public init(ticket: String, expiresIn: Int, url: String) {
        self.ticket = ticket
        self.expiresIn = expiresIn
        self.url = url
    }

    enum CodingKeys: String, CodingKey {
        case ticket, url
        case expiresIn = "expires_in"
    }
}

public enum DevicePlatform: String, Codable, Sendable { case ios, android, web }
public enum PushProvider: String, Codable, Sendable { case fcm, apns, ntfy }

/// `POST devices`
public struct DeviceRegistration: Codable, Hashable, Sendable {
    public var platform: DevicePlatform
    public var provider: PushProvider
    public var token: String
    public var appVersion: String

    public init(platform: DevicePlatform = .ios, provider: PushProvider = .apns, token: String, appVersion: String) {
        self.platform = platform
        self.provider = provider
        self.token = token
        self.appVersion = appVersion
    }

    enum CodingKeys: String, CodingKey {
        case platform, provider, token
        case appVersion = "app_version"
    }
}

public struct DeviceRegistered: Codable, Hashable, Sendable {
    public var id: Int
}

/// `GET calendar` / `POST calendar` – persönliches Kalender-Abo (iCal), Ergänzung zu API v1.
public struct CalendarSubscription: Codable, Hashable, Sendable {
    public var enabled: Bool
    public var url: String?

    public init(enabled: Bool, url: String?) {
        self.enabled = enabled
        self.url = url
    }

    /// `webcal://`-Variante für Apple Kalender (Abo statt Download).
    public var webcalURL: URL? {
        guard let url, var c = URLComponents(string: url) else { return nil }
        c.scheme = "webcal"
        return c.url
    }
}

/// Push-Nutzlast (data-Teil) laut Vertrag.
public struct PlanChangedPush: Hashable, Sendable {
    public var dates: [ISODay]

    public init(dates: [ISODay]) {
        self.dates = dates
    }

    /// Liest `data.type == "plan_changed"` und `data.dates` (kommagetrennt) aus einer APNs-Nutzlast.
    public init?(userInfo: [AnyHashable: Any]) {
        let data = (userInfo["data"] as? [AnyHashable: Any]) ?? userInfo
        guard (data["type"] as? String) == "plan_changed" else { return nil }
        let raw = (data["dates"] as? String) ?? ""
        dates = raw.split(separator: ",").map { $0.trimmingCharacters(in: .whitespaces) }.filter(ISOCalendar.isValid)
    }
}
