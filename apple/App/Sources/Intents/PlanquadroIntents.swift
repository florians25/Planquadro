import AppIntents
import Foundation
import PlanquadroKit

/// Tag für „Wo bin ich … eingesetzt?“
enum PlanDayOption: String, AppEnum {
    case today, tomorrow, dayAfterTomorrow

    static var typeDisplayRepresentation: TypeDisplayRepresentation = "Tag"
    static var caseDisplayRepresentations: [PlanDayOption: DisplayRepresentation] = [
        .today: "heute",
        .tomorrow: "morgen",
        .dayAfterTomorrow: "übermorgen",
    ]

    var offset: Int {
        switch self {
        case .today: return 0
        case .tomorrow: return 1
        case .dayAfterTomorrow: return 2
        }
    }
}

/// „Wo bin ich morgen eingesetzt?“ – Antwort aus dem nicht vertraulichen Auszug
/// (App Group); funktioniert auch ohne die App zu öffnen.
struct NextAssignmentIntent: AppIntent {
    static var title: LocalizedStringResource = "Einsatz abfragen"
    static var description = IntentDescription("Sagt, wo Sie an einem Tag eingesetzt sind. Abwesenheiten werden nur als „abwesend“ genannt.")

    @Parameter(title: "Tag", default: .tomorrow)
    var day: PlanDayOption

    init() {}

    init(day: PlanDayOption) {
        self.day = day
    }

    static var parameterSummary: some ParameterSummary {
        Summary("Einsatz \(\.$day)")
    }

    func perform() async throws -> some IntentResult & ProvidesDialog {
        let today = ISOCalendar.today()
        let date = ISOCalendar.addDays(today, day.offset)
        guard let snapshot = AppGroup.snapshotStore.load() else {
            return .result(dialog: "Ich kenne Ihren Plan noch nicht. Öffnen Sie Planquadro einmal und melden Sie sich an.")
        }
        return .result(dialog: IntentDialog(stringLiteral: snapshot.spokenSummary(for: date, today: today)))
    }
}

/// „Zeig meinen Plan“ – öffnet die App im Plan.
struct OpenPlanIntent: AppIntent {
    static var title: LocalizedStringResource = "Plan anzeigen"
    static var description = IntentDescription("Öffnet Ihren Plan in Planquadro.")
    static var openAppWhenRun = true

    @MainActor
    func perform() async throws -> some IntentResult {
        IntentInbox.shared.pending = .plan(nil)
        return .result()
    }
}

/// „Urlaub beantragen“ – öffnet das Antragsformular.
struct RequestVacationIntent: AppIntent {
    static var title: LocalizedStringResource = "Urlaub beantragen"
    static var description = IntentDescription("Öffnet das Antragsformular für Urlaub oder Gleitzeit.")
    static var openAppWhenRun = true

    @MainActor
    func perform() async throws -> some IntentResult {
        IntentInbox.shared.pending = .absences(year: nil, newRequest: true)
        return .result()
    }
}

/// Kurzbefehle mit deutschen Sätzen (Siri, Spotlight, Aktionstaste).
struct PlanquadroShortcuts: AppShortcutsProvider {
    static var appShortcuts: [AppShortcut] {
        AppShortcut(
            intent: NextAssignmentIntent(),
            phrases: [
                "Wo bin ich morgen eingesetzt in \(.applicationName)",
                "Wo bin ich \(\.$day) eingesetzt in \(.applicationName)",
                "Wo bin ich \(\.$day) mit \(.applicationName)",
                "\(.applicationName) Einsatz \(\.$day)",
                "Mein Einsatz \(\.$day) in \(.applicationName)",
            ],
            shortTitle: "Wo bin ich eingesetzt?",
            systemImageName: "calendar.badge.clock"
        )
        AppShortcut(
            intent: OpenPlanIntent(),
            phrases: [
                "Zeig meinen Plan in \(.applicationName)",
                "Öffne meinen Plan in \(.applicationName)",
                "\(.applicationName) Plan",
            ],
            shortTitle: "Plan anzeigen",
            systemImageName: "calendar"
        )
        AppShortcut(
            intent: RequestVacationIntent(),
            phrases: [
                "Urlaub beantragen in \(.applicationName)",
                "\(.applicationName) Urlaub beantragen",
                "Beantrage Urlaub mit \(.applicationName)",
            ],
            shortTitle: "Urlaub beantragen",
            systemImageName: "sun.max.fill"
        )
    }

    static var shortcutTileColor: ShortcutTileColor = .blue
}
