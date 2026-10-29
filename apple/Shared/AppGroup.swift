import Foundation
import PlanquadroKit

/// Gemeinsamer Container von App, Widgets, Live-Aktivität und Kurzbefehlen.
/// Die Kennung kommt aus dem Info.plist (`PQAppGroup` = `$(PQ_APP_GROUP)`), damit
/// White-Label-Builds eine eigene App Group verwenden können.
enum AppGroup {
    static var identifier: String {
        (Bundle.main.object(forInfoDictionaryKey: "PQAppGroup") as? String).flatMap { $0.isEmpty || $0.hasPrefix("$(") ? nil : $0 }
            ?? "group.de.planquadro.app"
    }

    /// Verzeichnis im App-Group-Container. Ohne Entitlement (z. B. unsignierter
    /// Simulator-Build im CI) fällt die App auf ihr eigenes Verzeichnis zurück –
    /// Widgets zeigen dann den Hinweis „In Planquadro anmelden“.
    static var directory: URL {
        if let url = FileManager.default.containerURL(forSecurityApplicationGroupIdentifier: identifier) {
            return url.appendingPathComponent("Library/Application Support/Planquadro", isDirectory: true)
        }
        let base = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask).first
            ?? URL(fileURLWithPath: NSTemporaryDirectory())
        return base.appendingPathComponent("Planquadro", isDirectory: true)
    }

    static var snapshotStore: PlanSnapshotStore { PlanSnapshotStore(directory: directory) }

    /// Gemeinsame Widget-Kinds (für gezieltes Neuladen).
    static let todayWidgetKind = "de.planquadro.widget.today"
    static let weekWidgetKind = "de.planquadro.widget.week"
    static let lockWidgetKind = "de.planquadro.widget.lock"
}
