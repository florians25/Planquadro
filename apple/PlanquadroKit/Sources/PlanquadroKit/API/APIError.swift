import Foundation

/// Fehler der API (Vertragsformat `{error:{code,message}}`) und clientseitige Fehler.
public struct APIError: Error, Hashable, Sendable, LocalizedError, CustomStringConvertible {
    /// Vertragscodes laut docs/API.md und zusätzliche Client-Codes.
    public enum Code: String, Sendable {
        case invalidRequest = "invalid_request"
        case invalidCredentials = "invalid_credentials"
        case invalidToken = "invalid_token"
        case tokenExpired = "token_expired"
        case forbidden
        case passwordChangeRequired = "password_change_required"
        case moduleDisabled = "module_disabled"
        case accountLocked = "account_locked"
        case notFound = "not_found"
        case conflict
        case rateLimited = "rate_limited"
        case maintenance
        // nur clientseitig
        case networkError = "network_error"
        case timeout
        case serverError = "server_error"
        case sessionExpired = "session_expired"
        case notConfigured = "not_configured"
        case offlineWrite = "offline_write"
        case appLocked = "app_locked"
        case cancelled
    }

    public let status: Int
    public let code: String
    /// Meldung des Servers (bereits deutsch), falls vorhanden.
    public let serverMessage: String?
    public let retryAfter: Int?

    public init(status: Int, code: String, serverMessage: String? = nil, retryAfter: Int? = nil) {
        self.status = status
        self.code = code
        let m = serverMessage?.trimmingCharacters(in: .whitespacesAndNewlines)
        self.serverMessage = (m?.isEmpty ?? true) ? nil : m
        self.retryAfter = retryAfter
    }

    public init(status: Int = 0, _ code: Code, serverMessage: String? = nil, retryAfter: Int? = nil) {
        self.init(status: status, code: code.rawValue, serverMessage: serverMessage, retryAfter: retryAfter)
    }

    public var knownCode: Code? { Code(rawValue: code) }

    /// Fehler, bei denen ein Lesezugriff aus dem Offline-Cache bedient werden darf.
    public var isConnectivity: Bool { knownCode == .networkError || knownCode == .timeout }

    /// Lesen aus dem Cache erlaubt (Verbindung weg oder Wartung).
    public var allowsCachedRead: Bool { isConnectivity || knownCode == .maintenance }

    /// Fehler, die eine erneute Anmeldung erfordern.
    public var requiresLogin: Bool { knownCode == .invalidToken || knownCode == .sessionExpired }

    /// Für die Anzeige: Servertext bevorzugen, sonst deutscher Standardtext.
    public var userMessage: String {
        if knownCode == .rateLimited, let r = retryAfter, r > 0 {
            return "Zu viele Anfragen. Bitte warten Sie \(r) Sekunden."
        }
        // Für diese Codes ist der eigene Text handlungsorientierter als ein generischer Servertext.
        if knownCode == .passwordChangeRequired || knownCode == .networkError { return Self.defaultMessage(code) }
        return serverMessage ?? Self.defaultMessage(code)
    }

    public var errorDescription: String? { userMessage }
    public var description: String { "APIError(\(status), \(code))" }

    public static func defaultMessage(_ code: String) -> String {
        switch Code(rawValue: code) {
        case .invalidRequest: return "Die Anfrage war ungültig."
        case .invalidCredentials: return "Benutzername oder Passwort ist falsch."
        case .invalidToken: return "Ihre Anmeldung ist nicht mehr gültig. Bitte melden Sie sich erneut an."
        case .tokenExpired: return "Ihre Anmeldung ist abgelaufen."
        case .forbidden: return "Dafür fehlt Ihnen die Berechtigung."
        case .passwordChangeRequired:
            return "Sie müssen Ihr Passwort zuerst in der Weboberfläche von Planquadro ändern. Danach können Sie sich hier anmelden."
        case .moduleDisabled: return "Diese Funktion ist für Ihre Organisation nicht aktiviert."
        case .accountLocked: return "Ihr Konto ist gesperrt. Bitte wenden Sie sich an Ihre Planung oder Administration."
        case .notFound: return "Der Eintrag wurde nicht gefunden."
        case .conflict: return "Der Eintrag wurde zwischenzeitlich geändert. Bitte laden Sie neu."
        case .rateLimited: return "Zu viele Anfragen. Bitte warten Sie kurz."
        case .maintenance: return "Planquadro wird gerade gewartet. Bitte versuchen Sie es später erneut."
        case .networkError: return "Keine Verbindung zum Server. Bitte prüfen Sie Ihre Internetverbindung."
        case .timeout: return "Der Server antwortet nicht rechtzeitig."
        case .serverError: return "Der Server hat einen Fehler gemeldet."
        case .sessionExpired: return "Ihre Sitzung ist abgelaufen. Bitte melden Sie sich erneut an."
        case .notConfigured: return "Es ist noch kein Server eingerichtet."
        case .offlineWrite: return "Ohne Verbindung können keine Änderungen gesendet werden. Bitte versuchen Sie es online erneut."
        case .appLocked: return "Die App ist gesperrt."
        case .cancelled: return "Abgebrochen."
        case nil: return "Unbekannter Fehler."
        }
    }

