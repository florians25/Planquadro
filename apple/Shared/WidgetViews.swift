import SwiftUI
import WidgetKit
import PlanquadroKit

/// Inhalte der Widgets – gemeinsam für die Widget-Erweiterung und die Widget-Vorschau
/// in der App (Screenshots im CI). Zeigt ausschließlich den nicht vertraulichen
/// `PlanSnapshot` (eigene Einsätze, Abwesenheiten nur als „Abwesend“).

struct WidgetDayBadge: View {
    var entry: PublicEntry?
    var size: CodeBadge.Size = .regular
    var highlighted = false
    @Environment(\.widgetRenderingMode) private var renderingMode

    var body: some View {
        if let e = entry {
            if renderingMode == .fullColor {
                CodeBadge(code: e.code, color: e.color, fg: e.fg, size: size, highlighted: highlighted)
            } else {
                Text(e.code)
                    .font(size.font)
                    .lineLimit(1)
                    .minimumScaleFactor(0.6)
                    .frame(minWidth: size.minSide, minHeight: size.minSide)
                    .background(Color.primary.opacity(0.18), in: RoundedRectangle(cornerRadius: size.minSide * 0.24, style: .continuous))
                    .widgetAccentable()
            }
        } else {
            Text("–")
                .font(size.font)
                .foregroundStyle(.secondary)
                .frame(minWidth: size.minSide, minHeight: size.minSide)
        }
    }
}

/// Beschreibung eines Tages in Worten (für Widgets und VoiceOver).
func widgetDayText(_ day: PlanSnapshot.Day?) -> (title: String, subtitle: String?) {
    guard let day else { return ("Kein Plan", "In Planquadro öffnen") }
    if let e = day.entry {
        return e.kind == .assignment ? (e.label, day.place) : ("Abwesend", nil)
    }
    if let h = day.holiday { return (h, "Feiertag") }
    return (day.weekday >= 6 ? "Wochenende" : "Kein Einsatz", nil)
}

struct WidgetSignedOutView: View {
    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            PlanquadroMark(style: .standard).frame(width: 26, height: 26)
            Spacer(minLength: 0)
            Text("Planquadro")
                .font(.headline)
            Text("Melden Sie sich in der App an, um Ihren Plan hier zu sehen.")
                .font(.caption)
                .foregroundStyle(.secondary)
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .leading)
    }
}

/// „Heute“ (klein)
struct TodayWidgetView: View {
    var snapshot: PlanSnapshot?
    var date: ISODay

    var body: some View {
        if let snapshot {
            let day = snapshot.day(date)
            let text = widgetDayText(day)
            VStack(alignment: .leading, spacing: 4) {
                HStack(spacing: 6) {
                    PlanquadroMark(style: .standard).frame(width: 16, height: 16)
                    Text("Heute")
                        .font(.caption.weight(.semibold))
                        .foregroundStyle(Color.pqOrangeText)
                        .widgetAccentable()
                    Spacer(minLength: 0)
                    Text(PQFormat.weekdayShort(date) + " " + PQFormat.dayMonth(date))
                        .font(.caption2)
                        .foregroundStyle(.secondary)
                }
                Spacer(minLength: 0)
                WidgetDayBadge(entry: day?.entry, size: .large)
                Text(text.title)
                    .font(.headline)
                    .lineLimit(2)
                    .minimumScaleFactor(0.8)
                if let sub = text.subtitle {
                    Label(sub, systemImage: day?.entry?.kind == .assignment ? "mappin.and.ellipse" : "calendar")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                        .lineLimit(1)
                }
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topLeading)
            .accessibilityElement(children: .ignore)
            .accessibilityLabel(snapshot.spokenSummary(for: date, today: date))
        } else {
            WidgetSignedOutView()
        }
    }
}

/// „Diese Woche“ (mittel)
struct WeekWidgetView: View {
    var snapshot: PlanSnapshot?
    var date: ISODay

