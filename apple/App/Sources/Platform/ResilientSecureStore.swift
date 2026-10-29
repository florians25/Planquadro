import Foundation
import Security
import PlanquadroKit

/// Keychain mit Rückfall auf den Arbeitsspeicher, falls der Keychain fehlende
/// Berechtigungen meldet (`errSecMissingEntitlement`, z. B. unsignierter Simulator-Build
/// im CI). Auf signierten Geräten wird immer der Keychain verwendet.
actor ResilientSecureStore: SecureStore {
    private let keychain: KeychainSecureStore
    private let memory = InMemorySecureStore()
    private var useMemory = false

    init(service: String) {
        keychain = KeychainSecureStore(service: service)
    }

    private func fallbackIfNeeded(_ error: Error) throws {
        if let e = error as? KeychainSecureStore.KeychainError, e.status == errSecMissingEntitlement {
            useMemory = true
            return
        }
        throw error
    }

    func get(_ key: String) async throws -> String? {
        if useMemory { return await memory.get(key) }
        do {
            return try await keychain.get(key)
        } catch {
            try fallbackIfNeeded(error)
            return await memory.get(key)
        }
    }

    func set(_ key: String, _ value: String) async throws {
        if useMemory { return await memory.set(key, value) }
        do {
            try await keychain.set(key, value)
        } catch {
            try fallbackIfNeeded(error)
            await memory.set(key, value)
        }
    }

    func remove(_ key: String) async throws {
        await memory.remove(key)
        if useMemory { return }
        do { try await keychain.remove(key) } catch { try fallbackIfNeeded(error) }
    }

    func clear() async throws {
        await memory.clear()
        if useMemory { return }
        do { try await keychain.clear() } catch { try fallbackIfNeeded(error) }
    }
}