    /// Fallback-Code, wenn der Server kein Vertragsformat liefert.
    public static func fallbackCode(status: Int) -> Code {
        switch status {
        case 400: return .invalidRequest
        case 401: return .invalidToken
        case 403: return .forbidden
        case 404: return .notFound
        case 409: return .conflict
        case 429: return .rateLimited
        case 503: return .maintenance
        default: return .serverError
        }
    }

    /// Wandelt eine Nicht-2xx-Antwort in einen APIError.
    public static func from(status: Int, body: Data, retryAfterHeader: String?, now: Date = Date()) -> APIError {
        struct Envelope: Decodable {
            struct E: Decodable {
                var code: String?
                var message: String?
            }
            var error: E?
        }
        let env = try? JSONDecoder().decode(Envelope.self, from: body)
        let code = env?.error?.code ?? fallbackCode(status: status).rawValue
        return APIError(status: status, code: code, serverMessage: env?.error?.message, retryAfter: parseRetryAfter(retryAfterHeader, now: now))
    }

    static func parseRetryAfter(_ value: String?, now: Date) -> Int? {
        guard let v = value?.trimmingCharacters(in: .whitespaces), !v.isEmpty else { return nil }
        if let n = Int(v), n >= 0 { return n }
        let f = DateFormatter()
        f.locale = Locale(identifier: "en_US_POSIX")
        f.timeZone = TimeZone(identifier: "GMT")
        f.dateFormat = "EEE, dd MMM yyyy HH:mm:ss zzz"
        if let d = f.date(from: v) { return max(0, Int(d.timeIntervalSince(now).rounded())) }
        return nil
    }

    /// Beliebigen Fehler (URLError, Decoding, …) als APIError darstellen.
    public static func wrap(_ error: Error) -> APIError {
        if let e = error as? APIError { return e }
        if error is CancellationError { return APIError(.cancelled) }
        if let u = error as? URLError {
            switch u.code {
            case .timedOut: return APIError(.timeout)
            case .cancelled: return APIError(.cancelled)
            default: return APIError(.networkError)
            }
        }
        if error is DecodingError { return APIError(.serverError, serverMessage: "Ungültige Antwort vom Server.") }
        return APIError(.networkError)
    }
}

/// Meldungen für den Login je Fehlercode.
public func loginErrorMessage(_ error: Error) -> String {
    let e = APIError.wrap(error)
    switch e.knownCode {
    case .invalidCredentials: return "Benutzername oder Passwort ist falsch."
    case .accountLocked:
        return e.serverMessage ?? "Ihr Konto ist gesperrt. Bitte wenden Sie sich an Ihre Planung oder Administration."
    case .passwordChangeRequired:
        return "Sie müssen Ihr Passwort zuerst in der Planquadro-Weboberfläche ändern. Melden Sie sich danach hier mit dem neuen Passwort an."
    case .maintenance: return "Planquadro wird gerade gewartet. \(e.serverMessage ?? "Bitte versuchen Sie es später erneut.")"
    case .forbidden: return e.serverMessage ?? APIError.defaultMessage(e.code)
    case .networkError, .timeout: return "Keine Verbindung zum Server. Bitte prüfen Sie Ihre Internetverbindung."
    default: return e.userMessage
    }
}
