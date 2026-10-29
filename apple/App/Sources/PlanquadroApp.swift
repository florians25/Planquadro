import CoreSpotlight
import SwiftUI
import PlanquadroKit

@main
struct PlanquadroApp: App {
    @UIApplicationDelegateAdaptor(AppDelegate.self) private var appDelegate
    @State private var model = AppModel.shared
    @Environment(\.scenePhase) private var scenePhase

    var body: some Scene {
        WindowGroup {
            RootView()
                .environment(model)
                .onOpenURL { model.handle(url: $0) }
                .onContinueUserActivity(CSSearchableItemActionType) { model.handle(activity: $0) }
                .onContinueUserActivity(HandoffActivity.day) { model.handle(activity: $0) }
                .onContinueUserActivity(HandoffActivity.section) { model.handle(activity: $0) }
                .task { await model.start() }
        }
        .onChange(of: scenePhase) { _, phase in
            model.scenePhaseChanged(phase)
        }
        .commands {
            PlanquadroCommands(model: model)
        }
    }
}

enum HandoffActivity {
    static let day = "de.planquadro.app.day"
    static let section = "de.planquadro.app.section"
}

/// Tastaturkurzbefehle (iPad mit Tastatur, Menüleiste ab iPadOS 26).
struct PlanquadroCommands: Commands {
    let model: AppModel

    var body: some Commands {
        CommandMenu("Gehe zu") {
            ForEach(Array(AppSection.allCases.enumerated()), id: \.element) { index, section in
                Button(section.title) { model.handle(link: section.defaultLink) }
                    .keyboardShortcut(KeyEquivalent(Character(String(index + 1))), modifiers: .command)
                    .disabled(!model.session.isReady || !section.isVisible(for: model.session.me))
            }
            Divider()
            Button("Urlaub beantragen") { model.handle(link: .absences(year: nil, newRequest: true)) }
                .keyboardShortcut("n", modifiers: .command)
                .disabled(!model.session.isReady || !(model.session.me?.modules.approvals ?? false))
        }
        CommandGroup(after: .toolbar) {
            Button("Aktualisieren") {
                Task { await model.session.sync() }
            }
            .keyboardShortcut("r", modifiers: .command)
            .disabled(!model.session.isReady)
        }
    }
}

extension AppSection {
    var defaultLink: DeepLink {
        switch self {
        case .plan: return .plan(nil)
        case .team: return .team(nil)
        case .absences: return .absences(year: nil, newRequest: false)
        case .notifications: return .notifications
        case .more: return .more
        }
    }
}
