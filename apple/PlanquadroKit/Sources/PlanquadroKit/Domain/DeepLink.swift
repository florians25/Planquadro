import Foundation

/// Bereiche der App (Tabs bzw. Seitenleiste).
public enum AppSection: String, CaseIterable, Codable, Hashable, Sendable, Identifiable {
    case plan, team, absences, notifications, more

    public var id: String { rawValue }

    public var title: String {
        switch self {
        case .plan: return "Plan"
        case .team: return "Team"
        case .absences: return "Abwesenheiten"
        case .notifications: return "Mitteilungen"
        case .more: return "Mehr"
        }
    }

    /// SF Symbol
    public var symbol: String {
        switch self {
        case .plan: return "calendar"
        case .team: return "square.grid.3x3.fill"
        case .absences: return "sun.max"
        case .notifications: return "bell"
        case .more: return "ellipsis.circle"
        }
    }

    /// Sichtbar nach Rechten und Modulen (wie die Capacitor-App).
    public func isVisible(for me: MeResponse?) -> Bool {
        switch self {
        case .team: return me?.permissions.planView ?? false
        case .absences: return me?.modules.absences ?? true
        default: return true
        }
    }
}

/// Deep Links `planquadro://…` (Push, Widgets, Kurzbefehle, Spotlight, Handoff).
///
/// - `planquadro://plan[/2026-11]`
/// - `planquadro://tag/2026-11-02[/14]` – Einsatzdetails (eigene bzw. Person 14)
/// - `planquadro://team[/2026-11]`
/// - `planquadro://abwesenheiten[/2026][?antrag=1]`
/// - `planquadro://mitteilungen`, `planquadro://mehr`
/// - `planquadro://auth?…` ist der OIDC-Rücksprung (kein Ziel)
public enum DeepLink: Hashable, Sendable {
    case plan(YearMonth?)
    case day(ISODay, userID: Int?)
    case team(YearMonth?)
    case absences(year: Int?, newRequest: Bool)
    case notifications
    case more

    public static let scheme = "planquadro"

    public init?(url: URL) {
        guard url.scheme?.lowercased() == Self.scheme, let host = url.host?.lowercased() else { return nil }
        let parts = url.path.split(separator: "/").map(String.init)
        let query = URLComponents(url: url, resolvingAgainstBaseURL: false)?.queryItems ?? []
        switch host {
        case "plan":
            self = .plan(parts.first.flatMap { YearMonth(key: $0) })
        case "tag", "day":
            guard let d = parts.first, ISOCalendar.isValid(d) else { return nil }
            self = .day(d, userID: parts.count > 1 ? Int(parts[1]) : nil)
        case "team":
            self = .team(parts.first.flatMap { YearMonth(key: $0) })
        case "abwesenheiten", "absences":
            let req = query.contains { $0.name == "antrag" && ($0.value == "1" || $0.value == "true") }
            self = .absences(year: parts.first.flatMap(Int.init), newRequest: req)
        case "mitteilungen", "notifications":
            self = .notifications
        case "mehr", "more":
            self = .more
        default:
            return nil
        }
    }

    public var url: URL {
        var s = "\(Self.scheme)://"
        switch self {
        case let .plan(ym): s += "plan" + (ym.map { "/\($0.key)" } ?? "")
        case let .day(d, uid): s += "tag/\(d)" + (uid.map { "/\($0)" } ?? "")
        case let .team(ym): s += "team" + (ym.map { "/\($0.key)" } ?? "")
        case let .absences(y, req): s += "abwesenheiten" + (y.map { "/\($0)" } ?? "") + (req ? "?antrag=1" : "")
        case .notifications: s += "mitteilungen"
        case .more: s += "mehr"
        }
        return URL(string: s)!
    }

    public var section: AppSection {
        switch self {
        case .plan, .day: return .plan
        case .team: return .team
        case .absences: return .absences
        case .notifications: return .notifications
        case .more: return .more
        }
    }

    /// Ziel für das `link`-Feld einer Mitteilung (wie `routeForLink` der Capacitor-App).
    public static func forNotificationLink(_ link: String?) -> DeepLink? {
        switch link {
        case "mein_plan": return .plan(nil)
        case "abwesenheiten", "urlaub": return .absences(year: nil, newRequest: false)
        case "team", "gesamtplan": return .team(nil)
        default: return nil
        }
    }

    /// Ziel beim Antippen einer Push-Nachricht.
    public static func forPush(_ userInfo: [AnyHashable: Any]) -> DeepLink {
        if let p = PlanChangedPush(userInfo: userInfo), let first = p.dates.sorted().first {
            return .plan(YearMonth(key: first))
        }
        let data = (userInfo["data"] as? [AnyHashable: Any]) ?? userInfo
        return forNotificationLink(data["link"] as? String) ?? .plan(nil)
    }
}
