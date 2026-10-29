import SwiftUI

// Liquid Glass (iOS 26) mit sauberem Rückfall auf Materials (iOS 17/18).
//
// - `#if compiler(>=6.2)`: nur Xcode 26+ kennt die neuen APIs; mit älterem Xcode wird
//   der Rückfall übersetzt.
// - `if #available(iOS 26.0, *)`: zur Laufzeit nur auf iOS 26+.

@available(iOS 26.0, *)
private func pqMakeGlass(tint: Color?, interactive: Bool) -> Glass {
    var g = Glass.regular
    if let tint { g = g.tint(tint) }
    if interactive { g = g.interactive() }
    return g
}

extension View {
    /// Glasfläche in `shape` (iOS 26: `.glassEffect`, sonst `.regularMaterial` mit feinem Rand).
    @ViewBuilder
    func pqGlass<S: Shape>(_ shape: S, tint: Color? = nil, interactive: Bool = false) -> some View {
        #if compiler(>=6.2)
        if #available(iOS 26.0, *) {
            glassEffect(pqMakeGlass(tint: tint, interactive: interactive), in: shape)
        } else {
            pqMaterialFallback(shape, tint: tint)
        }
        #else
        pqMaterialFallback(shape, tint: tint)
        #endif
    }

    @ViewBuilder
    private func pqMaterialFallback<S: Shape>(_ shape: S, tint: Color?) -> some View {
        background {
            ZStack {
                shape.fill(.regularMaterial)
                if let tint { shape.fill(tint.opacity(0.14)) }
            }
        }
        .overlay { shape.stroke(Color.primary.opacity(0.08), lineWidth: 0.5) }
    }

    /// Glas-Kapsel (Statuszeilen, Umschalter).
    func pqGlassCapsule(tint: Color? = nil, interactive: Bool = false) -> some View {
        pqGlass(Capsule(), tint: tint, interactive: interactive)
    }

    /// Glas-Karte mit abgerundeten Ecken.
    func pqGlassCard(cornerRadius: CGFloat = 22, tint: Color? = nil) -> some View {
        pqGlass(RoundedRectangle(cornerRadius: cornerRadius, style: .continuous), tint: tint)
    }

    /// Hervorgehobener Button: iOS 26 `.glassProminent`, sonst `.borderedProminent`.
    @ViewBuilder
    func pqProminentButton() -> some View {
        #if compiler(>=6.2)
        if #available(iOS 26.0, *) {
            buttonStyle(.glassProminent)
        } else {
            buttonStyle(.borderedProminent)
        }
        #else
        buttonStyle(.borderedProminent)
        #endif
    }

    /// Normaler Button: iOS 26 `.glass`, sonst `.bordered`.
    @ViewBuilder
    func pqGlassButton() -> some View {
        #if compiler(>=6.2)
        if #available(iOS 26.0, *) {
            buttonStyle(.glass)
        } else {
            buttonStyle(.bordered)
        }
        #else
        buttonStyle(.bordered)
        #endif
    }

    /// Tab-Leiste verkleinert sich beim Scrollen (iOS 26).
    @ViewBuilder
    func pqTabBarMinimizeOnScroll() -> some View {
        #if compiler(>=6.2)
        if #available(iOS 26.0, *) {
            tabBarMinimizeBehavior(.onScrollDown)
        } else {
            self
        }
        #else
        self
        #endif
    }

    /// Weiche Kante unter Glas-Leisten (iOS 26).
    @ViewBuilder
    func pqSoftScrollEdge() -> some View {
        #if compiler(>=6.2)
        if #available(iOS 26.0, *) {
            scrollEdgeEffectStyle(.soft, for: .top)
        } else {
            self
        }
        #else
        self
        #endif
    }
}

/// Gruppiert Glasflächen, damit sie auf iOS 26 ineinanderfließen (`GlassEffectContainer`).
struct PQGlassGroup<Content: View>: View {
    var spacing: CGFloat?
    @ViewBuilder var content: () -> Content

    init(spacing: CGFloat? = nil, @ViewBuilder content: @escaping () -> Content) {
        self.spacing = spacing
        self.content = content
    }

    var body: some View {
        #if compiler(>=6.2)
        if #available(iOS 26.0, *) {
            GlassEffectContainer(spacing: spacing) { content() }
        } else {
            content()
        }
        #else
        content()
        #endif
    }
}
