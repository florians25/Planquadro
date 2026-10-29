import Foundation

/// Eingabeprüfung für Server-Adresse und Mandanten-Kürzel (wie `mobile/src/lib/url.ts`).
public enum ServerAddress {
    /// Hosts, für die (nur in der Entwicklung) Klartext-HTTP erlaubt ist.
    public static let cleartextDevHosts: Set<String> = ["localhost", "127.0.0.1"]

    public struct InvalidAddress: Error, Hashable, Sendable, LocalizedError {
        public let message: String
        public var errorDescription: String? { message }
    }

    /// Normalisiert eine Server-Adresse auf die API-Basis `…/api/v1/`.
    /// - ohne Schema → https://
    /// - http:// nur für localhost/127.0.0.1
    /// - vorhandenes `/api/v1` wird nicht verdoppelt
    public static func normalize(_ input: String) throws -> URL {
        var s = input.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !s.isEmpty else { throw InvalidAddress(message: "Bitte geben Sie die Adresse Ihres Planquadro-Servers ein.") }
        if s.range(of: "^[a-zA-Z][a-zA-Z0-9+.-]*://", options: .regularExpression) == nil { s = "https://" + s }
        guard var c = URLComponents(string: s), let scheme = c.scheme?.lowercased(), let host = c.host, !host.isEmpty else {
            throw InvalidAddress(message: "Die Server-Adresse ist ungültig.")
        }
        guard scheme == "https" || scheme == "http" else {
            throw InvalidAddress(message: "Nur https://-Adressen werden unterstützt.")
        }
        if scheme == "http" && !cleartextDevHosts.contains(host.lowercased()) {
            throw InvalidAddress(message: "Aus Sicherheitsgründen ist nur https:// erlaubt (http:// nur für localhost in der Entwicklung).")
        }
        if c.user != nil || c.password != nil {
            throw InvalidAddress(message: "Die Adresse darf keine Zugangsdaten enthalten.")
        }
        guard host.range(of: "^[A-Za-z0-9.-]+$|^\\[[0-9A-Fa-f:.]+\\]$", options: .regularExpression) != nil else {
            throw InvalidAddress(message: "Die Server-Adresse ist ungültig.")
        }
        c.scheme = scheme
        c.host = host.lowercased()
        c.query = nil
        c.fragment = nil
        var path = c.path
        while path.hasSuffix("/") { path.removeLast() }
        if path.lowercased().hasSuffix("/api/v1") { path.removeLast(7) }
        c.path = path + "/api/v1/"
        guard let url = c.url else { throw InvalidAddress(message: "Die Server-Adresse ist ungültig.") }
        return url
    }

    /// Anzeigeform der Server-Adresse (ohne /api/v1/).
    public static func display(_ apiBase: URL) -> String {
        var s = apiBase.absoluteString
        if s.hasSuffix("/api/v1/") { s.removeLast(8) } else if s.hasSuffix("/api/v1") { s.removeLast(7) }
        return s
    }

    /// Web-Basis (für Links in die Weboberfläche, z. B. Profil).
    public static func webBase(_ apiBase: URL) -> URL {
        URL(string: display(apiBase)) ?? apiBase
    }

    /// Gültiger Mandanten-Slug (oder nil bei leerer Eingabe).
    public static func normalizeTenant(_ input: String) throws -> String? {
        let s = input.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        if s.isEmpty { return nil }
        guard s.range(of: "^[a-z0-9][a-z0-9-]{0,62}$", options: .regularExpression) != nil else {
            throw InvalidAddress(message: "Das Mandanten-Kürzel darf nur Kleinbuchstaben, Ziffern und Bindestriche enthalten.")
        }
        return s
    }
}
