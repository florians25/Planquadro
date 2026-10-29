import Foundation
#if canImport(CryptoKit)
import CryptoKit
#endif

/// Verschlüsselung der Cache-Datensätze. Die Datensatz-ID wird als AAD authentifiziert
/// (keine Vertauschung zwischen Benutzern/Schlüsseln möglich).
public protocol CacheCipher: Sendable {
    func seal(_ plaintext: Data, aad: Data) throws -> Data
    func open(_ ciphertext: Data, aad: Data) throws -> Data
}

/// Ohne Verschlüsselung – nur für Tests/Linux und den Demo-Modus.
public struct PlainCacheCipher: CacheCipher {
    public init() {}
    public func seal(_ plaintext: Data, aad: Data) throws -> Data { plaintext }
    public func open(_ ciphertext: Data, aad: Data) throws -> Data { ciphertext }
}

#if canImport(CryptoKit)
/// AES-GCM-256 (CryptoKit). Schlüsselmaterial liegt im Keychain.
public struct AESGCMCacheCipher: CacheCipher {
    let key: SymmetricKey

    public init(keyData: Data) {
        key = SymmetricKey(data: keyData)
    }

    public static func newKeyData() -> Data {
        SymmetricKey(size: .bits256).withUnsafeBytes { Data($0) }
    }

    public func seal(_ plaintext: Data, aad: Data) throws -> Data {
        guard let combined = try AES.GCM.seal(plaintext, using: key, authenticating: aad).combined else {
            throw CocoaError(.coderInvalidValue)
        }
        return combined
    }

    public func open(_ ciphertext: Data, aad: Data) throws -> Data {
        try AES.GCM.open(try AES.GCM.SealedBox(combined: ciphertext), using: key, authenticating: aad)
    }
}
#endif

/// Wert aus dem Cache mit Zeitpunkt der letzten Server-Antwort.
public struct Cached<T> {
    public var data: T
    public var savedAt: Date

    public init(data: T, savedAt: Date) {
        self.data = data
        self.savedAt = savedAt
    }
}

extension Cached: Sendable where T: Sendable {}

/// Offline-Lesecache: zuletzt geladene Plan-/Team-/Abwesenheits-/Detaildaten,
/// je Server/Mandant/Benutzer getrennt und verschlüsselt (wie `mobile/src/store/cache.ts`).
///
/// Ablage: `<Verzeichnis>/<Scope>/<base64url(Schlüssel)>.pqc`. Auf iOS mit Datenschutzklasse
/// „bis zur ersten Entsperrung“, damit ein Push-Sync auch bei gesperrtem Gerät schreiben kann.
public actor OfflineCache {
    private let directory: URL
    private let cipher: CacheCipher
    private let now: @Sendable () -> Date
    private let fm = FileManager.default

    private struct Record: Codable {
        var savedAt: Date
        var payload: Data
    }

    public init(directory: URL, cipher: CacheCipher, now: @escaping @Sendable () -> Date = { Date() }) {
        self.directory = directory
        self.cipher = cipher
        self.now = now
    }

    private static func encodeName(_ key: String) -> String { PKCE.base64URL(Data(key.utf8)) }

    private static func decodeName(_ name: String) -> String? {
        var s = name.replacingOccurrences(of: "-", with: "+").replacingOccurrences(of: "_", with: "/")
        while s.count % 4 != 0 { s += "=" }
        guard let d = Data(base64Encoded: s) else { return nil }
        return String(data: d, encoding: .utf8)
    }

    private func scopeDir(_ scope: String) -> URL {
        directory.appendingPathComponent(Self.encodeName(scope), isDirectory: true)
    }

    private func file(_ scope: String, _ key: String) -> URL {
        scopeDir(scope).appendingPathComponent(Self.encodeName(key) + ".pqc")
    }

    private func aad(_ scope: String, _ key: String) -> Data { Data("\(scope)|\(key)".utf8) }

    public func put<T: Encodable>(_ value: T, scope: String, key: String) throws {
        let plain = try JSONEncoder().encode(value)
        let rec = Record(savedAt: now(), payload: try cipher.seal(plain, aad: aad(scope, key)))
        let data = try JSONEncoder().encode(rec)
        try fm.createDirectory(at: scopeDir(scope), withIntermediateDirectories: true)
        #if os(iOS) || os(watchOS)
        try data.write(to: file(scope, key), options: [.atomic, .completeFileProtectionUntilFirstUserAuthentication])
        #else
        try data.write(to: file(scope, key), options: [.atomic])
        #endif
    }

    public func get<T: Decodable>(_ type: T.Type, scope: String, key: String) -> Cached<T>? {
        let url = file(scope, key)
        guard let data = try? Data(contentsOf: url) else { return nil }
        do {
            let rec = try JSONDecoder().decode(Record.self, from: data)
            let plain = try cipher.open(rec.payload, aad: aad(scope, key))
            return Cached(data: try JSONDecoder().decode(T.self, from: plain), savedAt: rec.savedAt)
        } catch {
            // Schlüssel gewechselt, Format geändert oder manipuliert → verwerfen
            try? fm.removeItem(at: url)
            return nil
        }
    }

    public func delete(scope: String, key: String) {
        try? fm.removeItem(at: file(scope, key))
    }

    /// Schlüssel eines Scopes, optional nach Präfix gefiltert (sortiert).
    public func keys(scope: String, prefix: String = "") -> [String] {
        guard let names = try? fm.contentsOfDirectory(atPath: scopeDir(scope).path) else { return [] }
        return names.compactMap { n -> String? in
            guard n.hasSuffix(".pqc") else { return nil }
            return Self.decodeName(String(n.dropLast(4)))
        }.filter { $0.hasPrefix(prefix) }.sorted()
    }

    public func clear(scope: String) {
        try? fm.removeItem(at: scopeDir(scope))
    }

    public func clearAll() {
        try? fm.removeItem(at: directory)
    }

    public nonisolated func scoped(_ scope: String) -> ScopedCache { ScopedCache(cache: self, scope: scope) }

    /// Stabile, nicht umkehrbare Kennung für Server+Mandant+Benutzer.
    public static func scopeID(apiBase: String, tenant: String?, userID: Int) -> String {
        let hex = PQSHA256.hex(Data("\(apiBase)#\(tenant ?? "")".utf8))
        return "\(hex.prefix(16))-\(userID)"
    }
}

