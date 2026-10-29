#if canImport(ActivityKit)
import ActivityKit
import Foundation

/// Live-Aktivität „Heutiger Einsatz“ (Sperrbildschirm, Dynamic Island).
/// Enthält nur eigene, nicht vertrauliche Angaben: Kürzel, Bezeichnung, Ortsname.
struct PlanquadroActivityAttributes: ActivityAttributes {
    struct ContentState: Codable, Hashable {
        /// z. B. „Heute im Einsatz“ oder „Plan geändert“
        var headline: String
        var code: String
        var label: String
        var color: String
        var fg: String
        var place: String?
        var updatedAt: Date
    }

    /// Tag des Einsatzes (`Y-m-d`)
    var date: String
    /// Name des Mandanten
    var tenantName: String
}
#endif
