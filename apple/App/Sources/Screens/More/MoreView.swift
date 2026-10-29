import AppIntents
import SwiftUI
import UIKit
import PlanquadroKit

/// Mehr: Profil, Einstellungen, Kalender-Abo, Siri & Kurzbefehle, Widgets, Über, Abmelden.
struct MoreView: View {
    @Environment(AppModel.self) private var model
    @Environment(\.openURL) private var openURL
    @Environment(\.brand) private var brand
    @State private var confirm: Confirm?
    @State private var busy = false

    private enum Confirm: Identifiable {
        case logout, wipe, changeServer, export
        var id: Self { self }
    }

    var body: some View {
        let session = model.session
        let me = session.me
        let bio = session.biometryInfo
        List {
            if let me {
                Section {
                    HStack(spacing: 14) {
                        Text(me.user.initials)
                            .font(.title2.weight(.bold))
                            .foregroundStyle(brand.primaryForeground)
                            .frame(width: 56, height: 56)
                            .background(brand.primary, in: Circle())
                            .accessibilityHidden(true)
                        VStack(alignment: .leading, spacing: 2) {
                            Text(me.user.fullName).font(.title3.weight(.semibold))
                            Text("\(me.user.roleLabel) · \(me.tenant.name)").font(.subheadline).foregroundStyle(.secondary)
                            Text(me.user.username).font(.caption).foregroundStyle(.tertiary)
                        }
                    }
                    .padding(.vertical, 4)
                    .accessibilityElement(children: .combine)
                }
            }

            Section("Server") {
                if session.isDemo {
                    LabeledContent("Adresse") { StatusChip(text: "Demo-Modus", color: .pqOrangeText) }
                    Text("Beispieldaten, kein Server.").font(.footnote).foregroundStyle(.secondary)
                } else {
                    KeyValueRow(label: "Adresse", value: session.serverDisplay)
                    if let t = session.prefs.tenant { KeyValueRow(label: "Mandanten-Kürzel", value: t) }
                }
                LabeledContent("Live-Aktualisierung") {
                    Text(liveText).foregroundStyle(.secondary)
                }
                Button("Server wechseln") { confirm = .changeServer }
                if session.isDemo {
                    Button("Demo: Planänderung simulieren") {
                        Task {
                            let r = await session.simulateDemoChange()
                            model.show(r.map { "Synchronisiert: \($0.changedDates.count) geänderte Tage." } ?? "Sync fehlgeschlagen.", style: r == nil ? .error : .ok)
                        }
                    }
                }
            }

            Section {
                Toggle(isOn: Binding(get: { session.prefs.biometric }, set: { on in
                    Task {
                        let r = await session.setBiometric(on)
                        model.show(r.message, style: r.ok ? .ok : .error)
                    }
                })) {
                    VStack(alignment: .leading, spacing: 2) {
                        Text("Mit \(bio.label) entsperren")
                        Text(bio.available ? "Nach dem Start und nach mehr als 5 Minuten im Hintergrund." : (bio.reason ?? "Nicht verfügbar."))
                            .font(.footnote)
                            .foregroundStyle(.secondary)
                    }
                }
                .disabled(!bio.available && !session.prefs.biometric)
            } header: {
                Text("Sicherheit")
            }

            Section("Darstellung") {
                Picker("Farbschema", selection: Binding(get: { session.prefs.theme }, set: { session.setTheme($0) })) {
                    ForEach(ThemeMode.allCases) { Text($0.label).tag($0) }
                }
                .pickerStyle(.segmented)
            }

            Section {
                if me?.modules.ical ?? false {
                    Button {
                        Task { await subscribeCalendar() }
                    } label: {
                        Label("Kalender abonnieren", systemImage: "calendar.badge.clock")
                    }
                    .disabled(busy)
                    Button {
                        Task { await copyCalendarLink() }
                    } label: {
                        Label("Abo-Link kopieren", systemImage: "doc.on.doc")
                    }
                    .disabled(busy)
                }
                Button {
                    confirm = .export
                } label: {
                    Label("Einsätze in Kalender übernehmen", systemImage: "calendar.badge.plus")
                }
            } header: {
                Text("Kalender")
            } footer: {
                Text("Das Abo hält Apple Kalender automatisch aktuell. Beim Übernehmen werden nur Einsätze dieses und des nächsten Monats eingetragen, keine Abwesenheiten.")
            }

            Section {
                SiriTipView(intent: NextAssignmentIntent())
                ShortcutsLink()
                    .shortcutsLinkStyle(.automatic)
                Toggle("Einsätze in der Suche (Spotlight)", isOn: Binding(get: { session.prefs.spotlight }, set: { model.setSpotlight($0) }))
                if model.liveActivities.isSupported {
                    Toggle("Live-Aktivität für heutigen Einsatz", isOn: Binding(get: { session.prefs.liveActivity }, set: { on in
                        session.setLiveActivity(on)
                        Task {
                            if on { await model.startLiveActivityForToday() } else { await model.liveActivities.endAll() }
                        }
                    }))
                }
                NavigationLink(value: Route.widgetGallery) {
                    Label("Widgets", systemImage: "square.grid.2x2")
                }
            } header: {
                Text("Siri, Suche und Widgets")
            } footer: {
                Text("Widgets, Sperrbildschirm und Siri zeigen nur Ihre eigenen Einsätze; Abwesenheiten erscheinen dort nur als „Abwesend“.")
            }

            Section {
                NavigationLink(value: Route.about) {
                    Label("Über Planquadro", systemImage: "info.circle")
                }
            }

            Section {
                Button("Abmelden") { confirm = .logout }
                    .accessibilityIdentifier("more.logout")
                Button("Lokale Daten löschen", role: .destructive) { confirm = .wipe }
            }
        }
        .listStyle(.insetGrouped)
        .navigationTitle("Mehr")
        .confirmationDialog(confirmTitle, isPresented: Binding(get: { confirm != nil }, set: { if !$0 { confirm = nil } }),
                            titleVisibility: .visible, presenting: confirm) { c in
            switch c {
            case .logout:
                Button("Abmelden", role: .destructive) { Task { await session.logout() } }
            case .wipe:
                Button("Löschen", role: .destructive) {
                    Task {
                        await session.wipeLocal()
                        model.show("Lokale Daten gelöscht.", style: .ok)
                    }
                }
            case .changeServer:
                Button("Wechseln", role: .destructive) { Task { await session.changeServer() } }
            case .export:
                Button("Übernehmen") { Task { await exportTwoMonths() } }
            }
            Button("Abbrechen", role: .cancel) {}
        } message: { c in
            switch c {
            case .logout: Text("Alle lokal gespeicherten Daten werden von diesem Gerät gelöscht.")
            case .wipe: Text("Sie werden abgemeldet. Offline-Daten, Schlüssel und Einstellungen inkl. Server-Adresse werden gelöscht.")
            case .changeServer: Text("Sie werden abgemeldet und alle lokalen Daten werden gelöscht.")
            case .export: Text("Ihre Einsätze dieses und des nächsten Monats werden als ganztägige Termine eingetragen.")
            }
        }
    }