/// Ansicht auf einen Benutzer-Scope.
public struct ScopedCache: Sendable {
    public let cache: OfflineCache
    public let scope: String

    public func put<T: Encodable & Sendable>(_ key: String, _ value: T) async throws {
        try await cache.put(value, scope: scope, key: key)
    }

    public func get<T: Decodable & Sendable>(_ key: String, as type: T.Type = T.self) async -> Cached<T>? {
        await cache.get(type, scope: scope, key: key)
    }

    public func delete(_ key: String) async { await cache.delete(scope: scope, key: key) }
    public func keys(prefix: String = "") async -> [String] { await cache.keys(scope: scope, prefix: prefix) }
    public func clear() async { await cache.clear(scope: scope) }
}

/// Cache-Schlüssel (wie die Capacitor-App).
public enum CacheKeys {
    public static func plan(_ month: String) -> String { "plan:\(month)" }
    public static func team(_ month: String) -> String { "team:\(month)" }
    public static func absences(_ year: Int) -> String { "abs:\(year)" }
    public static func details(_ date: ISODay, _ userID: Int) -> String { "det:\(date):\(userID)" }
    public static let notifications = "notifications"
    public static let me = "me"
    public static let cursor = "meta:cursor"
    public static let calendar = "calendar"
}

/// Ergebnis eines Ladevorgangs mit Offline-Fallback.
public struct Loaded<T> {
    public var data: T
    public var fromCache: Bool
    /// Zeitpunkt, zu dem die Daten zuletzt vom Server kamen
    public var savedAt: Date
    /// Grund, warum Cache-Daten angezeigt werden („Offline“, „Wartungsmodus“)
    public var offlineReason: String?

    public init(data: T, fromCache: Bool, savedAt: Date, offlineReason: String? = nil) {
        self.data = data
        self.fromCache = fromCache
        self.savedAt = savedAt
        self.offlineReason = offlineReason
    }
}

extension Loaded: Sendable where T: Sendable {}

/// Lesen mit Offline-Fallback:
/// - online: vom Server laden und verschlüsselt cachen; bei Verbindungsfehler/Wartung Cache zeigen
/// - offline: direkt Cache zeigen; ohne Cache Fehler `network_error`
public func loadCached<T: Codable & Sendable>(_ cache: ScopedCache?, key: String, online: Bool, now: Date = Date(),
                                               fetch: @Sendable () async throws -> T) async throws -> Loaded<T> {
    func fromCache(_ reason: String, _ original: APIError) async throws -> Loaded<T> {
        guard let c = await cache?.get(key, as: T.self) else { throw original }
        return Loaded(data: c.data, fromCache: true, savedAt: c.savedAt, offlineReason: reason)
    }
    if !online { return try await fromCache("Offline", APIError(.networkError)) }
    do {
        let data = try await fetch()
        try? await cache?.put(key, data)
        return Loaded(data: data, fromCache: false, savedAt: now)
    } catch {
        let e = APIError.wrap(error)
        if e.isConnectivity { return try await fromCache("Offline", e) }
        if e.knownCode == .maintenance { return try await fromCache("Wartungsmodus", e) }
        throw e
    }
}

/// Schreibzugriffe nur online – offline wird nichts in eine Warteschlange gestellt.
public func requireOnline(_ online: Bool) throws {
    if !online { throw APIError(.offlineWrite) }
}
