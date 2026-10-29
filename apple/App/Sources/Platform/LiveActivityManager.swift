import Foundation
import PlanquadroKit
#if canImport(ActivityKit) && !PQ_NO_LIVE_ACTIVITIES
import ActivityKit
#endif

/// Live-Aktivität für den heutigen Einsatz (optional, vom Nutzer gestartet oder in den
/// Einstellungen automatisch beim Öffnen der App). Läuft bis Tagesende.
@MainActor
final class LiveActivityManager {
    var isSupported: Bool {
        #if canImport(ActivityKit) && !PQ_NO_LIVE_ACTIVITIES
        return ActivityAuthorizationInfo().areActivitiesEnabled
        #else
        return false
        #endif
    }

    var isRunning: Bool {
        #if canImport(ActivityKit) && !PQ_NO_LIVE_ACTIVITIES
        return !Activity<PlanquadroActivityAttributes>.activities.isEmpty
        #else
        return false
        #endif
    }

    /// Startet bzw. aktualisiert die Aktivität für `day` (nur Einsätze).
    @discardableResult
    func startOrUpdate(day: PlanSnapshot.Day, tenantName: String, headline: String = "Heute im Einsatz") async -> Bool {
        #if canImport(ActivityKit) && !PQ_NO_LIVE_ACTIVITIES
        guard let e = day.entry, e.kind == .assignment, ActivityAuthorizationInfo().areActivitiesEnabled else { return false }
        let state = PlanquadroActivityAttributes.ContentState(headline: headline, code: e.code, label: e.label, color: e.color, fg: e.fg,
                                                              place: day.place, updatedAt: Date())
        let stale = CalendarExporter.localDate(ISOCalendar.addDays(day.date, 1))
        let content = ActivityContent(state: state, staleDate: stale)
        if let existing = Activity<PlanquadroActivityAttributes>.activities.first(where: { $0.attributes.date == day.date }) {
            await existing.update(content)
            return true
        }
        await endAll()
        do {
            _ = try Activity.request(attributes: PlanquadroActivityAttributes(date: day.date, tenantName: tenantName), content: content, pushType: nil)
            return true
        } catch {
            return false
        }
        #else
        return false
        #endif
    }

    /// Aktualisiert eine laufende Aktivität nach einem Sync (oder beendet sie, wenn der Einsatz entfällt).
    func refresh(from snapshot: PlanSnapshot?, today: ISODay, tenantName: String) async {
        #if canImport(ActivityKit) && !PQ_NO_LIVE_ACTIVITIES
        guard isRunning else { return }
        guard let day = snapshot?.day(today), day.entry?.kind == .assignment else {
            await endAll()
            return
        }
        await startOrUpdate(day: day, tenantName: tenantName, headline: "Heute im Einsatz")
        #endif
    }

    func endAll() async {
        #if canImport(ActivityKit) && !PQ_NO_LIVE_ACTIVITIES
        for a in Activity<PlanquadroActivityAttributes>.activities {
            await a.end(nil, dismissalPolicy: .immediate)
        }
        #endif
    }
}
