import SwiftUI
import PlanquadroKit

/// Ladezustand eines Bildschirms.
enum LoadState<T> {
    case loading
    case loaded(Loaded<T>)
    case failed(String)

    var value: T? {
        if case let .loaded(l) = self { return l.data }
        return nil
    }

    var loaded: Loaded<T>? {
        if case let .loaded(l) = self { return l }
        return nil
    }
}

/// Zeigt Laden/Fehler/Inhalt einheitlich an.
struct LoadedContent<T, Content: View>: View {
    var state: LoadState<T>
    var retry: () async -> Void
    @ViewBuilder var content: (Loaded<T>) -> Content

    var body: some View {
        switch state {
        case .loading:
            ProgressView("Wird geladen …")
                .frame(maxWidth: .infinity, maxHeight: .infinity)
        case let .failed(message):
            ErrorStateView(message: message, retry: retry)
        case let .loaded(l):
            content(l)
        }
    }
}

struct ErrorStateView: View {
    var message: String
    var retry: () async -> Void

    var body: some View {
        ContentUnavailableView {
            Label("Nicht geladen", systemImage: "wifi.exclamationmark")
        } description: {
            Text(message)
        } actions: {
            Button("Erneut versuchen") { Task { await retry() } }
                .pqProminentButton()
        }
    }
}

/// Hinweis „Offline – Stand: …“ bzw. „Wartungsmodus – Stand: …“ (deutlich gekennzeichnet).
struct DataStateBanner: View {
    var fromCache: Bool
    var savedAt: Date
    var reason: String?

    var body: some View {
        if fromCache {
            Label {
                Text("\(reason ?? "Offline") – Stand: \(PQFormat.dateTime(savedAt))")
                    .font(.footnote.weight(.semibold))
            } icon: {
                Image(systemName: reason == "Wartungsmodus" ? "wrench.and.screwdriver" : "icloud.slash")
            }
            .foregroundStyle(Color.pqOrangeText)
            .padding(.horizontal, 14)
            .padding(.vertical, 8)
            .pqGlassCapsule(tint: .pqOrange)
            .accessibilityElement(children: .combine)
            .accessibilityLabel("Offline-Lesemodus. \(reason ?? "Offline"), Stand \(PQFormat.dateTime(savedAt)). Änderungen sind erst wieder online möglich.")
            .accessibilityAddTraits(.isStaticText)
        }
    }
}

extension View {
    /// Blendet den Offline-Hinweis oben ein.
    func dataStateBanner<T>(_ loaded: Loaded<T>?) -> some View {
        safeAreaInset(edge: .top, spacing: 0) {
            if let l = loaded, l.fromCache {
                DataStateBanner(fromCache: true, savedAt: l.savedAt, reason: l.offlineReason)
                    .padding(.vertical, 6)
                    .frame(maxWidth: .infinity)
            }
        }
    }
}

/// Umschalter für Monat/Woche/Jahr: ‹ Titel › (+ „Heute“) als Glas-Kapsel.
struct PeriodSwitcher: View {
    var title: String
    var previousLabel: String
    var nextLabel: String
    var showToday: Bool
    var onPrevious: () -> Void
    var onNext: () -> Void
    var onToday: () -> Void = {}

