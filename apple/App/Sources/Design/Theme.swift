import SwiftUI
import PlanquadroKit

/// Marken-Farben des Mandanten (oder Planquadro-Standard), kontrastgeprüft je Erscheinungsbild.
struct BrandTheme: Equatable {
    var primary: Color
    var primaryForeground: Color
    var primaryText: Color
    var accent: Color
    var accentText: Color
    var tenantName: String
    var logoURL: URL?

    init(branding: Branding, tenantName: String, dark: Bool) {
        let t = BrandTokens(primary: branding.primary, accent: branding.accent, dark: dark)
        primary = Color(hex: t.primary, fallback: .pqBlue)
        primaryForeground = Color(hex: t.primaryForeground, fallback: .white)
        primaryText = Color(hex: t.primaryText, fallback: .pqBlue)
        accent = Color(hex: t.accent, fallback: .pqOrange)
        accentText = Color(hex: t.accentText, fallback: .pqOrangeText)
        self.tenantName = tenantName
        // Logo nur über HTTPS (wie die Capacitor-App)
        logoURL = branding.logoURL.flatMap { URL(string: $0) }.flatMap { $0.scheme == "https" ? $0 : nil }
    }

    static let standard = BrandTheme(branding: .planquadro, tenantName: "Planquadro", dark: false)

    /// „Heute“-Markierung: Signal-Orange (Füllung) bzw. die Textvariante.
    var today: Color { .pqOrange }
    var todayText: Color { accentText }
}

private struct BrandThemeKey: EnvironmentKey {
    static let defaultValue = BrandTheme.standard
}

extension EnvironmentValues {
    var brand: BrandTheme {
        get { self[BrandThemeKey.self] }
        set { self[BrandThemeKey.self] = newValue }
    }
}

extension ThemeMode {
    var colorScheme: ColorScheme? {
        switch self {
        case .system: return nil
        case .light: return .light
        case .dark: return .dark
        }
    }
}

/// Flächen der App.
extension Color {
    static let pqSurface = Color("SurfaceBackground")
    static let pqCard = Color("CardBackground")
}
