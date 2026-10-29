import SwiftUI
import PlanquadroKit

/// Tage eines Zeitraums (ein oder zwei Monatsabfragen zusammengeführt).
struct PlanData {
    var days: [PlanDay]
    var publishedAt: ISODateTime?
}

/// „Mein Plan“: eigene veröffentlichte Einsätze als Woche oder Monat, Heute hervorgehoben.
struct PlanView: View {
    @Environment(AppModel.self) private var model
    @Environment(\.brand) private var brand
    @State private var month: YearMonth = YearMonth(key: ISOCalendar.today()) ?? YearMonth(year: 2026, month: 1)
    @State private var weekStart: ISODay = ISOCalendar.startOfWeek(ISOCalendar.today())
    @State private var state: LoadState<PlanData> = .loading
    @State private var confirmExport = false
    @State private var didInit = false

    private struct LoadKey: Hashable {
        var mode: PlanMode
        var month: YearMonth
        var weekStart: ISODay
        var version: Int
    }

    var body: some View {
        let router = model.router
        let today = model.session.today
        ScrollViewReader { proxy in
            List {
                if router.planMode == .week {
                    Section {
                        TodayCard(day: todayDay(today), today: today)
                            .listRowInsets(EdgeInsets())
                            .listRowBackground(Color.clear)
                    }
                }
                LoadedContentRows(state: state, retry: load) { loaded in
                    if let p = loaded.data.publishedAt {
                        Text("Veröffentlicht: \(PQFormat.dateTime(p))")
                            .font(.footnote)
                            .foregroundStyle(.secondary)
                            .listRowBackground(Color.clear)
                    }
                    ForEach(loaded.data.days.groupedByISOWeek()) { group in
                        Section {
                            ForEach(group.items) { day in
                                DayRowLink(day: day, today: today)
                                    .id(day.date)
                            }
                        } header: {
                            Text("\(group.label) · \(PQFormat.dayMonth(group.start))–\(PQFormat.dayMonth(ISOCalendar.addDays(group.start, 6)))")
                        }
                    }
                }
            }
            .listStyle(.insetGrouped)
            .pqSoftScrollEdge()
            .safeAreaInset(edge: .top, spacing: 0) { header(today: today) }
            .dataStateBanner(state.loaded)
            .navigationTitle("Mein Plan")
            .toolbar { toolbar }
            .refreshable {
                await model.session.sync()
                await load()
            }
            .task(id: LoadKey(mode: router.planMode, month: month, weekStart: weekStart, version: model.session.dataVersion)) {
                applyRequestedMonth()
                await load()
                if router.planMode == .month, month == YearMonth(key: today) {
                    withAnimation { proxy.scrollTo(today, anchor: .center) }
                }
            }
            .confirmationDialog("Einsätze in den Kalender übernehmen?", isPresented: $confirmExport, titleVisibility: .visible) {
                Button("\(periodTitle) übernehmen") { Task { await export() } }
                Button("Abbrechen", role: .cancel) {}
            } message: {
                Text("Ihre Einsätze werden als ganztägige Termine eingetragen. Abwesenheiten werden nicht übertragen.")
            }
        }
    }

    // MARK: Kopf

    @ViewBuilder
    private func header(today: ISODay) -> some View {
        @Bindable var router = model.router
        VStack(spacing: 6) {
            Picker("Ansicht", selection: $router.planMode) {
                ForEach(PlanMode.allCases) { Text($0.label).tag($0) }
            }
            .pickerStyle(.segmented)
            .frame(maxWidth: 320)
            .accessibilityIdentifier("plan.mode")
            PeriodSwitcher(title: periodTitle,
                           previousLabel: router.planMode == .week ? "Vorherige Woche" : "Vorheriger Monat",
                           nextLabel: router.planMode == .week ? "Nächste Woche" : "Nächster Monat",
                           showToday: !isCurrentPeriod(today),
                           onPrevious: { shift(-1) }, onNext: { shift(1) }, onToday: { goToday(today) })
        }
        .padding(.horizontal)
        .padding(.top, 4)
        .frame(maxWidth: .infinity)
    }

    @ToolbarContentBuilder
    private var toolbar: some ToolbarContent {
        ToolbarItem(placement: .topBarTrailing) {
            Menu {
                Button {
                    confirmExport = true
                } label: {
                    Label("In Kalender übernehmen", systemImage: "calendar.badge.plus")
                }
                Button {
                    Task {
                        await model.session.sync()
                        await load()
                    }
                } label: {
                    Label("Aktualisieren", systemImage: "arrow.clockwise")
                }
                if model.liveActivities.isSupported {
                    Button {
                        Task { await model.startLiveActivityForToday() }
                    } label: {
                        Label("Live-Aktivität für heute", systemImage: "livephoto")
                    }
                }
            } label: {
                Label("Aktionen", systemImage: "ellipsis.circle")
            }
            .accessibilityIdentifier("plan.actions")
        }
    }

