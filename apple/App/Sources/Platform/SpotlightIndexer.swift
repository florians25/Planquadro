import CoreSpotlight
import Foundation
import UniformTypeIdentifiers
import PlanquadroKit

/// Optional (Mehr → Einstellungen): eigene **Einsätze** in der Spotlight-Suche des Geräts.
/// Keine Abwesenheiten, keine Bemerkungen, keine Kundendaten. Beim Abmelden/Ausschalten
/// wird der Index gelöscht.
@MainActor
final class SpotlightIndexer {
    static let domain = "de.planquadro.app.assignments"
    static let idPrefix = "day:"

    func index(_ snapshot: PlanSnapshot) {
        let items: [CSSearchableItem] = snapshot.days.compactMap { day in
            guard let e = day.entry, e.kind == .assignment else { return nil }
            let attrs = CSSearchableItemAttributeSet(contentType: .text)
            attrs.title = "\(e.code) · \(e.label)"
            attrs.contentDescription = PQFormat.dateLong(day.date) + (day.place.map { " · \($0)" } ?? "")
            attrs.keywords = ["Einsatz", "Planquadro", e.code, e.label, PQFormat.weekdayLong(day.date)]
            attrs.startDate = CalendarExporter.localDate(day.date)
            attrs.endDate = attrs.startDate
            let item = CSSearchableItem(uniqueIdentifier: Self.idPrefix + day.date, domainIdentifier: Self.domain, attributeSet: attrs)
            item.expirationDate = CalendarExporter.localDate(ISOCalendar.addDays(day.date, 1))
            return item
        }
        let index = CSSearchableIndex.default()
        index.deleteSearchableItems(withDomainIdentifiers: [Self.domain]) { _ in
            index.indexSearchableItems(items) { _ in }
        }
    }

    func clear() {
        CSSearchableIndex.default().deleteSearchableItems(withDomainIdentifiers: [Self.domain]) { _ in }
    }

    /// Ziel eines angetippten Suchtreffers.
    static func link(for activity: NSUserActivity) -> DeepLink? {
        guard let id = activity.userInfo?[CSSearchableItemActivityIdentifier] as? String, id.hasPrefix(idPrefix) else { return nil }
        let date = String(id.dropFirst(idPrefix.count))
        return ISOCalendar.isValid(date) ? .day(date, userID: nil) : nil
    }
}
