import AuthenticationServices
import SwiftUI
import PlanquadroKit

/// Gemeinsamer Rahmen der Anmelde-Bildschirme: Tinte-Verlauf, Glas-Karte.
struct AuthScaffold<Content: View>: View {
    @ViewBuilder var content: () -> Content

    var body: some View {
        ZStack {
            LinearGradient(colors: [Color.pqInk, Color.pqInk.opacity(0.92), Color.pqBlue.opacity(0.55)],
                           startPoint: .top, endPoint: .bottom)
                .ignoresSafeArea()
            // dezentes Raster im Hintergrund (Planquadrat)
            AuthGridBackdrop()
                .ignoresSafeArea()
                .accessibilityHidden(true)
            ScrollView {
                VStack(spacing: 24) {
                    content()
                }
                .padding(24)
                .frame(maxWidth: 480)
                .pqGlassCard(cornerRadius: 32)
                .padding(.horizontal, 16)
                .padding(.vertical, 40)
                .frame(maxWidth: .infinity)
            }
            .scrollDismissesKeyboard(.interactively)
        }
        // Anmelde-Bildschirme immer hell auf Tinte – gleicher Kontrast in Hell und Dunkel
        .environment(\.colorScheme, .dark)
    }
}

private struct AuthGridBackdrop: View {
    var body: some View {
        GeometryReader { geo in
            let cell: CGFloat = 46
            let cols = Int(geo.size.width / cell) + 1
            let rows = Int(geo.size.height / cell) + 1
            Canvas { ctx, _ in
                for r in 0..<rows {
                    for c in 0..<cols {
                        let rect = CGRect(x: CGFloat(c) * cell + 4, y: CGFloat(r) * cell + 4, width: cell - 8, height: cell - 8)
                        let signal = (r * 7 + c * 3) % 23 == 0
                        ctx.fill(Path(roundedRect: rect, cornerRadius: 8),
                                 with: .color(signal ? Color.pqOrange.opacity(0.35) : Color.white.opacity(((r + c) % 3 == 0) ? 0.06 : 0.025)))
                    }
                }
            }
        }
    }
}

/// Erster Start: Server-Adresse und optionaler Mandant (SaaS).
struct SetupView: View {
    @Environment(AppModel.self) private var model
    @State private var server = ""
    @State private var tenant = ""
    @State private var busy = false
    @State private var error: String?
    @FocusState private var focus: Field?

    private enum Field { case server, tenant }