    private var confirmTitle: String {
        switch confirm {
        case .logout: return "Abmelden?"
        case .wipe: return "Lokale Daten löschen?"
        case .changeServer: return "Server wechseln?"
        case .export: return "In Kalender übernehmen?"
        case nil: return ""
        }
    }

    private var liveText: String {
        if model.session.isDemo { return "aus (Demo)" }
        if !model.session.online { return "offline" }
        switch model.session.liveState {
        case .connected: return "verbunden"
        case .connecting: return "verbindet …"
        case .unavailable: return "nicht verfügbar"
        case .stopped: return "aus"
        }
    }

    private func subscribeCalendar() async {
        busy = true
        defer { busy = false }
        do {
            let sub = try await model.session.calendarSubscription(create: true)
            if let url = sub.webcalURL { openURL(url) }
        } catch {
            model.show(error: error)
        }
    }

    private func copyCalendarLink() async {
        busy = true
        defer { busy = false }
        do {
            let sub = try await model.session.calendarSubscription(create: true)
            UIPasteboard.general.string = sub.url
            model.show("Abo-Link kopiert. Der Link ist privat – bitte nicht weitergeben.", style: .ok)
        } catch {
            model.show(error: error)
        }
    }

    private func exportTwoMonths() async {
        guard let ym = YearMonth(key: model.session.today) else { return }
        do {
            var days: [PlanDay] = []
            for m in [ym, ym.adding(months: 1)] {
                days += try await model.session.myPlan(m).data.filledDays
            }
            let today = model.session.today
            let r = try await model.calendar.export(days: days.filter { $0.date >= today }, tenantName: model.session.tenantName)
            model.show("\(r.added) \(r.added == 1 ? "Einsatz" : "Einsätze") übernommen" + (r.skipped > 0 ? ", \(r.skipped) bereits vorhanden." : "."), style: .ok)
        } catch {
            model.show(error.localizedDescription, style: .error)
        }
    }
}

