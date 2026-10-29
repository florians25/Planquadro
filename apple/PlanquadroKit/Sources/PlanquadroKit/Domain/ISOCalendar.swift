import Foundation

/// Ganzzahl mit führenden Nullen (ohne `String(format:)`, das unter Linux bei `Int` heikel ist).
func pad(_ n: Int, _ width: Int = 2) -> String {
    let s = String(abs(n))
    let padded = s.count >= width ? s : String(repeating: "0", count: width - s.count) + s
    return n < 0 ? "-" + padded : padded
}

/// Jahr + Monat (1–12).
public struct YearMonth: Hashable, Comparable, Codable, Sendable, CustomStringConvertible {
    public var year: Int
    public var month: Int

    public init(year: Int, month: Int) {
        self.year = year
        self.month = month
    }

    /// Aus `YYYY-MM` oder `YYYY-MM-DD`.
    public init?(key: String) {
        let parts = key.split(separator: "-")
        guard parts.count >= 2, parts[0].count == 4, parts[1].count == 2,
              let y = Int(parts[0]), let m = Int(parts[1]), (1...12).contains(m) else { return nil }
        if parts.count == 2 {
            self.init(year: y, month: m)
        } else {
            guard ISOCalendar.isValid(key) else { return nil }
            self.init(year: y, month: m)
        }
    }

    /// `2026-11`
    public var key: String { "\(pad(year, 4))-\(pad(month))" }
    public var description: String { key }

    public func adding(months delta: Int) -> YearMonth {
        let idx = year * 12 + (month - 1) + delta
        let y = Int((Double(idx) / 12).rounded(.down))
        let m = ((idx % 12) + 12) % 12 + 1
        return YearMonth(year: y, month: m)
    }

    public var dayCount: Int { ISOCalendar.daysInMonth(year: year, month: month) }
    public var first: ISODay { "\(pad(year, 4))-\(pad(month))-01" }
    public var last: ISODay { "\(pad(year, 4))-\(pad(month))-\(pad(dayCount))" }
    public var days: [ISODay] { ISOCalendar.eachDay(from: first, to: last) }

    public static func < (a: YearMonth, b: YearMonth) -> Bool { (a.year, a.month) < (b.year, b.month) }
}

/// ISO-Kalenderwoche.
public struct ISOWeek: Hashable, Sendable {
    public var year: Int
    public var week: Int
}

/// Datumshilfen für `Y-m-d`-Strings. Gerechnet wird im gregorianischen Kalender in UTC,
/// damit Sommer-/Winterzeit keine Tage verschiebt (wie `mobile/src/lib/dates.ts`).
public enum ISOCalendar {
    static let utc: Calendar = {
        var c = Calendar(identifier: .gregorian)
        c.timeZone = TimeZone(identifier: "UTC")!
        c.firstWeekday = 2
        c.minimumDaysInFirstWeek = 4
        return c
    }()

    public static func isValid(_ s: String) -> Bool {
        guard s.count == 10, let d = date(s) else { return false }
        return string(d) == s
    }

    /// `Y-m-d` → Date (UTC-Mitternacht)
    public static func date(_ s: String) -> Date? {
        let parts = s.split(separator: "-", omittingEmptySubsequences: false)
        guard parts.count == 3, parts[0].count == 4, parts[1].count == 2, parts[2].count == 2,
              let y = Int(parts[0]), let m = Int(parts[1]), let d = Int(parts[2]),
              (1...12).contains(m), (1...31).contains(d) else { return nil }
        return utc.date(from: DateComponents(year: y, month: m, day: d))
    }

    /// Date (UTC) → `Y-m-d`
    public static func string(_ d: Date) -> String {
        let c = utc.dateComponents([.year, .month, .day], from: d)
        return "\(pad(c.year ?? 0, 4))-\(pad(c.month ?? 0))-\(pad(c.day ?? 0))"
    }

