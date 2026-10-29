import Foundation

/// Planquadro-Farben laut docs/BRAND.md.
public enum BrandColors {
    public static let inkHex = "#14213D"
    public static let quadroBlueHex = "#2557D6"
    public static let quadroBlueLightHex = "#4C7BF0"
    public static let quadroBlueSurfaceHex = "#E8EEFC"
    public static let quadroBlueDarkHex = "#1B44AE"
    public static let signalOrangeHex = "#FF7A1A"
    public static let signalOrangeTextHex = "#C2410C"
    public static let successHex = "#16A34A"
    public static let warningHex = "#D97706"
    public static let errorHex = "#DC2626"

    /// Flächen je Erscheinungsbild (wie die Capacitor-App).
    public static let lightBackgroundHex = "#F3F4F6"
    public static let lightCardHex = "#FFFFFF"
    public static let darkBackgroundHex = "#0B1220"
    public static let darkCardHex = "#111A2B"
}

/// RGB-Farbe (0–255) mit WCAG-Kontrastberechnung.
public struct RGB: Hashable, Sendable {
    public var r: Double
    public var g: Double
    public var b: Double

    public init(r: Double, g: Double, b: Double) {
        self.r = r
        self.g = g
        self.b = b
    }

    public static let white = RGB(r: 255, g: 255, b: 255)
    public static let black = RGB(r: 0, g: 0, b: 0)

    /// `#rgb`, `#rrggbb` (mit/ohne #) → RGB; ungültig → nil
    public init?(hex input: String?) {
        guard var s = input?.trimmingCharacters(in: .whitespaces) else { return nil }
        if s.hasPrefix("#") { s.removeFirst() }
        if s.count == 3 { s = s.map { "\($0)\($0)" }.joined() }
        guard s.count == 6, s.allSatisfy({ $0.isHexDigit }), let v = UInt32(s, radix: 16) else { return nil }
        r = Double((v >> 16) & 0xFF)
        g = Double((v >> 8) & 0xFF)
        b = Double(v & 0xFF)
    }

    public var hex: String {
        func h(_ x: Double) -> String {
            let v = Int(min(255, max(0, x.rounded())))
            let s = String(v, radix: 16)
            return s.count == 1 ? "0" + s : s
        }
        return "#\(h(r))\(h(g))\(h(b))"
    }

    /// Relative Leuchtdichte nach WCAG
    public var luminance: Double {
        func ch(_ v: Double) -> Double {
            let c = v / 255
            return c <= 0.03928 ? c / 12.92 : pow((c + 0.055) / 1.055, 2.4)
        }
        return 0.2126 * ch(r) + 0.7152 * ch(g) + 0.0722 * ch(b)
    }

    public func contrast(with other: RGB) -> Double {
        let a = luminance, b = other.luminance
        return (max(a, b) + 0.05) / (min(a, b) + 0.05)
    }

    func mixed(with o: RGB, _ t: Double) -> RGB {
        RGB(r: r + (o.r - r) * t, g: g + (o.g - g) * t, b: b + (o.b - b) * t)
    }
}

public enum ColorContrast {
    /// Lesbare Vordergrundfarbe (Weiß oder fast Schwarz) für einen Hintergrund.
    public static func readableForeground(on bg: String, dark: String = "#111827", light: String = "#ffffff") -> String {
        guard let rgb = RGB(hex: bg) else { return dark }
        let d = RGB(hex: dark) ?? .black
        let l = RGB(hex: light) ?? .white
        return rgb.contrast(with: l) >= rgb.contrast(with: d) ? light : dark
    }

    /// Server-Vordergrundfarbe verwenden, solange sie lesbar ist (≥ `min`), sonst berechnen.
    public static func safeForeground(bg: String, fg: String?, min: Double = 4.5) -> String {
        if let b = RGB(hex: bg), let f = RGB(hex: fg), b.contrast(with: f) >= min { return f.hex }
        return readableForeground(on: bg)
    }

    /// Passt `color` an, bis sie auf `bg` mindestens `min` Kontrast hat (Farbton bleibt möglichst erhalten).
    public static func ensureContrast(_ color: String, on bg: String, min: Double = 4.5) -> String {
        guard let c = RGB(hex: color), let b = RGB(hex: bg) else { return color }
        if c.contrast(with: b) >= min { return c.hex }
        let target: RGB = b.luminance < 0.5 ? .white : .black
        var t = 0.05
        while t <= 1.0001 {
            let m = c.mixed(with: target, t)
            if m.contrast(with: b) >= min { return m.hex }
            t += 0.05
        }
        return target.hex
    }
}

/// Marken-Tokens für ein Erscheinungsbild, kontrastgeprüft (WCAG 1.4.3/1.4.11).
public struct BrandTokens: Hashable, Sendable {
    /// Füllfarbe für Buttons/aktive Elemente (≥ 3:1 zur Fläche)
    public var primary: String
    /// lesbarer Text auf `primary`
    public var primaryForeground: String
    /// Markenfarbe als Text/Link (≥ 4,5:1)
    public var primaryText: String
    public var accent: String
    public var accentForeground: String
    public var accentText: String

    public init(primary: String, accent: String, dark: Bool) {
        let surface = dark ? BrandColors.darkCardHex : BrandColors.lightCardHex
        let p = RGB(hex: primary)?.hex ?? RGB(hex: BrandColors.quadroBlueHex)!.hex
        let a = RGB(hex: accent)?.hex ?? RGB(hex: BrandColors.signalOrangeHex)!.hex
        let pFill = ColorContrast.ensureContrast(p, on: surface, min: 3)
        let aFill = ColorContrast.ensureContrast(a, on: surface, min: 3)
        self.primary = pFill
        primaryForeground = ColorContrast.readableForeground(on: pFill)
        primaryText = ColorContrast.ensureContrast(p, on: surface, min: 4.5)
        self.accent = aFill
        accentForeground = ColorContrast.readableForeground(on: aFill)
        accentText = ColorContrast.ensureContrast(a, on: surface, min: 4.5)
    }
}
