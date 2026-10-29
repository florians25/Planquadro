import Foundation
#if canImport(Security)
import Security
#endif

/// Sicherer Schlüssel-Wert-Speicher für Geheimnisse (Refresh-Token, Cache-Schlüssel).
public protocol SecureStore: Sendable {
    func get(_ key: String) async throws -> String?
    func set(_ key: String, _ value: String) async throws
    func remove(_ key: String) async throws
    func clear() async throws
}

/// Für Tests, Linux und den Demo-Modus: nur im Arbeitsspeicher.
public actor InMemorySecureStore: SecureStore {
    private var values: [String: String] = [:]

    public init(_ initial: [String: String] = [:]) {
        values = initial
    }

    public func get(_ key: String) -> String? { values[key] }
    public func set(_ key: String, _ value: String) { values[key] = value }
    public func remove(_ key: String) { values[key] = nil }
    public func clear() { values.removeAll() }
    public var snapshot: [String: String] { values }
}

/// Speicher für das rotierende Refresh-Token (vom APIClient verwendet).
public protocol RefreshTokenStore: Sendable {
    func get() async throws -> String?
    func set(_ token: String?) async throws
}

/// Refresh-Token unter einem Schlüssel im SecureStore.
public struct SecureRefreshTokenStore: RefreshTokenStore {
    public static let key = "refresh_token"
    let store: SecureStore
    let key: String

    public init(store: SecureStore, key: String = SecureRefreshTokenStore.key) {
        self.store = store
        self.key = key
    }

    public func get() async throws -> String? { try await store.get(key) }

    public func set(_ token: String?) async throws {
        if let token { try await store.set(key, token) } else { try await store.remove(key) }
    }
}

#if canImport(Security)
/// Keychain (Generic Password), `kSecAttrAccessibleWhenUnlockedThisDeviceOnly`:
/// kein iCloud-Schlüsselbund, kein Backup auf andere Geräte, nur bei entsperrtem Gerät.
public struct KeychainSecureStore: SecureStore {
    public let service: String
    public let accessGroup: String?

    public init(service: String = "de.planquadro.app", accessGroup: String? = nil) {
        self.service = service
        self.accessGroup = accessGroup
    }

    public struct KeychainError: Error, Hashable, Sendable {
        public let status: OSStatus
    }

    private func base(_ key: String?) -> [String: Any] {
        var q: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
        ]
        if let key { q[kSecAttrAccount as String] = key }
        if let accessGroup { q[kSecAttrAccessGroup as String] = accessGroup }
        return q
    }

    public func get(_ key: String) async throws -> String? {
        var q = base(key)
        q[kSecReturnData as String] = true
        q[kSecMatchLimit as String] = kSecMatchLimitOne
        var out: CFTypeRef?
        let status = SecItemCopyMatching(q as CFDictionary, &out)
        if status == errSecItemNotFound { return nil }
        guard status == errSecSuccess, let data = out as? Data else { throw KeychainError(status: status) }
        return String(data: data, encoding: .utf8)
    }

    public func set(_ key: String, _ value: String) async throws {
        let data = Data(value.utf8)
        let attrs: [String: Any] = [
            kSecValueData as String: data,
            kSecAttrAccessible as String: kSecAttrAccessibleWhenUnlockedThisDeviceOnly,
        ]
        let status = SecItemUpdate(base(key) as CFDictionary, attrs as CFDictionary)
        if status == errSecItemNotFound {
            var add = base(key)
            add.merge(attrs) { _, b in b }
            let s = SecItemAdd(add as CFDictionary, nil)
            guard s == errSecSuccess else { throw KeychainError(status: s) }
        } else if status != errSecSuccess {
            throw KeychainError(status: status)
        }
    }

    public func remove(_ key: String) async throws {
        let status = SecItemDelete(base(key) as CFDictionary)
        guard status == errSecSuccess || status == errSecItemNotFound else { throw KeychainError(status: status) }
    }

    public func clear() async throws {
        let status = SecItemDelete(base(nil) as CFDictionary)
        guard status == errSecSuccess || status == errSecItemNotFound else { throw KeychainError(status: status) }
    }
}
#endif