    // MARK: Zeitraum

    private var periodTitle: String {
        if model.router.planMode == .month { return PQFormat.monthLabel(month) }
        let kw = ISOCalendar.isoWeek(weekStart).map { "KW \($0.week)" } ?? ""
        return "\(kw) · \(PQFormat.dayMonth(weekStart))–\(PQFormat.dayMonth(ISOCalendar.addDays(weekStart, 6)))"
    }

    private func isCurrentPeriod(_ today: ISODay) -> Bool {
        model.router.planMode == .month ? month == YearMonth(key: today) : weekStart == ISOCalendar.startOfWeek(today)
    }

    private func shift(_ delta: Int) {
        if model.router.planMode == .month {
            month = month.adding(months: delta)
        } else {
            weekStart = ISOCalendar.addDays(weekStart, 7 * delta)
        }
    }

    private func goToday(_ today: ISODay) {
        month = YearMonth(key: today) ?? month
        weekStart = ISOCalendar.startOfWeek(today)
    }

    private func applyRequestedMonth() {
        if !didInit {
            didInit = true
            goToday(model.session.today)
        }
        if let m = model.router.planMonth {
            model.router.planMonth = nil
            month = m
            if YearMonth(key: weekStart) != m { weekStart = ISOCalendar.startOfWeek(m.first) }
        }
    }

    private func todayDay(_ today: ISODay) -> PlanDay? {
        state.value?.days.first { $0.date == today }
    }

    // MARK: Laden

    private func load() async {
        let mode = model.router.planMode
        let months: [YearMonth]
        if mode == .month {
            months = [month]
        } else {
            let a = YearMonth(key: weekStart), b = YearMonth(key: ISOCalendar.addDays(weekStart, 6))
            months = Array(Set([a, b].compactMap { $0 })).sorted()
        }
        do {
            var all: [Loaded<MyPlanResponse>] = []
            for m in months { all.append(try await model.session.myPlan(m)) }
            var days = all.flatMap { $0.data.filledDays }
            if mode == .week {
                let end = ISOCalendar.addDays(weekStart, 6)
                days = days.filter { $0.date >= weekStart && $0.date <= end }
            }
            days.sort { $0.date < $1.date }
            let cached = all.first { $0.fromCache }
            state = .loaded(Loaded(data: PlanData(days: days, publishedAt: all.compactMap(\.data.publishedAt).max()),
                                   fromCache: cached != nil, savedAt: all.map(\.savedAt).min() ?? Date(), offlineReason: cached?.offlineReason))
        } catch {
            state = .failed(APIError.wrap(error).userMessage)
        }
    }

    private func export() async {
        guard let days = state.value?.days else { return }
        do {
            let r = try await model.calendar.export(days: days, tenantName: model.session.tenantName)
            model.show(r.added == 0 && r.skipped > 0 ? "Alle Einsätze sind bereits im Kalender." :
                        "\(r.added) \(r.added == 1 ? "Einsatz" : "Einsätze") in den Kalender übernommen.", style: .ok)
        } catch {
            model.show(error.localizedDescription, style: .error)
        }
    }
}

/// Wie `LoadedContent`, aber als Listenzeilen.
struct LoadedContentRows<T, Content: View>: View {
    var state: LoadState<T>
    var retry: () async -> Void
    @ViewBuilder var content: (Loaded<T>) -> Content

    var body: some View {
        switch state {
        case .loading:
            HStack {
                Spacer()
                ProgressView("Wird geladen …")
                Spacer()
            }
            .padding(.vertical, 40)
            .listRowBackground(Color.clear)
        case let .failed(message):
            ErrorStateView(message: message, retry: retry)
                .listRowBackground(Color.clear)
        case let .loaded(l):
            content(l)
        }
    }
}

/// Tageszeile; Tage mit Eintrag öffnen die Details.
struct DayRowLink: View {
    let day: PlanDay
    let today: ISODay

    var body: some View {
        let isToday = day.date == today
        Group {
            if day.entry != nil {
                NavigationLink(value: Route.day(day.date, userID: nil)) {
                    DayRow(day: day, today: today)
                }
            } else {
                DayRow(day: day, today: today)
            }
        }
        .listRowBackground(isToday ? Color.pqOrange.opacity(0.13) : nil)
        .accessibilityAddTraits(isToday ? .isSelected : [])
    }
}

