import EventKit
import MapKit
import SwiftUI
import UIKit
import PlanquadroKit

/// Einsatzdetails eines Tages: Kunde, Adresse (→ Karten), Anruf, E-Mail, Kollegen, Teilen,
/// in den Kalender übernehmen, Live-Aktivität. Fremde Abwesenheiten nur neutral.
struct AssignmentDetailView: View {
    let date: ISODay
    let userID: Int?
    @Environment(AppModel.self) private var model
    @Environment(\.openURL) private var openURL
    @State private var state: LoadState<AssignmentDetails> = .loading
    @State private var calendarEvent: EKEvent?

    private var isOwn: Bool { userID == nil || userID == model.session.myID }

    var body: some View {
        List {
            LoadedContentRows(state: state, retry: load) { loaded in
                content(loaded.data)
            }
        }
        .listStyle(.insetGrouped)
        .dataStateBanner(state.loaded)
        .navigationTitle(PQFormat.dateShort(date))
        .navigationBarTitleDisplayMode(.inline)
        .toolbar { toolbar }
        .refreshable { await load() }
        .task(id: model.session.dataVersion) { await load() }
        .sheet(item: Binding(get: { calendarEvent.map(IdentifiedEvent.init) }, set: { calendarEvent = $0?.event })) { item in
            EventEditView(event: item.event, store: model.calendar.eventStore) { saved in
                calendarEvent = nil
                if saved { model.show("Termin gespeichert.", style: .ok) }
            }
            .ignoresSafeArea()
        }
        .userActivity(HandoffActivity.day, isActive: isOwn) { activity in
            activity.title = "Einsatz am \(PQFormat.date(date))"
            activity.userInfo = ["url": DeepLink.day(date, userID: nil).url.absoluteString]
            activity.isEligibleForHandoff = true
            activity.isEligibleForSearch = false
        }
    }

    // MARK: Inhalt

    @ViewBuilder
    private func content(_ d: AssignmentDetails) -> some View {
        Section {
            VStack(alignment: .leading, spacing: 12) {
                Text(PQFormat.dateLong(d.date))
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
                if let e = d.entry {
                    HStack(spacing: 14) {
                        CodeBadge(code: e.code, color: e.color, fg: e.fg, size: .large, highlighted: d.date == model.session.today)
                        Text(e.label)
                            .font(.title2.weight(.bold))
                            .accessibilityAddTraits(.isHeader)
                    }
                } else {
                    Text("Kein Eintrag").font(.title2.weight(.bold))
                }
                if isOwn, d.date == model.session.today, d.entry?.kind == .assignment, model.liveActivities.isSupported {
                    Button {
                        Task { await model.startLiveActivityForToday() }
                    } label: {
                        Label("Als Live-Aktivität anzeigen", systemImage: "livephoto")
                    }
                    .pqGlassButton()
                }
            }
            .padding(.vertical, 6)
        }

        if let r = d.entry?.remarks, !r.isEmpty {
            Section("Bemerkungen") {
                Text(r).textSelection(.enabled)
            }
        }

        if let a = d.area {
            Section("Bereich") {
                KeyValueRow(label: "Code", value: a.code)
                KeyValueRow(label: "Name", value: a.name)
                if let t = a.type, !t.isEmpty { KeyValueRow(label: "Art", value: t.capitalized) }
            }
        }

        if let c = d.customer {
            Section("Kunde") {
                KeyValueRow(label: "Name", value: c.name)
                if let v = c.contact, !v.isEmpty { KeyValueRow(label: "Kontakt", value: v) }
                if let addr = c.address, !addr.isEmpty {
                    Button { openMaps(address: addr, lat: nil, lng: nil, name: c.name) } label: {
                        Label(addr, systemImage: "map")
                    }
                    .accessibilityHint("Öffnet die Adresse in Karten")
                }
                if let phone = c.phone, !phone.isEmpty, let url = URL(string: "tel:" + phone.filter { $0.isNumber || $0 == "+" }) {
                    Link(destination: url) { Label(phone, systemImage: "phone") }
                        .accessibilityHint("Ruft an")
                }
                if let mail = c.email, !mail.isEmpty, let url = URL(string: "mailto:\(mail)") {
                    Link(destination: url) { Label(mail, systemImage: "envelope") }
                }
                if let n = c.notes, !n.isEmpty {
                    VStack(alignment: .leading, spacing: 4) {
                        Text("Hinweise").font(.caption).foregroundStyle(.secondary)
                        Text(n)
                    }
                }
            }
        }

        if let l = d.location {
            Section("Standort") {
                KeyValueRow(label: "Name", value: l.name)
                if let lat = l.lat, let lng = l.lng {
                    LocationMap(name: l.name, lat: lat, lng: lng)
                        .frame(height: 170)
                        .listRowInsets(EdgeInsets())
                }
                if l.address != nil || l.hasCoordinates {
                    Button { openMaps(address: l.address, lat: l.lat, lng: l.lng, name: l.name) } label: {
                        Label(l.address ?? "In Karten öffnen", systemImage: "map")
                    }
                }
            }
        }

        if d.entry?.kind == .assignment {
            Section("Kolleginnen und Kollegen") {
                if d.colleagues.isEmpty {
                    Text("Niemand sonst eingeplant.").foregroundStyle(.secondary)
                } else {
                    ForEach(d.colleagues) { p in
                        Label(p.name, systemImage: "person")
                    }
                }
            }
        }
    }

