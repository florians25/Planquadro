import SwiftUI
import UIKit
import Observation
import WidgetKit
import PlanquadroKit

/// Ziele innerhalb eines Navigationsstapels.
enum Route: Hashable {
    case day(ISODay, userID: Int?)
    case widgetGallery
    case about
    /// Suche als Seite (iPhone: unter „Mehr“, damit die Tab-Leiste bei fünf Einträgen bleibt)
    case search
}

enum PlanMode: String, CaseIterable, Identifiable {
    case week, month
    var id: String { rawValue }
    var label: String { self == .week ? "Woche" : "Monat" }
}

/// Navigation der App (Tab bzw. Seitenleiste, Stapel je Bereich).
@MainActor
@Observable
final class Router {
    var section: AppSection = .plan
    var planPath = NavigationPath()
    var teamPath = NavigationPath()
    var absencesPath = NavigationPath()
    var notificationsPath = NavigationPath()
    var morePath = NavigationPath()
    var searchPath = NavigationPath()
    var planMode: PlanMode = .week
    /// angeforderter Zeitraum (Deep Link); die Bildschirme übernehmen ihn einmalig
    var planMonth: YearMonth?
    var teamMonth: YearMonth?
    var absencesYear: Int?
    var showRequestForm = false
    /// Suche-Tab aktiv (iOS 18+)
    var searchSelected = false

    func resetPaths() {
        planPath = NavigationPath()
        teamPath = NavigationPath()
        absencesPath = NavigationPath()
        notificationsPath = NavigationPath()
        morePath = NavigationPath()
        searchPath = NavigationPath()
    }

    func open(_ link: DeepLink) {
        searchSelected = false
        section = link.section
        switch link {
        case let .plan(ym):
            planPath = NavigationPath()
            planMonth = ym
            if ym != nil { planMode = .month }
        case let .day(d, uid):
            planMonth = YearMonth(key: d)
            planPath = NavigationPath()
            planPath.append(Route.day(d, userID: uid))
        case let .team(ym):
            teamPath = NavigationPath()
            teamMonth = ym
        case let .absences(y, req):
            absencesPath = NavigationPath()
            absencesYear = y
            showRequestForm = req
        case .notifications:
            notificationsPath = NavigationPath()
        case .more:
            morePath = NavigationPath()
        }
    }
}

/// Wird von App Intents gesetzt (laufen im App-Prozess) und beim nächsten Durchlauf geöffnet.
@MainActor
final class IntentInbox {
    static let shared = IntentInbox()
    var pending: DeepLink? {
        didSet { if let p = pending { AppModel.shared.handle(link: p); pending = nil } }
    }
}

/// App-Zustand: Sitzung (PlanquadroKit) + Plattformdienste + Navigation.
@MainActor
@Observable
final class AppModel {
    static let shared = AppModel()

    let session: SessionController
    let router = Router()
    let options: LaunchOptions
    var toast: Toast?
    /// Bildschirm abdecken, wenn die App im App-Umschalter erscheint (bei aktiver Sperre)
    var privacyCover = false

    @ObservationIgnored let calendar = CalendarExporter()
    @ObservationIgnored let liveActivities = LiveActivityManager()
    @ObservationIgnored let spotlight = SpotlightIndexer()
    @ObservationIgnored let push = PushManager()
    @ObservationIgnored private let network = NetworkMonitor()
    @ObservationIgnored private var started = false
    @ObservationIgnored private var readyHandled = false
    @ObservationIgnored private var pendingLink: DeepLink?
    @ObservationIgnored private var toastTask: Task<Void, Never>?

    init(options: LaunchOptions = .current) {
        self.options = options
        let fm = FileManager.default
        var cacheDir = (fm.urls(for: .applicationSupportDirectory, in: .userDomainMask).first ?? fm.temporaryDirectory)
            .appendingPathComponent("Planquadro/Cache", isDirectory: true)
        try? fm.createDirectory(at: cacheDir, withIntermediateDirectories: true)
        // Offline-Daten nicht in iCloud-/iTunes-Backups
        var rv = URLResourceValues()
        rv.isExcludedFromBackup = true
        try? cacheDir.setResourceValues(rv)

        let bundleID = Bundle.main.bundleIdentifier ?? "de.planquadro.app"
        let secure: SecureStore = options.demo ? InMemorySecureStore() : ResilientSecureStore(service: bundleID)
        let version = (Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String) ?? "7.0.0"
        let env = SessionEnvironment(
            secureStore: secure,
            prefs: UserDefaultsPreferencesStore(),
            cacheDirectory: cacheDir,
            biometrics: DeviceBiometrics(),
            deviceName: UIDevice.current.name,
            appVersion: version,
            snapshotStore: AppGroup.snapshotStore,
            demoOptions: DemoServer.Options(latency: options.demo ? 0.05 : 0.15, today: options.today)
        )
        session = SessionController(env: env)
        session.onSnapshotUpdated = { [weak self] snap in self?.snapshotUpdated(snap) }
        session.onSignedOut = { [weak self] in self?.signedOut() }
    }

    var appVersion: String { session.env.appVersion }
    var buildNumber: String { (Bundle.main.object(forInfoDictionaryKey: "CFBundleVersion") as? String) ?? "1" }

    func theme(for scheme: ColorScheme) -> BrandTheme {
        BrandTheme(branding: session.branding, tenantName: session.tenantName, dark: scheme == .dark)
    }

    // MARK: Start

