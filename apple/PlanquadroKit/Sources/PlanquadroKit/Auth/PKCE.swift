import Foundation
#if canImport(Security)
import Security
#endif

/// OAuth 2.0 Authorization Code + PKCE (RFC 7636, S256) mit `state` und `nonce`.
public enum PKCE {
    /// Rücksprung-URL der App (bei Identity Provider und Server registriert).
    public static let redirectURI = "planquadro://auth"
    public static let callbackScheme = "planquadro"
    /// Gültigkeit einer gestarteten Anmeldung.
    public static let maxAge: TimeInterval = 10 * 60

    public static func base64URL(_ data: Data) -> String {
        data.base64EncodedString()
            .replacingOccurrences(of: "+", with: "-")
            .replacingOccurrences(of: "/", with: "_")
            .replacingOccurrences(of: "=", with: "")
    }

    /// Kryptografisch sichere Zufallsbytes.
    public static func randomBytes(_ count: Int) -> Data {
        var bytes = [UInt8](repeating: 0, count: count)
        #if canImport(Security)
        let status = SecRandomCopyBytes(kSecRandomDefault, count, &bytes)
        if status == errSecSuccess { return Data(bytes) }
        #endif
        var rng = SystemRandomNumberGenerator()
        for i in 0..<count { bytes[i] = UInt8.random(in: 0...255, using: &rng) }
        return Data(bytes)
    }

    public static func randomString(byteCount: Int = 32) -> String { base64URL(randomBytes(byteCount)) }

    /// `code_challenge` = BASE64URL(SHA256(verifier))
    public static func challengeS256(_ verifier: String) -> String {
        base64URL(PQSHA256.hash(Data(verifier.utf8)))
    }

    public struct Session: Codable, Hashable, Sendable {
        public var state: String
        public var nonce: String
        public var verifier: String
        public var redirectURI: String
        public var createdAt: Date

        public init(state: String, nonce: String, verifier: String, redirectURI: String, createdAt: Date) {
            self.state = state
            self.nonce = nonce
            self.verifier = verifier
            self.redirectURI = redirectURI
            self.createdAt = createdAt
        }
    }

    public struct AuthorizeRequest: Sendable, Hashable {
        public var url: URL
        public var session: Session
    }

    public struct AuthError: Error, Hashable, Sendable, LocalizedError {
        public let message: String
        public var errorDescription: String? { message }
    }

    /// Baut die Autorisierungs-URL. `authorize_url` aus `GET config` enthält bereits
    /// `client_id` (ggf. `scope`); fehlende Standardparameter werden ergänzt.
    public static func authorizeRequest(authorizeURL: String, redirectURI: String = PKCE.redirectURI, scope: String = "openid profile",
                                        now: Date = Date()) throws -> AuthorizeRequest {
        guard var c = URLComponents(string: authorizeURL), c.scheme?.lowercased() == "https" || c.host == "localhost" else {
            throw AuthError(message: "Die Anmeldeadresse des Firmenkontos ist ungültig.")
        }
        let verifier = randomString(byteCount: 48) // 64 Zeichen ≥ 43 (RFC 7636)
        let state = randomString(byteCount: 16)
        let nonce = randomString(byteCount: 16)
        var items = c.queryItems ?? []
        func setIfMissing(_ k: String, _ v: String) {
            if !items.contains(where: { $0.name == k }) { items.append(URLQueryItem(name: k, value: v)) }
        }
        func set(_ k: String, _ v: String) {
            items.removeAll { $0.name == k }
            items.append(URLQueryItem(name: k, value: v))
        }
        setIfMissing("response_type", "code")
        setIfMissing("scope", scope)
        set("redirect_uri", redirectURI)
        set("code_challenge", challengeS256(verifier))
        set("code_challenge_method", "S256")
        set("state", state)
        set("nonce", nonce)
        c.queryItems = items
        guard let url = c.url else { throw AuthError(message: "Die Anmeldeadresse des Firmenkontos ist ungültig.") }
        return AuthorizeRequest(url: url, session: Session(state: state, nonce: nonce, verifier: verifier, redirectURI: redirectURI, createdAt: now))
    }

    public struct Callback: Hashable, Sendable {
        public var code: String?
        public var state: String?
        public var error: String?
    }

