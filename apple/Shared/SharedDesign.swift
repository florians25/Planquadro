import SwiftUI
import PlanquadroKit

extension Color {
    /// Farbe aus `#rrggbb`/`#rgb`; ungültig → `fallback`.
    init(hex: String?, fallback: Color = .gray) {
        guard let rgb = RGB(hex: hex) else {
            self = fallback
            return
        }
        self.init(.sRGB, red: rgb.r / 255, green: rgb.g / 255, blue: rgb.b / 255, opacity: 1)
    }

    /// Planquadro-Farben (docs/BRAND.md)
    static let pqInk = Color(hex: BrandColors.inkHex)
    static let pqBlue = Color(hex: BrandColors.quadroBlueHex)
    static let pqBlueLight = Color(hex: BrandColors.quadroBlueLightHex)
    static let pqOrange = Color(hex: BrandColors.signalOrangeHex)
    static let pqOrangeText = Color(hex: BrandColors.signalOrangeTextHex)
    static let pqSuccess = Color(hex: BrandColors.successHex)
    static let pqWarning = Color(hex: BrandColors.warningHex)
    static let pqError = Color(hex: BrandColors.errorHex)
}

/// Die Bildmarke „Planquadrat“: 3 × 3 Felder, volle Felder = geplant, helle = frei,
/// ein Feld in Signal-Orange (Anordnung fest, docs/BRAND.md).
struct PlanquadroMark: View {
    enum Style {
        /// Quadro-Blau auf hellem Grund
        case standard
        /// Weiß auf dunklem Grund (Tinte, Navigationsleiste)
        case onDark
        /// einfarbig (Widgets im getönten/akzentuierten Modus)
        case monochrome
    }

    var style: Style = .standard

    /// Muster zeilenweise: 1 = voll, 0 = frei, 2 = Signal
    private static let pattern = [1, 0, 1, 0, 1, 2, 1, 0, 0]

    var body: some View {
        GeometryReader { geo in
            let side = min(geo.size.width, geo.size.height)
            let cell = side * 0.25
            let gap = side * 0.0625
            let inset = (side - 3 * cell - 2 * gap) / 2
            ZStack(alignment: .topLeading) {
                ForEach(0..<9, id: \.self) { i in
                    RoundedRectangle(cornerRadius: cell * 0.25, style: .continuous)
                        .fill(color(Self.pattern[i]))
                        .frame(width: cell, height: cell)
                        .offset(x: inset + CGFloat(i % 3) * (cell + gap), y: inset + CGFloat(i / 3) * (cell + gap))
                }
            }
            .frame(width: side, height: side)
        }
        .aspectRatio(1, contentMode: .fit)
        .accessibilityHidden(true)
    }

    private func color(_ kind: Int) -> Color {
        switch (style, kind) {
        case (_, 2): return style == .monochrome ? .primary : .pqOrange
        case (.standard, 1): return .pqBlue
        case (.standard, _): return Color(hex: "#C9D5F5")
        case (.onDark, 1): return .white
        case (.onDark, _): return .white.opacity(0.28)
        case (.monochrome, 1): return .primary.opacity(0.85)
        case (.monochrome, _): return .primary.opacity(0.3)
        }
    }
}

/// Farbiges Kürzel eines Einsatzes/einer Abwesenheit (wie die Zellen der Planungsmatrix).
struct CodeBadge: View {
    var code: String
    var color: String
    var fg: String?
    var size: Size = .regular
    var highlighted = false

    enum Size {
        case cell, regular, large, hero

        var font: Font {
            switch self {
            case .cell: return .system(size: 12, weight: .bold, design: .rounded)
            case .regular: return .system(.footnote, design: .rounded).weight(.bold)
            case .large: return .system(.title3, design: .rounded).weight(.bold)
            case .hero: return .system(.largeTitle, design: .rounded).weight(.heavy)
            }
        }

        var minSide: CGFloat {
            switch self {
            case .cell: return 30
            case .regular: return 36
            case .large: return 52
            case .hero: return 76
            }
        }
    }

    var body: some View {
        let fgHex = ColorContrast.safeForeground(bg: color, fg: fg)
        Text(code)
            .font(size.font)
            .lineLimit(1)
            .minimumScaleFactor(0.6)
            .foregroundStyle(Color(hex: fgHex, fallback: .white))
            .padding(.horizontal, size == .cell ? 2 : 6)
            .frame(minWidth: size.minSide, minHeight: size.minSide)
            .background(Color(hex: color), in: RoundedRectangle(cornerRadius: size.minSide * 0.24, style: .continuous))
            .overlay {
                if highlighted {
                    RoundedRectangle(cornerRadius: size.minSide * 0.24, style: .continuous)
                        .strokeBorder(Color.pqOrange, lineWidth: 2.5)
                }
            }
    }
}

extension PublicEntry {
    var swiftUIColor: Color { Color(hex: color) }
}