    var body: some View {
        PQGlassGroup(spacing: 10) {
            HStack(spacing: 10) {
                HStack(spacing: 4) {
                    Button(action: onPrevious) {
                        Image(systemName: "chevron.left")
                            .frame(width: 36, height: 36)
                            .contentShape(Rectangle())
                    }
                    .accessibilityLabel(previousLabel)
                    .keyboardShortcut(.leftArrow, modifiers: .command)
                    Text(title)
                        .font(.headline)
                        .monospacedDigit()
                        .frame(minWidth: 150)
                        .accessibilityAddTraits(.isHeader)
                    Button(action: onNext) {
                        Image(systemName: "chevron.right")
                            .frame(width: 36, height: 36)
                            .contentShape(Rectangle())
                    }
                    .accessibilityLabel(nextLabel)
                    .keyboardShortcut(.rightArrow, modifiers: .command)
                }
                .padding(.horizontal, 6)
                .pqGlassCapsule(interactive: true)
                if showToday {
                    Button("Heute", action: onToday)
                        .font(.subheadline.weight(.semibold))
                        .padding(.horizontal, 14)
                        .frame(height: 44)
                        .pqGlassCapsule(tint: .pqOrange, interactive: true)
                        .keyboardShortcut("t", modifiers: .command)
                        .transition(.scale.combined(with: .opacity))
                }
            }
        }
        .buttonStyle(.plain)
        .animation(.snappy, value: showToday)
        .padding(.vertical, 6)
    }
}

struct StatusChip: View {
    var text: String
    var color: Color

    var body: some View {
        Text(text)
            .font(.caption.weight(.semibold))
            .padding(.horizontal, 10)
            .padding(.vertical, 4)
            .foregroundStyle(color)
            .background(color.opacity(0.14), in: Capsule())
    }
}

struct TodayTag: View {
    var body: some View {
        Text("Heute")
            .font(.caption2.weight(.bold))
            .textCase(.uppercase)
            .padding(.horizontal, 8)
            .padding(.vertical, 3)
            .foregroundStyle(.white)
            .background(Color.pqOrange, in: Capsule())
            .accessibilityHidden(true)
    }
}

/// Zeile Beschriftung → Wert.
struct KeyValueRow: View {
    var label: String
    var value: String

    var body: some View {
        LabeledContent(label) {
            Text(value)
                .multilineTextAlignment(.trailing)
                .textSelection(.enabled)
        }
    }
}

/// Vorübergehende Meldung (oben, Glas-Kapsel).
struct Toast: Equatable, Identifiable {
    enum Style { case ok, error, info }
    let id = UUID()
    var message: String
    var style: Style
}

struct ToastView: View {
    var toast: Toast

    var body: some View {
        Label(toast.message, systemImage: icon)
            .font(.subheadline.weight(.semibold))
            .foregroundStyle(color)
            .padding(.horizontal, 16)
            .padding(.vertical, 12)
            .pqGlassCapsule(tint: color)
            .padding(.horizontal)
            .accessibilityAddTraits(.isStaticText)
    }

    private var icon: String {
        switch toast.style {
        case .ok: return "checkmark.circle.fill"
        case .error: return "exclamationmark.triangle.fill"
        case .info: return "info.circle.fill"
        }
    }

    private var color: Color {
        switch toast.style {
        case .ok: return .pqSuccess
        case .error: return .pqError
        case .info: return .primary
        }
    }
}

/// Planquadro-Kopf (Bildmarke + Wortmarke) bzw. Mandantenlogo.
struct BrandHeader: View {
    var title: String
    var subtitle: String?
    var logoURL: URL?

    var body: some View {
        VStack(spacing: 12) {
            if let logoURL {
                AsyncImage(url: logoURL) { phase in
                    if let img = phase.image {
                        img.resizable().scaledToFit()
                    } else {
                        PlanquadroMark()
                    }
                }
                .frame(width: 72, height: 72)
                .accessibilityLabel(title)
            } else {
                PlanquadroMark(style: .onDark)
                    .frame(width: 64, height: 64)
                    .padding(14)
                    .background(Color.pqInk, in: RoundedRectangle(cornerRadius: 24, style: .continuous))
                    .shadow(color: .black.opacity(0.15), radius: 12, y: 6)
            }
            Text(title)
                .font(.largeTitle.weight(.bold))
                .multilineTextAlignment(.center)
                .accessibilityAddTraits(.isHeader)
            if let subtitle {
                Text(subtitle)
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
                    .multilineTextAlignment(.center)
            }
        }
        .frame(maxWidth: .infinity)
    }
}