    /// Heutiges Datum in der Gerätezeitzone als `Y-m-d`.
    public static func today(now: Date = Date(), timeZone: TimeZone = .current) -> ISODay {
        var cal = Calendar(identifier: .gregorian)
        cal.timeZone = timeZone
        let c = cal.dateComponents([.year, .month, .day], from: now)
        return "\(pad(c.year ?? 0, 4))-\(pad(c.month ?? 0))-\(pad(c.day ?? 0))"
    }

    public static func addDays(_ s: ISODay, _ n: Int) -> ISODay {
        guard let d = date(s), let r = utc.date(byAdding: .day, value: n, to: d) else { return s }
        return string(r)
    }

    /// Anzahl Tage von `a` nach `b` (b − a).
    public static func daysBetween(_ a: ISODay, _ b: ISODay) -> Int? {
        guard let da = date(a), let db = date(b) else { return nil }
        return utc.dateComponents([.day], from: da, to: db).day
    }

    /// ISO-Wochentag: 1 = Montag … 7 = Sonntag (wie `weekday` der API).
    public static func weekday(_ s: ISODay) -> Int? {
        guard let d = date(s) else { return nil }
        let wd = utc.component(.weekday, from: d) // 1 = Sonntag
        return wd == 1 ? 7 : wd - 1
    }

    public static func isoWeek(_ s: ISODay) -> ISOWeek? {
        guard let d = date(s) else { return nil }
        let c = utc.dateComponents([.weekOfYear, .yearForWeekOfYear], from: d)
        guard let w = c.weekOfYear, let y = c.yearForWeekOfYear else { return nil }
        return ISOWeek(year: y, week: w)
    }

    /// Montag der Woche, in der `s` liegt.
    public static func startOfWeek(_ s: ISODay) -> ISODay {
        guard let wd = weekday(s) else { return s }
        return addDays(s, 1 - wd)
    }

    public static func daysInMonth(year: Int, month: Int) -> Int {
        guard let d = utc.date(from: DateComponents(year: year, month: month, day: 1)),
              let r = utc.range(of: .day, in: .month, for: d) else { return 30 }
        return r.count
    }

    /// Alle Tage von `from` bis `to` (inklusive); leer bei ungültigem Zeitraum.
    public static func eachDay(from: ISODay, to: ISODay) -> [ISODay] {
        guard isValid(from), isValid(to), from <= to else { return [] }
        var out: [ISODay] = []
        var d = from
        while d <= to {
            out.append(d)
            d = addDays(d, 1)
            if out.count > 3700 { break }
        }
        return out
    }

    /// Monate (`YYYY-MM`) der angegebenen Tage – sortiert, eindeutig.
    public static func months(of dates: [ISODay]) -> [String] {
        Array(Set(dates.filter(isValid).map { String($0.prefix(7)) })).sorted()
    }

    /// Die sieben Tage (Mo–So) der Woche, in der `s` liegt.
    public static func week(containing s: ISODay) -> [ISODay] {
        let start = startOfWeek(s)
        return (0..<7).map { addDays(start, $0) }
    }
}

/// Gruppe von Tagen einer ISO-Kalenderwoche.
public struct WeekGroup<T: Sendable>: Sendable, Identifiable {
    /// z. B. „KW 45“
    public var label: String
    public var year: Int
    public var week: Int
    /// Montag der Woche
    public var start: ISODay
    public var items: [T]

    public var id: String { "\(year)-\(week)" }
}

extension Array where Element == PlanDay {
    /// Gruppiert Tage nach ISO-Kalenderwoche (aufsteigend sortiert).
    public func groupedByISOWeek() -> [WeekGroup<PlanDay>] {
        var groups: [WeekGroup<PlanDay>] = []
        for day in sorted(by: { $0.date < $1.date }) {
            guard let w = ISOCalendar.isoWeek(day.date) else { continue }
            if let last = groups.last, last.year == w.year, last.week == w.week {
                groups[groups.count - 1].items.append(day)
            } else {
                groups.append(WeekGroup(label: "KW \(w.week)", year: w.year, week: w.week, start: ISOCalendar.startOfWeek(day.date), items: [day]))
            }
        }
        return groups
    }
}