    func start() async {
        guard !started else { return }
        started = true
        if options.reset { await session.wipeLocal() }
        if options.demo && options.screen == "setup" {
            await session.start()
        } else {
            await session.start(forceDemo: options.demo)
        }
        if options.demo, !options.wantsSignedOut, session.status == .needsLogin {
            try? await session.login(username: "mmuster", password: "demo")
        }
        applyScreenOption()
        Task { await watchNetwork() }
        // statusChanged() folgt über RootView.onChange(of: status); beim Start ohne Wechsel direkt
        if session.status == .ready && !readyHandled { await statusChanged() }
    }

    private func applyScreenOption() {
        guard let screen = options.screen else { return }
        switch screen {
        case "plan":
            router.planMode = .month
            router.section = .plan
        case "week":
            router.planMode = .week
            router.section = .plan
        case "detail":
            let today = session.today
            var d = today
            for i in 0..<14 {
                let c = ISOCalendar.addDays(today, i)
                if let wd = ISOCalendar.weekday(c), wd <= 5, i > 0 { d = c; break }
            }
            router.open(.day(d, userID: nil))
        case "team": router.open(.team(nil))
        case "absences": router.open(.absences(year: nil, newRequest: false))
        case "request": router.open(.absences(year: nil, newRequest: true))
        case "notifications": router.open(.notifications)
        case "more": router.open(.more)
        case "widgets":
            router.open(.more)
            router.morePath.append(Route.widgetGallery)
        case "search":
            router.searchSelected = true
        default: break
        }
    }

    private func watchNetwork() async {
        for await online in network.updates() {
            let was = session.online
            await session.setOnline(online)
            if session.isReady && online != was {
                show(online ? "Wieder online." : "Offline – Sie sehen den zuletzt geladenen Stand.", style: online ? .ok : .info)
            }
        }
    }

    /// Reaktion auf Statuswechsel der Sitzung (aus RootView beobachtet).
    func statusChanged() async {
        switch session.status {
        case .ready:
            readyHandled = true
            router.section = router.section.isVisible(for: session.me) ? router.section : .plan
            if !session.isDemo { await push.register() }
            session.startLive()
            await session.sync()
            if let link = pendingLink {
                pendingLink = nil
                router.open(link)
            }
            await autoLiveActivity()
        case .needsLogin, .needsServer:
            readyHandled = false
            router.resetPaths()
        default:
            break
        }
    }

    // MARK: Deep Links

    func handle(url: URL) {
        if url.scheme == DeepLink.scheme, url.host == "auth" { return } // OIDC-Rücksprung: ASWebAuthenticationSession
        guard let link = DeepLink(url: url) else { return }
        handle(link: link)
    }

    func handle(link: DeepLink) {
        guard session.isReady else {
            pendingLink = link
            return
        }
        if !link.section.isVisible(for: session.me) { return }
        router.open(link)
    }

    func handle(activity: NSUserActivity) {
        if let link = SpotlightIndexer.link(for: activity) {
            handle(link: link)
        } else if let url = activity.webpageURL ?? (activity.userInfo?["url"] as? String).flatMap(URL.init(string:)), let link = DeepLink(url: url) {
            handle(link: link)
        }
    }

    // MARK: Lebenszyklus

    func scenePhaseChanged(_ phase: ScenePhase) {
        switch phase {
        case .active:
            privacyCover = false
            Task {
                await session.didBecomeActive()
                await autoLiveActivity()
            }
        case .inactive:
            privacyCover = session.prefs.biometric && session.isReady
        case .background:
            privacyCover = session.prefs.biometric && session.isReady
            Task { await session.didEnterBackground() }
        @unknown default:
            break
        }
    }

    // MARK: Widgets, Spotlight, Live-Aktivität

    private func snapshotUpdated(_ snap: PlanSnapshot?) {
        WidgetCenter.shared.reloadAllTimelines()
        if let snap, session.prefs.spotlight { spotlight.index(snap) }
        if snap == nil { spotlight.clear() }
        let today = session.today
        let name = session.tenantName
        Task { await liveActivities.refresh(from: snap, today: today, tenantName: name) }
    }

    private func signedOut() {
        spotlight.clear()
        push.unregister()
        calendar.forgetExports()
        Task { await liveActivities.endAll() }
        WidgetCenter.shared.reloadAllTimelines()
    }

    private func autoLiveActivity() async {
        guard session.isReady, session.prefs.liveActivity, !liveActivities.isRunning,
              let day = AppGroup.snapshotStore.load()?.day(session.today) else { return }
        await liveActivities.startOrUpdate(day: day, tenantName: session.tenantName)
    }

    func startLiveActivityForToday() async {
        guard let day = AppGroup.snapshotStore.load()?.day(session.today), day.entry?.kind == .assignment else {
            show("Heute ist kein Einsatz geplant.", style: .info)
            return
        }
        if await liveActivities.startOrUpdate(day: day, tenantName: session.tenantName) {
            show("Live-Aktivität gestartet.", style: .ok)
        } else {
            show("Live-Aktivitäten sind auf diesem Gerät ausgeschaltet.", style: .error)
        }
    }

    func setSpotlight(_ on: Bool) {
        session.setSpotlight(on)
        if on, let s = AppGroup.snapshotStore.load() { spotlight.index(s) } else { spotlight.clear() }
    }

    // MARK: Meldungen

    func show(_ message: String, style: Toast.Style = .info) {
        toastTask?.cancel()
        withAnimation(.snappy) { toast = Toast(message: message, style: style) }
        UIAccessibility.post(notification: .announcement, argument: message)
        toastTask = Task { [weak self] in
            try? await Task.sleep(nanoseconds: 2_800_000_000)
            guard !Task.isCancelled else { return }
            withAnimation(.easeOut) { self?.toast = nil }
        }
    }

    func show(error: Error) {
        show(APIError.wrap(error).userMessage, style: .error)
    }
}