    /// Liest `code`/`state`/`error` aus der Rücksprung-URL (Query oder Fragment).
    public static func parseCallback(_ url: URL) -> Callback {
        guard let c = URLComponents(url: url, resolvingAgainstBaseURL: false) else { return Callback() }
        var items = c.queryItems ?? []
        if items.isEmpty, let f = c.fragment, let fc = URLComponents(string: "?" + f) { items = fc.queryItems ?? [] }
        func v(_ n: String) -> String? { items.first { $0.name == n }?.value }
        return Callback(code: v("code"), state: v("state"), error: v("error"))
    }

    /// Prüft den Rücksprung gegen die gestartete Sitzung und liefert die Token-Anfrage.
    public static func tokenRequest(callback url: URL, session: Session?, deviceName: String?, now: Date = Date()) throws -> OIDCTokenRequest {
        let cb = parseCallback(url)
        if cb.error != nil {
            throw AuthError(message: "Die Anmeldung beim Firmenkonto wurde abgebrochen oder abgelehnt.")
        }
        guard let session, let code = cb.code, !code.isEmpty, cb.state == session.state,
              now.timeIntervalSince(session.createdAt) <= maxAge, now >= session.createdAt.addingTimeInterval(-60) else {
            throw AuthError(message: "Die Anmeldung konnte nicht zugeordnet werden. Bitte versuchen Sie es erneut.")
        }
        return OIDCTokenRequest(code: code, codeVerifier: session.verifier, nonce: session.nonce, redirectURI: session.redirectURI, deviceName: deviceName)
    }
}

/// Kompaktes SHA-256 (FIPS 180-4) ohne Abhängigkeiten – identisch auf Apple-Plattformen und Linux.
public enum PQSHA256 {
    private static let k: [UInt32] = [
        0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
        0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
        0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
        0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
        0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
        0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
        0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
        0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
    ]

    public static func hash(_ data: Data) -> Data {
        var h: [UInt32] = [0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19]
        var msg = [UInt8](data)
        let bitLen = UInt64(msg.count) * 8
        msg.append(0x80)
        while msg.count % 64 != 56 { msg.append(0) }
        for i in (0..<8).reversed() { msg.append(UInt8((bitLen >> (UInt64(i) * 8)) & 0xff)) }
        var w = [UInt32](repeating: 0, count: 64)
        for chunk in stride(from: 0, to: msg.count, by: 64) {
            for i in 0..<16 {
                let j = chunk + i * 4
                w[i] = UInt32(msg[j]) << 24 | UInt32(msg[j + 1]) << 16 | UInt32(msg[j + 2]) << 8 | UInt32(msg[j + 3])
            }
            for i in 16..<64 {
                let s0 = rotr(w[i - 15], 7) ^ rotr(w[i - 15], 18) ^ (w[i - 15] >> 3)
                let s1 = rotr(w[i - 2], 17) ^ rotr(w[i - 2], 19) ^ (w[i - 2] >> 10)
                w[i] = w[i - 16] &+ s0 &+ w[i - 7] &+ s1
            }
            var a = h[0], b = h[1], c = h[2], d = h[3], e = h[4], f = h[5], g = h[6], hh = h[7]
            for i in 0..<64 {
                let s1 = rotr(e, 6) ^ rotr(e, 11) ^ rotr(e, 25)
                let ch = (e & f) ^ (~e & g)
                let t1 = hh &+ s1 &+ ch &+ k[i] &+ w[i]
                let s0 = rotr(a, 2) ^ rotr(a, 13) ^ rotr(a, 22)
                let maj = (a & b) ^ (a & c) ^ (b & c)
                let t2 = s0 &+ maj
                hh = g; g = f; f = e; e = d &+ t1
                d = c; c = b; b = a; a = t1 &+ t2
            }
            h[0] = h[0] &+ a; h[1] = h[1] &+ b; h[2] = h[2] &+ c; h[3] = h[3] &+ d
            h[4] = h[4] &+ e; h[5] = h[5] &+ f; h[6] = h[6] &+ g; h[7] = h[7] &+ hh
        }
        var out = Data(capacity: 32)
        for v in h { out.append(contentsOf: [UInt8(v >> 24), UInt8((v >> 16) & 0xff), UInt8((v >> 8) & 0xff), UInt8(v & 0xff)]) }
        return out
    }

    public static func hex(_ data: Data) -> String {
        hash(data).map { b in
            let s = String(b, radix: 16)
            return s.count == 1 ? "0" + s : s
        }.joined()
    }

    @inline(__always) private static func rotr(_ x: UInt32, _ n: UInt32) -> UInt32 { (x >> n) | (x << (32 - n)) }
}