    var body: some View {
        AuthScaffold {
            BrandHeader(title: "Planquadro", subtitle: "Jeder Einsatz an seinem Platz.")
            VStack(alignment: .leading, spacing: 16) {
                if let error {
                    Label(error, systemImage: "exclamationmark.triangle.fill")
                        .foregroundStyle(Color.pqError)
                        .font(.callout)
                        .accessibilityAddTraits(.isStaticText)
                }
                VStack(alignment: .leading, spacing: 6) {
                    Text("Server-Adresse").font(.subheadline.weight(.semibold))
                    TextField("https://planquadro.firma.de", text: $server)
                        .textContentType(.URL)
                        .keyboardType(.URL)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled()
                        .submitLabel(.next)
                        .focused($focus, equals: .server)
                        .onSubmit { focus = .tenant }
                        .padding(12)
                        .background(Color.white.opacity(0.1), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                    Text("Die Adresse erhalten Sie von Ihrer Planung bzw. Administration.")
                        .font(.footnote)
                        .foregroundStyle(.secondary)
                }
                VStack(alignment: .leading, spacing: 6) {
                    Text("Mandanten-Kürzel (optional)").font(.subheadline.weight(.semibold))
                    TextField("z. B. acme", text: $tenant)
                        .textContentType(.organizationName)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled()
                        .submitLabel(.go)
                        .focused($focus, equals: .tenant)
                        .onSubmit { Task { await submit() } }
                        .padding(12)
                        .background(Color.white.opacity(0.1), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                    Text("Nur nötig, wenn Ihr Unternehmen Planquadro als Cloud-Dienst über eine gemeinsame Adresse nutzt.")
                        .font(.footnote)
                        .foregroundStyle(.secondary)
                }
                Button {
                    Task { await submit() }
                } label: {
                    Group {
                        if busy { ProgressView() } else { Text("Weiter").font(.headline) }
                    }
                    .frame(maxWidth: .infinity, minHeight: 30)
                }
                .pqProminentButton()
                .controlSize(.large)
                .disabled(busy)
                .accessibilityIdentifier("setup.continue")

                Button("Demo-Modus starten (ohne Server)") {
                    Task { await model.session.startDemo() }
                }
                .frame(maxWidth: .infinity)
                .pqGlassButton()
                .accessibilityIdentifier("setup.demo")
            }
        }
        .onAppear {
            if server.isEmpty, let s = model.session.prefs.serverURL, let u = URL(string: s) { server = ServerAddress.display(u) }
            tenant = model.session.prefs.tenant ?? ""
        }
    }

    private func submit() async {
        error = nil
        busy = true
        defer { busy = false }
        do {
            try await model.session.setServer(server, tenant: tenant)
        } catch {
            self.error = (error as? ServerAddress.InvalidAddress)?.message ?? APIError.wrap(error).userMessage
            focus = .server
        }
    }
}

struct LoginView: View {
    @Environment(AppModel.self) private var model
    @Environment(\.brand) private var brand
    @Environment(\.webAuthenticationSession) private var webAuthenticationSession
    @State private var username = ""
    @State private var password = ""
    @State private var busy = false
    @State private var error: String?
    @FocusState private var focus: Field?

    private enum Field { case user, password }

    var body: some View {
        let session = model.session
        let cfg = session.config
        let passwordEnabled = cfg?.auth.password ?? true
        AuthScaffold {
            BrandHeader(title: session.tenantName,
                        subtitle: session.isDemo ? "Demo-Modus – Beispieldaten" : session.serverDisplay,
                        logoURL: brand.logoURL)
            VStack(alignment: .leading, spacing: 14) {
                if let notice = session.loginMessage {
                    Label(notice, systemImage: "info.circle.fill")
                        .font(.callout)
                        .foregroundStyle(.secondary)
                }
                if let error {
                    Label(error, systemImage: "exclamationmark.triangle.fill")
                        .font(.callout)
                        .foregroundStyle(Color.pqError)
                        .accessibilityIdentifier("login.error")
                }
                if passwordEnabled {
                    TextField("Benutzername", text: $username)
                        .textContentType(.username)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled()
                        .submitLabel(.next)
                        .focused($focus, equals: .user)
                        .onSubmit { focus = .password }
                        .padding(12)
                        .background(Color.white.opacity(0.1), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                        .accessibilityIdentifier("login.username")
                    SecureField("Passwort", text: $password)
                        .textContentType(.password)
                        .submitLabel(.go)
                        .focused($focus, equals: .password)
                        .onSubmit { Task { await login() } }
                        .padding(12)
                        .background(Color.white.opacity(0.1), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                        .accessibilityIdentifier("login.password")
                    Button {
                        Task { await login() }
                    } label: {
                        Group {
                            if busy { ProgressView() } else { Text("Anmelden").font(.headline) }
                        }
                        .frame(maxWidth: .infinity, minHeight: 30)
                    }
                    .pqProminentButton()
                    .controlSize(.large)
                    .disabled(busy)
                    .accessibilityIdentifier("login.submit")
                }
                if let cfg, cfg.oidcAvailable {
                    if passwordEnabled {
                        HStack {
                            Rectangle().frame(height: 0.5).foregroundStyle(.secondary)
                            Text("oder").font(.footnote).foregroundStyle(.secondary)
                            Rectangle().frame(height: 0.5).foregroundStyle(.secondary)
                        }
                    }
                    Button {
                        Task { await oidc() }
                    } label: {
                        Label(cfg.auth.oidc.label ?? "Mit Firmenkonto anmelden", systemImage: "building.2")
                            .frame(maxWidth: .infinity, minHeight: 30)
                    }
                    .pqGlassButton()
                    .controlSize(.large)
                    .disabled(busy)
                }
                if session.isDemo {
                    Text("Demo: beliebiger Benutzername, Passwort „demo“. Testfälle: „gesperrt“, „neu“, „wartung“.")
                        .font(.footnote)
                        .foregroundStyle(.secondary)
                }
                Button("Server ändern") {
                    Task { await session.changeServer() }
                }
                .font(.subheadline)
                .frame(maxWidth: .infinity)
                .padding(.top, 4)
            }
        }
        .task {
            if session.config == nil { _ = try? await session.loadConfig() }
        }
    }

    private func login() async {
        error = nil
        guard !username.trimmingCharacters(in: .whitespaces).isEmpty, !password.isEmpty else {
            error = "Bitte Benutzername und Passwort eingeben."
            return
        }
        busy = true
        defer { busy = false }
        do {
            try await model.session.login(username: username, password: password)
            password = ""
        } catch {
            self.error = loginErrorMessage(error)
            password = ""
            focus = .password
        }
    }

    /// Anmeldung per Firmenkonto: ASWebAuthenticationSession (PKCE S256, state, nonce,
    /// Rücksprung `planquadro://auth`).
    private func oidc() async {
        error = nil
        busy = true
        defer { busy = false }
        do {
            let url = try await model.session.beginOIDC()
            let callback = try await webAuthenticationSession.authenticate(using: url, callbackURLScheme: PKCE.callbackScheme,
                                                                           preferredBrowserSession: .shared)
            try await model.session.completeOIDC(callback: callback)
        } catch let e as ASWebAuthenticationSessionError where e.code == .canceledLogin {
            model.session.cancelOIDC()
        } catch {
            model.session.cancelOIDC()
            self.error = (error as? PKCE.AuthError)?.message ?? loginErrorMessage(error)
        }
    }
}

/// Biometrische Sperre: Das Refresh-Token wird erst nach erfolgreicher Prüfung gelesen.
struct LockView: View {
    @Environment(AppModel.self) private var model
    @State private var message: String?

    var body: some View {
        let info = model.session.biometryInfo
        AuthScaffold {
            Image(systemName: info.label == "Touch ID" ? "touchid" : "faceid")
                .font(.system(size: 56, weight: .light))
                .foregroundStyle(Color.pqOrange)
                .accessibilityHidden(true)
            Text("Planquadro ist gesperrt")
                .font(.title2.weight(.bold))
                .accessibilityAddTraits(.isHeader)
            if let message {
                Text(message).font(.callout).foregroundStyle(.secondary)
            }
            Button {
                Task { await unlock() }
            } label: {
                Label("Mit \(info.label) entsperren", systemImage: "lock.open")
                    .frame(maxWidth: .infinity, minHeight: 30)
            }
            .pqProminentButton()
            .controlSize(.large)
            Button("Abmelden", role: .destructive) {
                Task { await model.session.logout() }
            }
        }
        .task { await unlock() }
    }

    private func unlock() async {
        message = nil
        let ok = await model.session.unlock()
        if !ok && model.session.status == .locked { message = "Entsperren abgebrochen. Bitte erneut versuchen." }
    }
}

struct UnavailableView: View {
    @Environment(AppModel.self) private var model
    var message: String

    var body: some View {
        AuthScaffold {
            Image(systemName: "icloud.slash")
                .font(.system(size: 52, weight: .light))
                .foregroundStyle(Color.pqOrange)
                .accessibilityHidden(true)
            Text("Nicht erreichbar")
                .font(.title2.weight(.bold))
                .accessibilityAddTraits(.isHeader)
            Text(message)
                .font(.callout)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)
            Button {
                Task { await model.session.retry() }
            } label: {
                Text("Erneut versuchen").frame(maxWidth: .infinity, minHeight: 30)
            }
            .pqProminentButton()
            .controlSize(.large)
            Button("Abmelden", role: .destructive) {
                Task { await model.session.logout() }
            }
        }
    }
}