struct AboutView: View {
    @Environment(AppModel.self) private var model

    var body: some View {
        List {
            Section {
                VStack(spacing: 10) {
                    PlanquadroMark().frame(width: 64, height: 64)
                    Text("Planquadro").font(.title.weight(.bold))
                    Text("Einsatz- und Teamplanung für Service-Organisationen")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                        .multilineTextAlignment(.center)
                    Text("Jeder Einsatz an seinem Platz.")
                        .font(.footnote.italic())
                        .foregroundStyle(Color.pqOrangeText)
                }
                .frame(maxWidth: .infinity)
                .padding(.vertical, 8)
            }
            Section("Version") {
                KeyValueRow(label: "App", value: "\(model.appVersion) (\(model.buildNumber))")
                KeyValueRow(label: "API", value: "v1")
                KeyValueRow(label: "Mandant", value: model.session.tenantName)
            }
            Section("Datenschutz") {
                Text("Keine Analyse-, Tracking- oder Werbedienste. Die App spricht ausschließlich mit dem Planquadro-Server Ihrer Organisation.")
                Text("Anmeldedaten liegen im Schlüsselbund dieses Geräts (nicht in iCloud). Offline-Daten werden verschlüsselt gespeichert und beim Abmelden gelöscht.")
                Text("Gründe von Abwesenheiten anderer sehen Sie nie – nur „A“. Widgets, Sperrbildschirm und Siri zeigen auch Ihre eigenen Abwesenheiten nur als „Abwesend“.")
            }
            .font(.callout)
        }
        .navigationTitle("Über")
    }
}

/// Vorschau aller Widgets in der App (auch für Screenshots im CI).
struct WidgetGalleryView: View {
    @Environment(AppModel.self) private var model
    @State private var snap: PlanSnapshot?

    var body: some View {
        let today = model.session.today
        ScrollView {
            VStack(alignment: .leading, spacing: 22) {
                Text("So erscheinen Ihre Einsätze auf dem Home-Bildschirm und dem Sperrbildschirm. Halten Sie den Home-Bildschirm gedrückt und tippen Sie auf „Bearbeiten“ → „Widget hinzufügen“.")
                    .font(.callout)
                    .foregroundStyle(.secondary)
                HStack(alignment: .top, spacing: 16) {
                    gallery("Heute") {
                        TodayWidgetView(snapshot: snap, date: today)
                            .padding(16)
                            .frame(width: 170, height: 170)
                            .background(Color.pqCard, in: RoundedRectangle(cornerRadius: 24, style: .continuous))
                    }
                    gallery("Sperrbildschirm") {
                        VStack(alignment: .leading, spacing: 10) {
                            Text(lockInlineText(snap, date: today))
                                .font(.caption.weight(.semibold))
                                .lineLimit(1)
                            LockRectangularView(snapshot: snap, date: today)
                                .frame(width: 158, height: 72, alignment: .leading)
                        }
                        .padding(12)
                        .foregroundStyle(.white)
                        .frame(width: 170, height: 170, alignment: .topLeading)
                        .background(LinearGradient(colors: [Color.pqInk, Color.pqBlue], startPoint: .topLeading, endPoint: .bottomTrailing),
                                    in: RoundedRectangle(cornerRadius: 24, style: .continuous))
                        .environment(\.colorScheme, .dark)
                    }
                }
                gallery("Diese Woche") {
                    WeekWidgetView(snapshot: snap, date: today)
                        .padding(16)
                        .frame(height: 170)
                        .frame(maxWidth: 364)
                        .background(Color.pqCard, in: RoundedRectangle(cornerRadius: 24, style: .continuous))
                }
            }
            .padding()
        }
        .background(Color.pqSurface)
        .navigationTitle("Widgets")
        .accessibilityIdentifier("widgets.gallery")
        .task(id: model.session.dataVersion) {
            // Der Auszug entsteht beim ersten Sync – kurz darauf warten
            for _ in 0..<20 {
                if let s = AppGroup.snapshotStore.load() {
                    snap = s
                    return
                }
                try? await Task.sleep(nanoseconds: 250_000_000)
            }
        }
    }

    private func gallery<V: View>(_ title: String, @ViewBuilder _ content: () -> V) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            Text(title).font(.headline)
            content().shadow(color: .black.opacity(0.12), radius: 10, y: 4)
        }
    }
}
