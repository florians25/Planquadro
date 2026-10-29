import SwiftUI
import WidgetKit
import PlanquadroKit

@main
struct PlanquadroWidgetBundle: WidgetBundle {
    var body: some Widget {
        TodayWidget()
        WeekWidget()
        LockScreenWidget()
        #if canImport(ActivityKit) && !PQ_NO_LIVE_ACTIVITIES
        PlanquadroLiveActivity()
        #endif
    }
}

// MARK: - Zeitleiste

struct PlanTimelineEntry: TimelineEntry {
    let date: Date
    let snapshot: PlanSnapshot?

    var day: ISODay { ISOCalendar.today(now: date) }
}

/// Liest den Auszug aus der App Group. Einträge zu jedem Tageswechsel, damit „Heute“
/// ohne App-Start weiterspringt; die App lädt die Zeitleiste nach jedem Sync neu.
struct PlanProvider: TimelineProvider {
    func placeholder(in context: Context) -> PlanTimelineEntry {
        PlanTimelineEntry(date: Date(), snapshot: Self.sample)
    }

    func getSnapshot(in context: Context, completion: @escaping (PlanTimelineEntry) -> Void) {
        let s = AppGroup.snapshotStore.load()
        completion(PlanTimelineEntry(date: Date(), snapshot: s ?? (context.isPreview ? Self.sample : nil)))
    }

    func getTimeline(in context: Context, completion: @escaping (Timeline<PlanTimelineEntry>) -> Void) {
        let snapshot = AppGroup.snapshotStore.load()
        let cal = Calendar.current
        let now = Date()
        var entries = [PlanTimelineEntry(date: now, snapshot: snapshot)]
        var next = cal.startOfDay(for: now)
        for _ in 0..<7 {
            guard let d = cal.date(byAdding: .day, value: 1, to: next) else { break }
            next = d
            entries.append(PlanTimelineEntry(date: d, snapshot: snapshot))
        }
        // Spätestens nach einem Tag neu anfragen (die App aktualisiert ohnehin bei jedem Sync)
        let refresh = cal.date(byAdding: .hour, value: 24, to: now) ?? now.addingTimeInterval(86_400)
        completion(Timeline(entries: entries, policy: .after(refresh)))
    }

    /// Beispieldaten für die Widget-Galerie (erfunden).
    static var sample: PlanSnapshot {
        let today = ISOCalendar.today()
        let codes: [(String, String, String, String?)] = [
            ("K1", "Kunde Nord", "#dc2626", "Köln Nord"), ("K2", "Klinikum Süd", "#2563eb", nil), ("BÜ", "Büro", "#facc15", "Zentrale"),
            ("K1", "Kunde Nord", "#dc2626", "Köln Nord"), ("HO", "Homeoffice", "#a3e635", nil),
        ]
        let days = (0..<21).map { i -> PlanSnapshot.Day in
            let d = ISOCalendar.addDays(today, i)
            let wd = ISOCalendar.weekday(d) ?? 1
            guard wd <= 5 else { return .init(date: d, weekday: wd) }
            let c = codes[(wd - 1) % codes.count]
            return .init(date: d, weekday: wd, entry: PublicEntry(kind: .assignment, code: c.0, label: c.1, color: c.2, fg: ColorContrast.readableForeground(on: c.2)), place: c.3)
        }
        return PlanSnapshot(generatedAt: Date(), firstName: "Max", tenantName: "Planquadro Demo GmbH", primaryColor: BrandColors.quadroBlueHex,
                            accentColor: BrandColors.signalOrangeHex, demo: true, days: days)
    }
}

// MARK: - Widgets

struct TodayWidget: Widget {
    var body: some WidgetConfiguration {
        StaticConfiguration(kind: AppGroup.todayWidgetKind, provider: PlanProvider()) { entry in
            TodayWidgetView(snapshot: entry.snapshot, date: entry.day)
                .widgetURL(DeepLink.day(entry.day, userID: nil).url)
                .pqWidgetBackground()
        }
        .configurationDisplayName("Heute")
        .description("Ihr Einsatz heute – Kürzel, Bezeichnung und Ort.")
        .supportedFamilies([.systemSmall])
    }
}

struct WeekWidget: Widget {
    var body: some WidgetConfiguration {
        StaticConfiguration(kind: AppGroup.weekWidgetKind, provider: PlanProvider()) { entry in
            WeekWidgetView(snapshot: entry.snapshot, date: entry.day)
                .widgetURL(DeepLink.plan(YearMonth(key: entry.day)).url)
                .pqWidgetBackground()
        }
        .configurationDisplayName("Diese Woche")
        .description("Ihre Einsätze von Montag bis Sonntag auf einen Blick.")
        .supportedFamilies([.systemMedium])
    }
}

struct LockScreenWidget: Widget {
    var body: some WidgetConfiguration {
        StaticConfiguration(kind: AppGroup.lockWidgetKind, provider: PlanProvider()) { entry in
            LockScreenEntryView(entry: entry)
                .widgetURL(DeepLink.day(entry.day, userID: nil).url)
                .pqWidgetBackground(accessory: true)
        }
        .configurationDisplayName("Einsatz")
        .description("Ihr heutiger Einsatz auf dem Sperrbildschirm. Abwesenheiten erscheinen nur als „Abwesend“.")
        .supportedFamilies([.accessoryRectangular, .accessoryInline, .accessoryCircular])
    }
}

struct LockScreenEntryView: View {
    let entry: PlanTimelineEntry
    @Environment(\.widgetFamily) private var family

    var body: some View {
        switch family {
        case .accessoryInline:
            Label(lockInlineText(entry.snapshot, date: entry.day), systemImage: "calendar")
        case .accessoryCircular:
            LockCircularView(snapshot: entry.snapshot, date: entry.day)
        default:
            LockRectangularView(snapshot: entry.snapshot, date: entry.day)
        }
    }
}

extension View {
    /// `containerBackground` (iOS 17): im getönten/klaren Modus (iOS 18/26) entfernt das
    /// System den Hintergrund automatisch, das Widget bleibt Liquid-Glass-tauglich.
    @ViewBuilder
    func pqWidgetBackground(accessory: Bool = false) -> some View {
        if accessory {
            containerBackground(for: .widget) { Color.clear }
        } else {
            containerBackground(for: .widget) {
                LinearGradient(colors: [Color("WidgetBackground"), Color("WidgetBackground").opacity(0.92)], startPoint: .top, endPoint: .bottom)
            }
        }
    }
}
