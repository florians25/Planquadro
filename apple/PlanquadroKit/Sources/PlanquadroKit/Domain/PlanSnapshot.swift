import Foundation

/// Kompakter, **nicht vertraulicher** Auszug des eigenen Plans für Widgets, Sperrbildschirm,
/// Live-Aktivität und Siri/Kurzbefehle. Liegt unverschlüsselt im App-Group-Container,
/// damit die Widget-Erweiterung ihn ohne Keychain lesen kann – deshalb enthält er nur
/// eigene Einträge, keine Bemerkungen, keine Abwesenheitsarten und keine Kundendaten.
public struct PlanSnapshot: Codable, Hashable, Sendable {
    public struct Day: Codable, Hashable, Sendable, Identifiable {
        public var date: ISODay
        public var weekday: Int
        public var holiday: String?
        public var entry: PublicEntry?
        /// Ortsname des Einsatzes (ohne Adresse), falls bekannt
        public var place: String?

        public var id: ISODay { date }

        public init(date: ISODay, weekday: Int, holiday: String? = nil, entry: PublicEntry? = nil, place: String? = nil) {
            self.date = date
            self.weekday = weekday
            self.holiday = holiday
            self.entry = entry
            self.place = place
        }
    }

    public static let version = 1

    public var version: Int
    public var generatedAt: Date
    /// Vorname für die Begrüßung („Hallo Max“) – kein Nachname
    public var firstName: String?
    public var tenantName: String
    public var primaryColor: String
    public var accentColor: String
    public var demo: Bool
    public var days: [Day]

    public init(generatedAt: Date, firstName: String?, tenantName: String, primaryColor: String, accentColor: String, demo: Bool, days: [Day]) {
        version = Self.version
        self.generatedAt = generatedAt
        self.firstName = firstName
        self.tenantName = tenantName
        self.primaryColor = primaryColor
        self.accentColor = accentColor
        self.demo = demo
        self.days = days
    }

    /// Baut den Auszug aus geladenen Monatsplänen (Zeitraum `from` … `from + dayCount`).
    public static func make(plans: [MyPlanResponse], me: MeResponse?, tenantName: String, branding: Branding,
                            from today: ISODay, dayCount: Int = 21, places: [ISODay: String] = [:],
                            demo: Bool = false, now: Date = Date()) -> PlanSnapshot {
        var byDate: [ISODay: PlanDay] = [:]
        for p in plans { for d in p.filledDays { byDate[d.date] = d } }
        let days = (0..<dayCount).map { ISOCalendar.addDays(today, $0) }.map { date -> Day in
            let d = byDate[date]
            let entry = Confidentiality.publicEntry(d?.entry)
            return Day(date: date, weekday: d?.weekday ?? ISOCalendar.weekday(date) ?? 1, holiday: d?.holiday?.name,
                       entry: entry, place: entry?.kind == .assignment ? places[date] : nil)
        }
        return PlanSnapshot(generatedAt: now, firstName: me?.user.firstName, tenantName: tenantName,
                            primaryColor: branding.primary, accentColor: branding.accent, demo: demo, days: days)
    }

    public func day(_ date: ISODay) -> Day? { days.first { $0.date == date } }

    /// Tage ab `date` (inklusive).
    public func days(from date: ISODay, count: Int) -> [Day] {
        Array(days.filter { $0.date >= date }.prefix(count))
    }

    /// Nächster Tag mit Einsatz ab `date` (inklusive).
    public func nextAssignment(from date: ISODay) -> Day? {
        days.first { $0.date >= date && $0.entry?.kind == .assignment }
    }

    /// Antwort für „Wo bin ich morgen eingesetzt?“ (Siri/Kurzbefehle).
    public func spokenSummary(for date: ISODay, today: ISODay) -> String {
        let when = PQFormat.relativeDay(date, today: today)
        let dayText = when == "Heute" || when == "Morgen" ? "\(when) (\(PQFormat.dateShort(date)))" : PQFormat.dateLong(date)
        guard let d = day(date) else {
            return "Für \(PQFormat.dateLong(date)) liegt noch kein Plan vor. Öffnen Sie Planquadro, um den Plan zu laden."
        }
        if let e = d.entry {
            switch e.kind {
            case .assignment:
                let place = d.place.map { " in \($0)" } ?? ""
                return "\(dayText): \(e.label) (\(e.code))\(place)."
            case .absence:
                return "\(dayText) sind Sie abwesend."
            }
        }
        if let h = d.holiday { return "\(dayText) ist \(h) – kein Einsatz geplant." }
        if d.weekday >= 6 { return "\(dayText) ist Wochenende – kein Einsatz geplant." }
        return "\(dayText) ist kein Einsatz geplant."
    }
}

/// Liest/schreibt den Auszug als JSON-Datei (App-Group-Container).
public struct PlanSnapshotStore: Sendable {
    public static let fileName = "plan-snapshot.json"
    public let directory: URL

    public init(directory: URL) {
        self.directory = directory
    }

    public var fileURL: URL { directory.appendingPathComponent(Self.fileName) }

    public func load() -> PlanSnapshot? {
        guard let data = try? Data(contentsOf: fileURL) else { return nil }
        let dec = JSONDecoder()
        dec.dateDecodingStrategy = .secondsSince1970
        guard let s = try? dec.decode(PlanSnapshot.self, from: data), s.version == PlanSnapshot.version else { return nil }
        return s
    }

    public func save(_ snapshot: PlanSnapshot) throws {
        let enc = JSONEncoder()
        enc.dateEncodingStrategy = .secondsSince1970
        enc.outputFormatting = [.sortedKeys]
        let data = try enc.encode(snapshot)
        try FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)
        #if os(iOS)
        try data.write(to: fileURL, options: [.atomic, .completeFileProtectionUntilFirstUserAuthentication])
        #else
        try data.write(to: fileURL, options: [.atomic])
        #endif
    }

    public func remove() {
        try? FileManager.default.removeItem(at: fileURL)
    }
}
