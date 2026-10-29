import Foundation

/// Vertraulichkeitsregeln der App (docs/PERMISSIONS.md, Abschnitt 1.4).
///
/// Der Server maskiert bereits: Abwesenheitsgründe anderer (Krankheit/Urlaub) kommen
/// nur als „A“ mit `confidential: true`. Die App setzt zusätzlich durch:
/// 1. Vertrauliche Zellen werden **nie** mit Art, Bemerkung oder Link dargestellt –
///    auch wenn ein fehlerhafter Server mehr liefern würde.
/// 2. Abwesenheiten **anderer** werden in der App immer neutral angezeigt (kein Grund),
///    sobald sie als vertraulich markiert sind oder ihre Art vertraulich ist.
/// 3. Flächen außerhalb der App (Widgets, Sperrbildschirm, Live-Aktivität, Siri, Spotlight,
///    Kalender) zeigen **nur eigene** Daten und auch eigene Abwesenheiten nur als
///    „Abwesend“ – ohne Art und ohne Bemerkung (der Sperrbildschirm ist für Dritte sichtbar).
public enum Confidentiality {
    /// Neutrales Kürzel und Bezeichnung für Abwesenheiten.
    public static let neutralCode = "A"
    public static let neutralLabel = "Abwesend"
    public static let neutralColor = "#64748B"

    /// Abwesenheitsarten, deren Grund vertraulich ist (`krank_*`, `urlaub_*`, sowie die
    /// Kurzformen der Demo-Daten).
    public static func isConfidentialType(_ type: String?) -> Bool {
        guard let t = type?.lowercased(), !t.isEmpty else { return true }
        return t.hasPrefix("krank") || t.hasPrefix("urlaub") || t == "vertraulich"
    }

    /// Darstellung einer Zelle der Teamübersicht.
    public struct CellDisplay: Hashable, Sendable {
        public var code: String
        public var color: String
        public var fg: String
        public var accessibilityLabel: String
        /// Details (Einsatzdetails) dürfen geöffnet werden
        public var opensDetails: Bool
    }

    public static func display(_ cell: TeamCell, isMe: Bool, userName: String, date: ISODay) -> CellDisplay {
        let day = PQFormat.date(date)
        if cell.kind == .absence && (cell.confidential || (!isMe && cell.code.uppercased() == neutralCode)) {
            // Vertrauliche Abwesenheit: nur „A“, keine Details, kein Link
            return CellDisplay(code: neutralCode, color: cell.color, fg: cell.fg,
                               accessibilityLabel: "\(userName), \(day): abwesend", opensDetails: false)
        }
        let kind = cell.kind == .assignment ? "Einsatz" : "Abwesenheit"
        return CellDisplay(code: cell.code, color: cell.color, fg: cell.fg,
                           accessibilityLabel: "\(userName), \(day): \(kind) \(cell.code)", opensDetails: cell.kind == .assignment)
    }

    /// Eintrag in Einsatzdetails einer anderen Person: Abwesenheiten ohne Grund und Bemerkung.
    public static func sanitizeForeignEntry(_ e: PlanEntry?, isMe: Bool) -> PlanEntry? {
        guard let e else { return nil }
        if isMe || e.kind == .assignment { return e }
        if e.confidential || isConfidentialType(e.type) {
            return PlanEntry(kind: .absence, code: neutralCode, label: neutralLabel, color: e.color, fg: e.fg, remarks: nil, confidential: true)
        }
        return PlanEntry(kind: .absence, type: e.type, code: e.code, label: e.label, color: e.color, fg: e.fg, remarks: nil)
    }

    /// Eintrag für Flächen außerhalb der App (Widget, Sperrbildschirm, Siri, Kalender):
    /// Einsätze ohne Bemerkung, Abwesenheiten nur neutral.
    public static func publicEntry(_ e: PlanEntry?) -> PublicEntry? {
        guard let e else { return nil }
        switch e.kind {
        case .assignment:
            return PublicEntry(kind: .assignment, code: e.code, label: e.label, color: e.color, fg: ColorContrast.safeForeground(bg: e.color, fg: e.fg))
        case .absence:
            return PublicEntry(kind: .absence, code: neutralCode, label: neutralLabel, color: neutralColor, fg: "#ffffff")
        }
    }
}

/// Eintrag, der außerhalb der App erscheinen darf (keine Bemerkungen, keine Abwesenheitsarten).
public struct PublicEntry: Codable, Hashable, Sendable {
    public var kind: PlanEntry.Kind
    public var code: String
    public var label: String
    public var color: String
    public var fg: String

    public init(kind: PlanEntry.Kind, code: String, label: String, color: String, fg: String) {
        self.kind = kind
        self.code = code
        self.label = label
        self.color = color
        self.fg = fg
    }
}
