import SwiftUI
import PlanquadroKit

/// Wählt den Bildschirm nach Sitzungsstatus; setzt Branding, Farbschema, Meldungen.
struct RootView: View {
    @Environment(AppModel.self) private var model
    @Environment(\.colorScheme) private var systemScheme

    var body: some View {
        let session = model.session
        let scheme = session.prefs.theme.colorScheme ?? systemScheme
        let theme = model.theme(for: scheme)
        ZStack {
            switch session.status {
            case .launching:
                SplashView()
            case .needsServer:
                SetupView()
            case .needsLogin:
                LoginView()
            case .locked:
                LockView()
            case let .unavailable(message):
                UnavailableView(message: message)
            case .ready:
                MainView()
            }
        }
        .animation(model.options.noAnimations ? nil : .smooth, value: session.status)
        .overlay(alignment: .top) {
            if let toast = model.toast {
                ToastView(toast: toast)
                    .padding(.top, 8)
                    .transition(.move(edge: .top).combined(with: .opacity))
                    .onTapGesture { model.toast = nil }
            }
        }
        .overlay {
            if model.privacyCover {
                PrivacyCover()
            }
        }
        .environment(\.brand, theme)
        .tint(theme.primary)
        .preferredColorScheme(session.prefs.theme.colorScheme)
        .onChange(of: session.status) { _, _ in
            Task { await model.statusChanged() }
        }
        .transaction { t in
            if model.options.noAnimations { t.animation = nil }
        }
    }
}

/// Startbildschirm (wie der Launch Screen: Tinte + Bildmarke).
struct SplashView: View {
    var body: some View {
        ZStack {
            Color.pqInk.ignoresSafeArea()
            PlanquadroMark(style: .onDark)
                .frame(width: 96, height: 96)
        }
        .accessibilityLabel("Planquadro wird gestartet")
    }
}

/// Abdeckung im App-Umschalter, wenn die biometrische Sperre aktiv ist.
struct PrivacyCover: View {
    var body: some View {
        ZStack {
            Rectangle().fill(.ultraThickMaterial).ignoresSafeArea()
            PlanquadroMark().frame(width: 72, height: 72)
        }
        .accessibilityHidden(true)
    }
}

/// Hauptansicht: iPhone mit Tab-Leiste, iPad mit Seitenleiste (NavigationSplitView).
struct MainView: View {
    @Environment(\.horizontalSizeClass) private var sizeClass

    var body: some View {
        if sizeClass == .regular {
            SplitRootView()
        } else if #available(iOS 18.0, *) {
            ModernTabRootView()
        } else {
            LegacyTabRootView()
        }
    }
}

/// Bildschirm eines Bereichs mit eigenem Navigationsstapel.
struct SectionStack: View {
    let section: AppSection
    @Environment(AppModel.self) private var model

    var body: some View {
        @Bindable var router = model.router
        switch section {
        case .plan:
            NavigationStack(path: $router.planPath) { PlanView().routeDestinations() }
        case .team:
            NavigationStack(path: $router.teamPath) { TeamView().routeDestinations() }
        case .absences:
            NavigationStack(path: $router.absencesPath) { AbsencesView().routeDestinations() }
        case .notifications:
            NavigationStack(path: $router.notificationsPath) { NotificationsView().routeDestinations() }
        case .more:
            NavigationStack(path: $router.morePath) { MoreView().routeDestinations() }
        }
    }
}

struct SearchStack: View {
    @Environment(AppModel.self) private var model

    var body: some View {
        @Bindable var router = model.router
        NavigationStack(path: $router.searchPath) { SearchView().routeDestinations() }
    }
}

extension View {
    func routeDestinations() -> some View {
        navigationDestination(for: Route.self) { route in
            switch route {
            case let .day(date, userID):
                AssignmentDetailView(date: date, userID: userID)
            case .widgetGallery:
                WidgetGalleryView()
            case .about:
                AboutView()
            case .search:
                SearchView()
            }
        }
    }
}

