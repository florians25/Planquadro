import SwiftUI
import PlanquadroKit

/// Suche (iOS 18+: eigene Tab-Rolle `.search`, iPad: Seitenleiste): eigene Einsätze der
/// nächsten Wochen nach Kürzel/Bezeichnung/Datum und Personen der Teamübersicht.
struct SearchView: View {
    @Environment(AppModel.self) private var model
    @State private var query = ""
    @State private var days: [PlanDay] = []
    @State private var people: [TeamUser] = []
    @State private var loaded = false

    var body: some View {
        let q = TeamView.normalize(query.trimmingCharacters(in: .whitespaces))
        let today = model.session.today
        let dayHits = q.isEmpty ? Array(days.filter { $0.entry != nil && $0.date >= today }.prefix(10)) : days.filter { matches($0, q) }
        let personHits = q.isEmpty ? [] : people.filter { TeamView.normalize($0.name).contains(q) }
        List {
            if !loaded {
                ProgressView().frame(maxWidth: .infinity).listRowBackground(Color.clear)
            } else if !q.isEmpty && dayHits.isEmpty && personHits.isEmpty {
                ContentUnavailableView.search(text: query)
                    .listRowBackground(Color.clear)
            }
            if !dayHits.isEmpty {
                Section(q.isEmpty ? "Ihre nächsten Einsätze" : "Einsätze") {
                    ForEach(dayHits) { d in DayRowLink(day: d, today: today) }
                }
            }
            if !personHits.isEmpty {
                Section("Team") {
                    ForEach(personHits) { u in
                        Button {
                            model.handle(link: .team(nil))
                        } label: {
                            Label {
                                VStack(alignment: .leading) {
                                    Text(u.name)
                                    Text(u.roleLabel).font(.caption).foregroundStyle(.secondary)
                                }
                            } icon: {
                                Image(systemName: u.isMe ? "person.crop.circle.fill" : "person.crop.circle")
                            }
                        }
                    }
                }
            }
        }
        .listStyle(.insetGrouped)
        .navigationTitle("Suchen")
        .searchable(text: $query, placement: .navigationBarDrawer(displayMode: .always), prompt: "Kürzel, Kunde, Datum oder Name")
        .task(id: model.session.dataVersion) { await load() }
    }

    private func matches(_ d: PlanDay, _ q: String) -> Bool {
        guard let e = d.entry else { return false }
        let hay = TeamView.normalize([e.code, e.label, e.remarks ?? "", PQFormat.date(d.date), PQFormat.weekdayLong(d.date)].joined(separator: " "))
        return hay.contains(q)
    }

    private func load() async {
        guard let ym = YearMonth(key: model.session.today) else { return }
        var all: [PlanDay] = []
        for m in [ym, ym.adding(months: 1)] {
            if let p = try? await model.session.myPlan(m) { all += p.data.filledDays }
        }
        days = all.sorted { $0.date < $1.date }
        if model.session.me?.permissions.planView ?? false, let t = try? await model.session.teamPlan(ym) {
            people = t.data.users
        }
        loaded = true
    }
}