    var body: some View {
        if let snapshot {
            let days = ISOCalendar.week(containing: date)
            VStack(alignment: .leading, spacing: 8) {
                HStack {
                    PlanquadroMark(style: .standard).frame(width: 16, height: 16)
                    Text("Diese Woche")
                        .font(.caption.weight(.semibold))
                    if let w = ISOCalendar.isoWeek(date) {
                        Text("· KW \(w.week)")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                    Spacer(minLength: 0)
                    Text(snapshot.tenantName)
                        .font(.caption2)
                        .foregroundStyle(.secondary)
                        .lineLimit(1)
                }
                HStack(spacing: 4) {
                    ForEach(days, id: \.self) { d in
                        let day = snapshot.day(d)
                        let isToday = d == date
                        Link(destination: DeepLink.day(d, userID: nil).url) {
                            VStack(spacing: 4) {
                                Text(PQFormat.weekdayShort(d))
                                    .font(.caption2.weight(isToday ? .bold : .regular))
                                    .foregroundStyle(isToday ? Color.pqOrangeText : .secondary)
                                Text(String(d.suffix(2)))
                                    .font(.caption.monospacedDigit().weight(isToday ? .bold : .regular))
                                WidgetDayBadge(entry: day?.entry, size: .cell, highlighted: isToday)
                            }
                            .frame(maxWidth: .infinity)
                            .padding(.vertical, 4)
                            .background {
                                if isToday {
                                    RoundedRectangle(cornerRadius: 10, style: .continuous)
                                        .fill(Color.pqOrange.opacity(0.12))
                                }
                            }
                        }
                        .accessibilityLabel(PQFormat.dateLong(d) + ": " + widgetDayText(day).title)
                    }
                }
                let today = snapshot.day(date)
                let t = widgetDayText(today)
                Text("Heute: \(t.title)" + (t.subtitle.map { " · \($0)" } ?? ""))
                    .font(.caption)
                    .foregroundStyle(.secondary)
                    .lineLimit(1)
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topLeading)
        } else {
            WidgetSignedOutView()
        }
    }
}

/// Sperrbildschirm (rechteckig)
struct LockRectangularView: View {
    var snapshot: PlanSnapshot?
    var date: ISODay

    var body: some View {
        if let snapshot {
            let day = snapshot.day(date)
            let t = widgetDayText(day)
            VStack(alignment: .leading, spacing: 1) {
                Text("\(PQFormat.weekdayShort(date)) \(PQFormat.dayMonth(date))")
                    .font(.caption2.weight(.semibold))
                    .widgetAccentable()
                Text((day?.entry.map { "\($0.code) · " } ?? "") + t.title)
                    .font(.headline)
                    .lineLimit(1)
                if let s = t.subtitle {
                    Text(s).font(.caption).lineLimit(1)
                } else if let next = snapshot.nextAssignment(from: ISOCalendar.addDays(date, 1)), let e = next.entry {
                    Text("Nächster: \(PQFormat.dateShort(next.date)) \(e.code)")
                        .font(.caption)
                        .lineLimit(1)
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        } else {
            Text("Planquadro: bitte anmelden").font(.caption)
        }
    }
}

/// Sperrbildschirm (rund): Kürzel des Tages
struct LockCircularView: View {
    var snapshot: PlanSnapshot?
    var date: ISODay

    var body: some View {
        ZStack {
            AccessoryWidgetBackground()
            VStack(spacing: 0) {
                Text(PQFormat.weekdayShort(date)).font(.caption2)
                Text(snapshot?.day(date)?.entry?.code ?? "–")
                    .font(.system(.title3, design: .rounded).weight(.bold))
                    .minimumScaleFactor(0.5)
                    .widgetAccentable()
            }
        }
    }
}

/// Sperrbildschirm (eine Zeile über der Uhr)
func lockInlineText(_ snapshot: PlanSnapshot?, date: ISODay) -> String {
    guard let snapshot else { return "Planquadro" }
    let day = snapshot.day(date)
    if let e = day?.entry { return e.kind == .assignment ? "\(e.code) · \(e.label)" : "Abwesend" }
    return widgetDayText(day).title
}