/// iOS 18+: `Tab`-API, Suche als eigene Tab-Rolle, iOS 26: Tab-Leiste minimiert sich beim Scrollen.
@available(iOS 18.0, *)
struct ModernTabRootView: View {
    @Environment(AppModel.self) private var model

    private enum Selection: Hashable {
        case section(AppSection)
        case search
    }

    var body: some View {
        let router = model.router
        let me = model.session.me
        let selection = Binding<Selection>(
            get: { router.searchSelected ? .search : .section(router.section) },
            set: { value in
                switch value {
                case let .section(s):
                    router.searchSelected = false
                    router.section = s
                case .search:
                    router.searchSelected = true
                }
            }
        )
        TabView(selection: selection) {
            ForEach(AppSection.allCases.filter { $0.isVisible(for: me) }) { section in
                Tab(section.title, systemImage: section.symbol, value: Selection.section(section)) {
                    SectionStack(section: section)
                }
                .badge(section == .notifications ? model.session.unreadCount : 0)
            }
        }
        .pqTabBarMinimizeOnScroll()
        // Höchstens fünf Tabs: ein sechster Tab ließe iOS einen eigenen „Mehr“-Überlauf
        // anlegen (doppelte Zurück-Pfeile). Die Suche liegt auf dem iPhone daher unter „Mehr“.
        .onChange(of: router.searchSelected, initial: true) { _, on in
            guard on else { return }
            router.searchSelected = false
            router.section = .more
            router.morePath = NavigationPath()
            router.morePath.append(Route.search)
        }
    }
}

/// iOS 17: klassische Tab-Leiste.
struct LegacyTabRootView: View {
    @Environment(AppModel.self) private var model

    var body: some View {
        @Bindable var router = model.router
        let me = model.session.me
        TabView(selection: $router.section) {
            ForEach(AppSection.allCases.filter { $0.isVisible(for: me) }) { section in
                SectionStack(section: section)
                    .tabItem { Label(section.title, systemImage: section.symbol) }
                    .tag(section)
                    .badge(section == .notifications ? model.session.unreadCount : 0)
            }
        }
    }
}

/// iPad: Seitenleiste mit Bereichen, rechts der Navigationsstapel des Bereichs.
struct SplitRootView: View {
    @Environment(AppModel.self) private var model
    @Environment(\.brand) private var brand
    @State private var columnVisibility = NavigationSplitViewVisibility.all

    var body: some View {
        let router = model.router
        let me = model.session.me
        let selection = Binding<AppSection?>(
            get: { router.searchSelected ? nil : router.section },
            set: { if let s = $0 { router.searchSelected = false; router.section = s } }
        )
        NavigationSplitView(columnVisibility: $columnVisibility) {
            List(selection: selection) {
                Section {
                    ForEach(AppSection.allCases.filter { $0.isVisible(for: me) }) { section in
                        NavigationLink(value: section) {
                            Label(section.title, systemImage: section.symbol)
                        }
                        .badge(section == .notifications ? model.session.unreadCount : 0)
                    }
                } header: {
                    HStack(spacing: 8) {
                        PlanquadroMark().frame(width: 20, height: 20)
                        Text(brand.tenantName).font(.headline).foregroundStyle(.primary)
                    }
                    .textCase(nil)
                    .padding(.bottom, 4)
                }
                Section {
                    Button {
                        router.searchSelected = true
                    } label: {
                        Label("Suchen", systemImage: "magnifyingglass")
                    }
                    .keyboardShortcut("f", modifiers: .command)
                }
            }
            .navigationTitle("Planquadro")
            .navigationSplitViewColumnWidth(min: 220, ideal: 260)
        } detail: {
            if router.searchSelected {
                SearchStack()
            } else {
                SectionStack(section: router.section)
                    .id(router.section)
            }
        }
    }
}