struct DayRow: View {
    let day: PlanDay
    let today: ISODay

    var body: some View {
        let isToday = day.date == today
        HStack(alignment: .center, spacing: 12) {
            VStack(spacing: 0) {
                Text(PQFormat.weekdayShort(day.date))
                    .font(.caption.weight(.semibold))
                    .foregroundStyle(isToday ? Color.pqOrangeText : .secondary)
                Text(String(day.date.suffix(2)))
                    .font(.title3.weight(.bold))
                    .monospacedDigit()
                    .foregroundStyle(isToday ? Color.pqOrangeText : .primary)
            }
            .frame(width: 40)
            if let e = day.entry {
                CodeBadge(code: e.code, color: e.color, fg: e.fg, highlighted: isToday)
                VStack(alignment: .leading, spacing: 2) {
                    Text(e.label)
                        .font(.body.weight(.semibold))
                        .lineLimit(2)
                    if let r = e.remarks, !r.isEmpty {
                        Text(r)
                            .font(.footnote)
                            .foregroundStyle(.secondary)
                            .lineLimit(2)
                    }
                }
            } else if day.holiday == nil {
                Text(day.isWeekend ? "Wochenende" : "frei")
                    .foregroundStyle(.secondary)
            }
            if let h = day.holiday {
                StatusChip(text: h.name, color: .pqWarning)
            }
            Spacer(minLength: 0)
            if isToday { TodayTag() }
        }
        .padding(.vertical, 4)
        .opacity(day.isWeekend && day.entry == nil ? 0.75 : 1)
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(accessibilityText(isToday: isToday))
    }

    private func accessibilityText(isToday: Bool) -> String {
        var parts = [(isToday ? "Heute, " : "") + PQFormat.dateLong(day.date)]
        if let e = day.entry {
            parts.append(e.kind == .assignment ? "Einsatz \(e.label), Kürzel \(e.code)" : e.label)
            if let r = e.remarks, !r.isEmpty { parts.append("Bemerkung: \(r)") }
        } else if day.holiday == nil {
            parts.append(day.isWeekend ? "Wochenende" : "frei")
        }
        if let h = day.holiday { parts.append("Feiertag \(h.name)") }
        return parts.joined(separator: ". ")
    }
}

/// „Heute“-Karte (Wochenansicht) mit Glas-Effekt.
struct TodayCard: View {
    @Environment(AppModel.self) private var model
    @Environment(\.brand) private var brand
    let day: PlanDay?
    let today: ISODay

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack {
                Label("Heute", systemImage: "sun.max.fill")
                    .font(.subheadline.weight(.bold))
                    .foregroundStyle(Color.pqOrangeText)
                Spacer()
                Text(PQFormat.dateLong(today))
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            }
            HStack(alignment: .center, spacing: 14) {
                if let e = day?.entry {
                    CodeBadge(code: e.code, color: e.color, fg: e.fg, size: .hero)
                    VStack(alignment: .leading, spacing: 4) {
                        Text(e.label)
                            .font(.title2.weight(.bold))
                            .lineLimit(2)
                        if let r = e.remarks, !r.isEmpty {
                            Text(r).font(.subheadline).foregroundStyle(.secondary).lineLimit(2)
                        }
                    }
                } else {
                    Image(systemName: day?.holiday != nil ? "party.popper" : "cup.and.saucer")
                        .font(.system(size: 36))
                        .foregroundStyle(Color.pqOrange)
                        .frame(width: 76, height: 76)
                    Text(day?.holiday?.name ?? (day == nil ? "Wird geladen …" : "Heute kein Einsatz"))
                        .font(.title3.weight(.semibold))
                }
            }
            if let e = day?.entry, e.kind == .assignment {
                PQGlassGroup(spacing: 8) {
                    HStack(spacing: 8) {
                        NavigationLink(value: Route.day(today, userID: nil)) {
                            Label("Details", systemImage: "info.circle")
                                .frame(maxWidth: .infinity)
                        }
                        .pqProminentButton()
                        if model.liveActivities.isSupported {
                            Button {
                                Task { await model.startLiveActivityForToday() }
                            } label: {
                                Label("Live", systemImage: "livephoto")
                                    .frame(maxWidth: .infinity)
                            }
                            .pqGlassButton()
                        }
                    }
                }
            }
        }
        .padding(18)
        .pqGlassCard(cornerRadius: 26, tint: brand.primary.opacity(0.35))
        .padding(.vertical, 6)
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("plan.today")
    }
}
