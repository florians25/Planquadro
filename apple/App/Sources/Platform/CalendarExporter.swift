import EventKit
import EventKitUI
import SwiftUI
import PlanquadroKit

/// Übernimmt eigene Einsätze als ganztägige Termine in den Kalender (EventKit).
///
/// - Nur nach ausdrücklicher Rückfrage und mit „Nur Schreiben“-Zugriff (iOS 17).
/// - Nur **Einsätze**, keine Abwesenheiten (Kalender werden oft geteilt/synchronisiert).
/// - Bereits übernommene Tage merkt sich die App (UserDefaults) und überspringt sie.
@MainActor
final class CalendarExporter {
    private let store = EKEventStore()
    private let defaultsKey = "pq.calendar.exported.v1"

    enum ExportError: LocalizedError {
        case denied

        var errorDescription: String? {
            "Planquadro hat keinen Zugriff auf Ihren Kalender. Sie können ihn in den Einstellungen unter Datenschutz → Kalender erlauben."
        }
    }

    private var exported: [String: String] {
        get { UserDefaults.standard.dictionary(forKey: defaultsKey) as? [String: String] ?? [:] }
        set { UserDefaults.standard.set(newValue, forKey: defaultsKey) }
    }

    func requestAccess() async throws {
        let granted = try await store.requestWriteOnlyAccessToEvents()
        guard granted else { throw ExportError.denied }
    }

    /// Termine für die Einsätze der Tage anlegen; liefert Anzahl neu/übersprungen.
    func export(days: [PlanDay], tenantName: String, places: [ISODay: String] = [:]) async throws -> (added: Int, skipped: Int) {
        try await requestAccess()
        var map = exported
        var added = 0, skipped = 0
        for day in days {
            guard let e = day.entry, e.kind == .assignment else { continue }
            let key = "\(day.date)|\(e.code)"
            if map[key] != nil {
                skipped += 1
                continue
            }
            let ev = makeEvent(date: day.date, code: e.code, label: e.label, place: places[day.date], tenantName: tenantName)
            ev.calendar = store.defaultCalendarForNewEvents
            try store.save(ev, span: .thisEvent, commit: false)
            map[key] = ev.eventIdentifier ?? "1"
            added += 1
        }
        try store.commit()
        exported = map
        return (added, skipped)
    }

    func makeEvent(date: ISODay, code: String, label: String, place: String?, tenantName: String) -> EKEvent {
        let ev = EKEvent(eventStore: store)
        ev.title = "\(code) · \(label)"
        ev.isAllDay = true
        let start = Self.localDate(date)
        ev.startDate = start
        ev.endDate = start
        ev.location = place
        ev.notes = "Einsatz aus Planquadro (\(tenantName))."
        ev.url = DeepLink.day(date, userID: nil).url
        return ev
    }

    /// Mitternacht des Tages in der Gerätezeitzone.
    static func localDate(_ day: ISODay) -> Date {
        var cal = Calendar(identifier: .gregorian)
        cal.timeZone = .current
        let parts = day.split(separator: "-").compactMap { Int($0) }
        return cal.date(from: DateComponents(year: parts.first, month: parts.count > 1 ? parts[1] : 1, day: parts.count > 2 ? parts[2] : 1)) ?? Date()
    }

    func forgetExports() {
        UserDefaults.standard.removeObject(forKey: defaultsKey)
    }

    var eventStore: EKEventStore { store }
}

/// Einzelner Termin mit System-Dialog (kein Kalenderzugriff nötig; der Nutzer bestätigt selbst).
struct EventEditView: UIViewControllerRepresentable {
    let event: EKEvent
    let store: EKEventStore
    var onDone: (Bool) -> Void

    func makeUIViewController(context: Context) -> EKEventEditViewController {
        let vc = EKEventEditViewController()
        vc.eventStore = store
        vc.event = event
        vc.editViewDelegate = context.coordinator
        return vc
    }

    func updateUIViewController(_ uiViewController: EKEventEditViewController, context: Context) {}

    func makeCoordinator() -> Coordinator { Coordinator(onDone: onDone) }

    final class Coordinator: NSObject, EKEventEditViewDelegate {
        let onDone: (Bool) -> Void

        init(onDone: @escaping (Bool) -> Void) {
            self.onDone = onDone
        }

        func eventEditViewController(_ controller: EKEventEditViewController, didCompleteWith action: EKEventEditViewAction) {
            onDone(action == .saved)
        }
    }
}
