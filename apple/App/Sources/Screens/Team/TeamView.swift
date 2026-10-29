import SwiftUI
import PlanquadroKit

/// Teamübersicht im Planquadro-Grunddesign: Zeilen = Personen, Spalten = Tage, farbige Kürzel.
/// Vertrauliche Abwesenheiten erscheinen nur als „A“ (ohne Details, ohne Link).
struct TeamView: View {
    @Environment(AppModel.self) private var model
    @State private var month: YearMonth = YearMonth(key: ISOCalendar.today()) ?? YearMonth(year: 2026, month: 1)
    @State private var state: LoadState<TeamPlanResponse> = .loading
    @State private var search = ""
    @State private var didInit = false

    private struct LoadKey: Hashable {
        var month: YearMonth
        var version: Int
    }

    var body: some View {
        let today = model.session.today
        LoadedContent(state: state, retry: load) { loaded in
            let t = loaded.data
            let users = filteredUsers(t.users)
            if t.users.isEmpty {
                ContentUnavailableView("Keine Mitarbeitenden sichtbar", systemImage: "person.2.slash",
                                       description: Text("Für diesen Monat sind in Ihrem Team keine Personen sichtbar."))
            } else if users.isEmpty {
                ContentUnavailableView.search(text: search)
            } else {
                TeamGrid(plan: t, users: users, today: today)
            }
        }
        .safeAreaInset(edge: .top, spacing: 0) {
            PeriodSwitcher(title: PQFormat.monthLabel(month), previousLabel: "Vorheriger Monat", nextLabel: "Nächster Monat",
                           showToday: month != YearMonth(key: today),
                           onPrevious: { month = month.adding(months: -1) }, onNext: { month = month.adding(months: 1) },
                           onToday: { month = YearMonth(key: today) ?? month })
        }
        .dataStateBanner(state.loaded)
        .safeAreaInset(edge: .bottom, spacing: 0) { legend }
        .navigationTitle("Team")
        .searchable(text: $search, placement: .navigationBarDrawer(displayMode: .automatic), prompt: "Name suchen")
        .refreshable { await load() }
        .task(id: LoadKey(month: month, version: model.session.dataVersion)) {
            if !didInit {
                didInit = true
                month = YearMonth(key: today) ?? month
            }
            if let m = model.router.teamMonth {
                model.router.teamMonth = nil
                month = m
            }
            await load()
        }
    }

    private var legend: some View {
        Text("„A“ = abwesend (vertraulich). Tippen Sie auf einen Einsatz für Details.")
            .font(.footnote)
            .foregroundStyle(.secondary)
            .padding(.horizontal, 16)
            .padding(.vertical, 8)
            .pqGlassCapsule()
            .padding(.bottom, 6)
    }

    private func filteredUsers(_ users: [TeamUser]) -> [TeamUser] {
        let q = Self.normalize(search.trimmingCharacters(in: .whitespaces))
        guard !q.isEmpty else { return users }
        return users.filter { Self.normalize($0.name).contains(q) }
    }

    /// Suche ohne Groß-/Kleinschreibung und Akzente („yilmaz“ findet „Yılmaz“).
    static func normalize(_ s: String) -> String {
        s.folding(options: [.caseInsensitive, .diacriticInsensitive], locale: Locale(identifier: "de_DE"))
            .replacingOccurrences(of: "ı", with: "i")
    }

    private func load() async {
        do {
            state = .loaded(try await model.session.teamPlan(month))
        } catch {
            state = .failed(APIError.wrap(error).userMessage)
        }
    }
}

/// Das Raster: feste Namensspalte, horizontal scrollende Tage, Heute in Signal-Orange.
struct TeamGrid: View {
    let plan: TeamPlanResponse
    let users: [TeamUser]
    let today: ISODay
    @Environment(\.dynamicTypeSize) private var dynamicType

    private var cellWidth: CGFloat { dynamicType.isAccessibilitySize ? 52 : 40 }
    private var rowHeight: CGFloat { dynamicType.isAccessibilitySize ? 60 : 46 }
    private var nameWidth: CGFloat { dynamicType.isAccessibilitySize ? 170 : 132 }

