import Foundation
import PlanquadroKit

/// Start-Argumente für Demo-/Screenshot-Modus – **nur im Debug-Build** (CI, Entwicklung):
///
/// - `-PQDemo YES` – Demo-Server mit Beispieldaten, automatische Anmeldung
/// - `-PQScreen <name>` – Startbildschirm: `setup`, `login`, `plan`, `week`, `detail`, `team`,
///   `absences`, `request`, `notifications`, `more`, `widgets`, `search`
/// - `-PQToday 2026-11-04` – fester „Heute“-Tag im Demo-Modus (reproduzierbare Screenshots)
/// - `-PQReset YES` – lokale Daten vor dem Start löschen
/// - `-PQNoAnimations YES` – Animationen aus (UI-Tests)
struct LaunchOptions: Equatable {
    var demo = false
    var screen: String?
    var today: ISODay?
    var reset = false
    var noAnimations = false

    static var current: LaunchOptions {
        #if DEBUG
        let d = UserDefaults.standard
        var o = LaunchOptions()
        o.demo = d.bool(forKey: "PQDemo")
        o.screen = d.string(forKey: "PQScreen")?.lowercased()
        o.today = d.string(forKey: "PQToday").flatMap { ISOCalendar.isValid($0) ? $0 : nil }
        o.reset = d.bool(forKey: "PQReset")
        o.noAnimations = d.bool(forKey: "PQNoAnimations")
        return o
        #else
        return LaunchOptions()
        #endif
    }

    /// Bildschirme, die ohne Anmeldung gezeigt werden.
    var wantsSignedOut: Bool { screen == "setup" || screen == "login" }
}