    @ToolbarContentBuilder
    private var toolbar: some ToolbarContent {
        ToolbarItemGroup(placement: .topBarTrailing) {
            if let d = state.value, d.entry?.kind == .assignment {
                ShareLink(item: shareText(d), subject: Text("Einsatz am \(PQFormat.date(d.date))")) {
                    Label("Teilen", systemImage: "square.and.arrow.up")
                }
                if isOwn, let e = d.entry {
                    Button {
                        let ev = model.calendar.makeEvent(date: d.date, code: e.code, label: e.label,
                                                          place: d.location?.address ?? d.customer?.address, tenantName: model.session.tenantName)
                        calendarEvent = ev
                    } label: {
                        Label("In Kalender eintragen", systemImage: "calendar.badge.plus")
                    }
                }
            }
        }
    }

    /// Text zum Teilen (ohne Bemerkungen anderer, ohne Abwesenheitsgründe).
    private func shareText(_ d: AssignmentDetails) -> String {
        var lines = ["Einsatz am \(PQFormat.dateLong(d.date))"]
        if let e = d.entry { lines.append("\(e.code) · \(e.label)") }
        if let c = d.customer {
            lines.append(c.name)
            if let a = c.address { lines.append(a) }
        }
        if let l = d.location {
            lines.append("Standort: \(l.name)" + (l.address.map { ", \($0)" } ?? ""))
        }
        return lines.joined(separator: "\n")
    }

    private func openMaps(address: String?, lat: Double?, lng: Double?, name: String) {
        var c = URLComponents(string: "https://maps.apple.com/")!
        var items: [URLQueryItem] = []
        if let lat, let lng { items.append(URLQueryItem(name: "ll", value: "\(lat),\(lng)")) }
        items.append(URLQueryItem(name: "q", value: address ?? name))
        c.queryItems = items
        if let url = c.url { openURL(url) }
    }

    private func load() async {
        do {
            state = .loaded(try await model.session.details(date: date, userID: userID))
        } catch {
            state = .failed(APIError.wrap(error).userMessage)
        }
    }
}

private struct IdentifiedEvent: Identifiable {
    let event: EKEvent
    var id: ObjectIdentifier { ObjectIdentifier(event) }
}

/// Kartenausschnitt mit Markierung (MapKit, iOS 17).
struct LocationMap: View {
    var name: String
    var lat: Double
    var lng: Double

    var body: some View {
        let coord = CLLocationCoordinate2D(latitude: lat, longitude: lng)
        Map(initialPosition: .region(MKCoordinateRegion(center: coord, latitudinalMeters: 900, longitudinalMeters: 900))) {
            Marker(name, systemImage: "wrench.and.screwdriver", coordinate: coord)
                .tint(Color.pqOrange)
        }
        .mapStyle(.standard(pointsOfInterest: .excludingAll))
        .allowsHitTesting(false)
        .accessibilityLabel("Karte: \(name)")
    }
}