    var body: some View {
        ScrollView(.vertical) {
            HStack(alignment: .top, spacing: 0) {
                // Namensspalte
                VStack(spacing: 0) {
                    Text("Name")
                        .font(.caption.weight(.semibold))
                        .foregroundStyle(.secondary)
                        .frame(width: nameWidth, height: 44, alignment: .leading)
                        .padding(.leading, 12)
                    ForEach(users) { u in
                        VStack(alignment: .leading, spacing: 1) {
                            Text(u.name)
                                .font(.subheadline.weight(u.isMe ? .bold : .medium))
                                .lineLimit(1)
                            if !u.roleLabel.isEmpty {
                                Text(u.roleLabel)
                                    .font(.caption2)
                                    .foregroundStyle(.secondary)
                                    .lineLimit(1)
                            }
                        }
                        .frame(width: nameWidth, height: rowHeight, alignment: .leading)
                        .padding(.leading, 12)
                        .background(u.isMe ? Color.pqBlue.opacity(0.08) : Color.clear)
                        .accessibilityAddTraits(.isHeader)
                        .accessibilityLabel(u.isMe ? "\(u.name) (Sie), \(u.roleLabel)" : "\(u.name), \(u.roleLabel)")
                    }
                }
                .background(.background)
                .overlay(alignment: .trailing) {
                    Rectangle().fill(.separator).frame(width: 0.5)
                }
                .zIndex(1)

                // Tage
                ScrollViewReader { proxy in
                    ScrollView(.horizontal) {
                        VStack(spacing: 0) {
                            HStack(spacing: 0) {
                                ForEach(plan.days, id: \.self) { d in
                                    dayHeader(d).id("h-\(d)")
                                }
                            }
                            ForEach(users) { u in
                                HStack(spacing: 0) {
                                    ForEach(plan.days, id: \.self) { d in
                                        cell(user: u, date: d)
                                    }
                                }
                                .background(u.isMe ? Color.pqBlue.opacity(0.08) : Color.clear)
                                .accessibilityElement(children: .contain)
                                .accessibilityLabel(u.name)
                            }
                        }
                    }
                    .scrollIndicators(.visible)
                    .onAppear {
                        if plan.days.contains(today) {
                            proxy.scrollTo("h-\(ISOCalendar.addDays(today, -2))", anchor: .leading)
                        }
                    }
                }
            }
            .padding(.bottom, 60)
        }
        .accessibilityIdentifier("team.grid")
    }

    private func columnBackground(_ d: ISODay) -> Color {
        if d == today { return Color.pqOrange.opacity(0.14) }
        if plan.holidays[d] != nil { return Color.pqWarning.opacity(0.10) }
        if (ISOCalendar.weekday(d) ?? 1) >= 6 { return Color.secondary.opacity(0.08) }
        return .clear
    }

    private func dayHeader(_ d: ISODay) -> some View {
        let isToday = d == today
        return VStack(spacing: 1) {
            Text(PQFormat.weekdayShort(d))
                .font(.caption2.weight(isToday ? .bold : .regular))
            Text(String(d.suffix(2)))
                .font(.caption.weight(.bold))
                .monospacedDigit()
        }
        .foregroundStyle(isToday ? Color.pqOrangeText : .secondary)
        .frame(width: cellWidth, height: 44)
        .background(columnBackground(d))
        .overlay(alignment: .bottom) {
            if isToday { Rectangle().fill(Color.pqOrange).frame(height: 3) }
        }
        .accessibilityLabel((isToday ? "Heute, " : "") + PQFormat.dateLong(d) + (plan.holidays[d].map { ", \($0.name)" } ?? ""))
    }

    @ViewBuilder
    private func cell(user u: TeamUser, date d: ISODay) -> some View {
        let base = Color.clear
            .frame(width: cellWidth, height: rowHeight)
            .background(columnBackground(d))
        if let c = plan.cell(userID: u.id, date: d) {
            let disp = Confidentiality.display(c, isMe: u.isMe, userName: u.name, date: d)
            let badge = CodeBadge(code: disp.code, color: disp.color, fg: disp.fg, size: .cell)
            if disp.opensDetails {
                NavigationLink(value: Route.day(d, userID: u.isMe ? nil : u.id)) {
                    base.overlay { badge }
                }
                .buttonStyle(.plain)
                .accessibilityLabel(disp.accessibilityLabel)
                .accessibilityHint("Öffnet die Einsatzdetails")
            } else {
                base.overlay { badge }
                    .accessibilityElement(children: .ignore)
                    .accessibilityLabel(disp.accessibilityLabel)
            }
        } else {
            base.accessibilityHidden(true)
        }
    }
}
