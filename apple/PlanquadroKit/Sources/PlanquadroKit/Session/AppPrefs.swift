import Foundation

/// Erscheinungsbild.
public enum ThemeMode: String, Codable, CaseIterable, Sendable, Identifiable {
    case system, light, dark

    public var id: String { rawValue }

    public var label: String {
        switch self {
        case .system: return "System"
        case .light: return "Hell"
        case .dark: return "Dunkel"
        }
    }
}

/// Nicht geheime Einstellungen (UserDefaults). Geheimnisse liegen im SecureStore.
public struct AppPrefs: Codable, Hashable, Sendable {
    public struct StoredBranding: Codable, Hashable, Sendable {
        public var name: String
        public var primary: String
        public var accent: String
        public var logoURL: String?

        public init(name: String, primary: String, accent: String, logoURL: String?) {
            self.name = name
            self.primary = primary
            self.accent = accent
            self.logoURL = logoURL
        }
    }

    /// API-Basis inkl. /api/v1/
    public var serverURL: String?
    public var tenant: String?
    /// Demo-Modus mit Beispieldaten statt Server
    public var demo: Bool = false
    public var theme: ThemeMode = .system
    public var biometric: Bool = false
    /// ID der Push-Registrierung (DELETE devices/{id} beim Abmelden)
    public var deviceID: Int?
    /// zuletzt angemeldeter Benutzer (für Cache-Zuordnung offline)
    public var lastUserID: Int?
    /// Branding für den Start ohne Netz
    public var branding: StoredBranding?
    /// eigene Einsätze in der Spotlight-Suche (Standard aus)
    public var spotlight: Bool = false
    /// Live-Aktivität für den heutigen Einsatz automatisch anbieten
    public var liveActivity: Bool = false

    public init() {}

    enum CodingKeys: String, CodingKey {
        case serverURL, tenant, demo, theme, biometric, deviceID, lastUserID, branding, spotlight, liveActivity
    }

    public init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        serverURL = try c.decodeIfPresent(String.self, forKey: .serverURL)
        tenant = try c.decodeIfPresent(String.self, forKey: .tenant)
        demo = try c.decodeIfPresent(Bool.self, forKey: .demo) ?? false
        theme = (try? c.decodeIfPresent(ThemeMode.self, forKey: .theme)) ?? .system
        biometric = try c.decodeIfPresent(Bool.self, forKey: .biometric) ?? false
        deviceID = try c.decodeIfPresent(Int.self, forKey: .deviceID)
        lastUserID = try c.decodeIfPresent(Int.self, forKey: .lastUserID)
        branding = try c.decodeIfPresent(StoredBranding.self, forKey: .branding)
        spotlight = try c.decodeIfPresent(Bool.self, forKey: .spotlight) ?? false
        liveActivity = try c.decodeIfPresent(Bool.self, forKey: .liveActivity) ?? false
    }
}

public protocol PreferencesStore: Sendable {
    func load() -> AppPrefs
    func save(_ prefs: AppPrefs)
    func clear()
}

/// UserDefaults (bei App-Group-Nutzung mit `suiteName`).
public final class UserDefaultsPreferencesStore: PreferencesStore, @unchecked Sendable {
    private let defaults: UserDefaults
    private let key: String

    public init(defaults: UserDefaults = .standard, key: String = "planquadro.prefs.v1") {
        self.defaults = defaults
        self.key = key
    }

    public func load() -> AppPrefs {
        guard let data = defaults.data(forKey: key), let p = try? JSONDecoder().decode(AppPrefs.self, from: data) else { return AppPrefs() }
        return p
    }

    public func save(_ prefs: AppPrefs) {
        if let data = try? JSONEncoder().encode(prefs) { defaults.set(data, forKey: key) }
    }

    public func clear() { defaults.removeObject(forKey: key) }
}

public final class InMemoryPreferencesStore: PreferencesStore, @unchecked Sendable {
    private let lock = NSLock()
    private var prefs: AppPrefs

    public init(_ prefs: AppPrefs = AppPrefs()) {
        self.prefs = prefs
    }

    public func load() -> AppPrefs {
        lock.lock()
        defer { lock.unlock() }
        return prefs
    }

    public func save(_ p: AppPrefs) {
        lock.lock()
        prefs = p
        lock.unlock()
    }

    public func clear() { save(AppPrefs()) }
}
