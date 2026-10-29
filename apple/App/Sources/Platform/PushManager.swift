import UIKit
import UserNotifications
import PlanquadroKit

/// Push über APNs: Berechtigung, Registrierung, Gerät bei der API an-/abmelden,
/// Deep Link beim Antippen. Abschaltbar per Build-Einstellung `PQ_PUSH_ENABLED = NO`
/// (Info.plist `PQPushEnabled`), z. B. für unsignierte CI-Builds.
@MainActor
final class PushManager {
    static var isEnabledInBuild: Bool {
        let v = (Bundle.main.object(forInfoDictionaryKey: "PQPushEnabled") as? String ?? "YES").uppercased()
        return v != "NO" && v != "0" && v != "FALSE"
    }

    /// Fragt (einmalig) nach Erlaubnis und registriert bei APNs.
    func register() async {
        guard Self.isEnabledInBuild else { return }
        let center = UNUserNotificationCenter.current()
        let settings = await center.notificationSettings()
        switch settings.authorizationStatus {
        case .notDetermined:
            let granted = (try? await center.requestAuthorization(options: [.alert, .badge, .sound])) ?? false
            guard granted else { return }
        case .denied:
            return
        default:
            break
        }
        UIApplication.shared.registerForRemoteNotifications()
    }

    func unregister() {
        guard Self.isEnabledInBuild else { return }
        UIApplication.shared.unregisterForRemoteNotifications()
        UNUserNotificationCenter.current().removeAllDeliveredNotifications()
        UNUserNotificationCenter.current().setBadgeCount(0)
    }

    func setBadge(_ count: Int) {
        guard Self.isEnabledInBuild else { return }
        UNUserNotificationCenter.current().setBadgeCount(count)
    }

    static func hex(_ token: Data) -> String {
        token.map { b in
            let s = String(b, radix: 16)
            return s.count == 1 ? "0" + s : s
        }.joined()
    }
}

/// App-Delegate für Push (APNs-Token, Hintergrund-Sync, Antippen).
final class AppDelegate: NSObject, UIApplicationDelegate, UNUserNotificationCenterDelegate {
    func application(_ application: UIApplication, didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]? = nil) -> Bool {
        UNUserNotificationCenter.current().delegate = self
        return true
    }

    func application(_ application: UIApplication, didRegisterForRemoteNotificationsWithDeviceToken deviceToken: Data) {
        let hex = PushManager.hex(deviceToken)
        Task { @MainActor in
            await AppModel.shared.session.registerPushToken(hex)
        }
    }

    func application(_ application: UIApplication, didFailToRegisterForRemoteNotificationsWithError error: Error) {
        // z. B. Simulator ohne Push oder fehlende Signierung – Push bleibt aus, Sync läuft weiter
    }

    /// Stille Nachricht bzw. Nachricht im Hintergrund: `plan_changed` → Sync.
    func application(_ application: UIApplication, didReceiveRemoteNotification userInfo: [AnyHashable: Any],
                     fetchCompletionHandler completionHandler: @escaping (UIBackgroundFetchResult) -> Void) {
        guard let push = PlanChangedPush(userInfo: userInfo) else {
            completionHandler(.noData)
            return
        }
        Task { @MainActor in
            let ok = await AppModel.shared.session.handlePlanChangedPush(push)
            completionHandler(ok ? .newData : .failed)
        }
    }

    /// Im Vordergrund: Banner zeigen und sofort synchronisieren.
    func userNotificationCenter(_ center: UNUserNotificationCenter, willPresent notification: UNNotification,
                                withCompletionHandler completionHandler: @escaping (UNNotificationPresentationOptions) -> Void) {
        let push = PlanChangedPush(userInfo: notification.request.content.userInfo)
        if let push {
            Task { @MainActor in
                _ = await AppModel.shared.session.handlePlanChangedPush(push)
            }
        }
        completionHandler([.banner, .list, .sound])
    }

    /// Antippen: Deep Link zum betroffenen Monat bzw. laut `link`.
    func userNotificationCenter(_ center: UNUserNotificationCenter, didReceive response: UNNotificationResponse,
                                withCompletionHandler completionHandler: @escaping () -> Void) {
        let link = DeepLink.forPush(response.notification.request.content.userInfo)
        Task { @MainActor in
            AppModel.shared.handle(link: link)
            completionHandler()
        }
    }
}
