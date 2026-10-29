import Foundation

/// Deutsche Formatierung für Datum, Zeit und Zahlen – unabhängig von der Gerätesprache
/// gleich (die App ist deutschsprachig; Ziffern und Reihenfolge wie in der Weboberfläche).
public enum PQFormat {
    public static let weekdaysShort = ["Mo", "Di", "Mi", "Do", "Fr", "Sa", "So"]
    public static let weekdaysLong = ["Montag", "Dienstag", "Mittwoch", "Donnerstag", "Freitag", "Samstag", "Sonntag"]
    public static let months = ["Januar", "Februar", "März", "April", "Mai", "Juni", "Juli", "August", "September", "Oktober", "November", "Dezember"]
    public static let monthsShort = ["Jan.", "Feb.", "März", "Apr.", "Mai", "Juni", "Juli", "Aug.", "Sept.", "Okt.", "Nov.", "Dez."]

    public static func weekdayShort(_ d: ISODay) -> String {
        guard let wd = ISOCalendar.weekday(d) else { return "" }
        return weekdaysShort[wd - 1]
    }

    public static func weekdayLong(_ d: ISODay) -> String {
        guard let wd = ISOCalendar.weekday(d) else { return "" }
        return weekdaysLong[wd - 1]
    }

    public static func monthLabel(_ ym: YearMonth) -> String {
        guard (1...12).contains(ym.month) else { return ym.key }
        return "\(months[ym.month - 1]) \(ym.year)"
    }

    /// `02.11.`
    public static func dayMonth(_ d: ISODay) -> String {
        guard d.count >= 10 else { return d }
        let a = Array(d)
        return "\(String(a[8...9])).\(String(a[5...6]))."
    }

    /// `02.11.2026`
    public static func date(_ d: ISODay) -> String {
        guard d.count >= 10 else { return d }
        let a = Array(d)
        return "\(String(a[8...9])).\(String(a[5...6])).\(String(a[0...3]))"
    }

    /// `Montag, 02.11.2026`
    public static func dateLong(_ d: ISODay) -> String { "\(weekdayLong(d)), \(date(d))" }

    /// `Mo, 02.11.`
    public static func dateShort(_ d: ISODay) -> String { "\(weekdayShort(d)), \(dayMonth(d))" }

    /// `2. November`
    public static func dayAndMonthName(_ d: ISODay) -> String {
        guard let ym = YearMonth(key: d), let day = Int(d.suffix(2)) else { return d }
        return "\(day). \(months[ym.month - 1])"
    }

    /// „Heute“, „Morgen“, „Gestern“ oder `Mo, 02.11.`
    public static func relativeDay(_ d: ISODay, today: ISODay) -> String {
        switch ISOCalendar.daysBetween(today, d) {
        case 0: return "Heute"
        case 1: return "Morgen"
        case -1: return "Gestern"
        default: return dateShort(d)
        }
    }

    /// Zeitraum `02.11.2026 – 06.11.2026` bzw. ein Tag.
    public static func range(_ from: ISODay, _ to: ISODay) -> String {
        from == to ? date(from) : "\(date(from)) – \(date(to))"
    }

    nonisolated(unsafe) private static let isoParsers: [ISO8601DateFormatter] = {
        let a = ISO8601DateFormatter()
        a.formatOptions = [.withInternetDateTime]
        let b = ISO8601DateFormatter()
        b.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        return [a, b]
    }()

    private static let parseLock = NSLock()

    /// ISO 8601 mit Offset (auch mit Sekundenbruchteilen) → Date.
    public static func parseTimestamp(_ s: String?) -> Date? {
        guard let s, !s.isEmpty else { return nil }
        parseLock.lock()
        defer { parseLock.unlock() }
        for p in isoParsers {
            if let d = p.date(from: s) { return d }
        }
        return nil
    }

    /// `29.10.2026, 18:00` in Gerätezeit.
    public static func dateTime(_ value: Date, timeZone: TimeZone = .current) -> String {
        var cal = Calendar(identifier: .gregorian)
        cal.timeZone = timeZone
        let c = cal.dateComponents([.year, .month, .day, .hour, .minute], from: value)
        return "\(pad(c.day ?? 0)).\(pad(c.month ?? 0)).\(pad(c.year ?? 0, 4)), \(pad(c.hour ?? 0)):\(pad(c.minute ?? 0))"
    }

    public static func dateTime(_ iso: String?, timeZone: TimeZone = .current) -> String {
        guard let d = parseTimestamp(iso) else { return "" }
        return dateTime(d, timeZone: timeZone)
    }

    /// `18:00`
    public static func time(_ value: Date, timeZone: TimeZone = .current) -> String {
        var cal = Calendar(identifier: .gregorian)
        cal.timeZone = timeZone
        let c = cal.dateComponents([.hour, .minute], from: value)
        return "\(pad(c.hour ?? 0)):\(pad(c.minute ?? 0))"
    }

    /// Zahl mit höchstens einer Nachkommastelle, deutsches Komma: `12,5`, `30`.
    public static func number(_ n: Double) -> String {
        let r = (n * 10).rounded() / 10
        if r == r.rounded() { return String(Int(r)) }
        let s = String(format: "%.1f", r)
        return s.replacingOccurrences(of: ".", with: ",")
    }

    /// `1 Tag`, `2,5 Tage`
    public static func days(_ n: Double) -> String {
        "\(number(n)) \(n == 1 ? "Tag" : "Tage")"
    }

    /// „Offline – Stand: 29.10.2026, 18:00“
    public static func offlineStand(_ savedAt: Date) -> String {
        "Offline – Stand: \(dateTime(savedAt))"
    }
}
