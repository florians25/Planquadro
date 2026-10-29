import SwiftUI
import PlanquadroKit

/// Abwesenheiten: Urlaubskonto, Anträge stellen und verfolgen, Zeiträume.
struct AbsencesView: View {
    @Environment(AppModel.self) private var model
    @State private var year = Int(ISOCalendar.today().prefix(4)) ?? 2026
    @State private var state: LoadState<MyAbsencesResponse> = .loading
    @State private var didInit = false

    private struct LoadKey: Hashable {
        var year: Int
        var version: Int
    }

    var body: some View {
        @Bindable var router = model.router
        let approvals = model.session.me?.modules.approvals ?? false
        List {
            LoadedContentRows(state: state, retry: load) { loaded in
                let a = loaded.data
                Section {
                    VacationAccountView(account: a.vacation, year: a.year, sickDays: a.sickDays)
                        .listRowInsets(EdgeInsets())
                        .listRowBackground(Color.clear)
                }
                if approvals {
                    Section {
                        Button {
                            router.showRequestForm = true
                        } label: {
                            Label("Antrag stellen", systemImage: "plus.circle.fill")
                                .font(.headline)
                                .frame(maxWidth: .infinity, minHeight: 34)
                        }
                        .pqProminentButton()
                        .listRowInsets(EdgeInsets())
                        .listRowBackground(Color.clear)
                        .accessibilityIdentifier("absences.request")
                    }
                }
                Section("Meine Anträge") {
                    if a.requests.isEmpty {
                        Text("Keine Anträge.").foregroundStyle(.secondary)
                    } else {
                        ForEach(a.requests) { r in RequestRow(request: r) }
                    }
                }
                Section("Zeiträume") {
                    if a.items.isEmpty {
                        Text("Keine Abwesenheiten in diesem Jahr.").foregroundStyle(.secondary)
                    } else {
                        ForEach(a.items) { i in
                            HStack {
                                VStack(alignment: .leading, spacing: 2) {
                                    Text(i.label).font(.body.weight(.semibold))
                                    Text(PQFormat.range(i.start, i.end)).font(.footnote).foregroundStyle(.secondary)
                                }
                                Spacer()
                                Text(PQFormat.days(i.days)).monospacedDigit()
                                if !i.published { StatusChip(text: "nicht veröffentlicht", color: .secondary) }
                            }
                            .accessibilityElement(children: .combine)
                        }
                    }
                }
            }
        }
        .listStyle(.insetGrouped)
        .safeAreaInset(edge: .top, spacing: 0) {
            let current = Int(model.session.today.prefix(4)) ?? year
            PeriodSwitcher(title: String(year), previousLabel: "Vorheriges Jahr", nextLabel: "Nächstes Jahr", showToday: year != current,
                           onPrevious: { year -= 1 }, onNext: { year += 1 }, onToday: { year = current })
        }
        .dataStateBanner(state.loaded)
        .navigationTitle("Abwesenheiten")
        .toolbar {
            if approvals {
                ToolbarItem(placement: .topBarTrailing) {
                    Button {
                        router.showRequestForm = true
                    } label: {
                        Label("Antrag stellen", systemImage: "plus")
                    }
                    .keyboardShortcut("n", modifiers: .command)
                }
            }
        }
        .refreshable { await load() }
        .task(id: LoadKey(year: year, version: model.session.dataVersion)) {
            if !didInit {
                didInit = true
                year = Int(model.session.today.prefix(4)) ?? year
            }
            if let y = model.router.absencesYear {
                model.router.absencesYear = nil
                year = y
            }
            await load()
        }
        .sheet(isPresented: $router.showRequestForm) {
            RequestFormView { created in
                if created { Task { await load() } }
            }
        }
    }

    private func load() async {
        do {
            state = .loaded(try await model.session.absences(year: year))
        } catch {
            state = .failed(APIError.wrap(error).userMessage)
        }
    }
}

/// Urlaubskonto als Kacheln mit Ring für den Rest.
struct VacationAccountView: View {
    var account: VacationAccount
    var year: Int
    var sickDays: Double
    @Environment(\.brand) private var brand

    var body: some View {
        VStack(alignment: .leading, spacing: 14) {
            Text("Urlaubskonto \(String(year))")
                .font(.headline)
                .accessibilityAddTraits(.isHeader)
            HStack(alignment: .center, spacing: 18) {
                Gauge(value: max(0, min(account.left, account.entitlement)), in: 0...max(1, account.entitlement)) {
                    Text("Rest")
                } currentValueLabel: {
                    Text(PQFormat.number(account.left))
                        .font(.title2.weight(.bold))
                        .monospacedDigit()
                }
                .gaugeStyle(.accessoryCircularCapacity)
                .tint(brand.primary)
                .scaleEffect(1.25)
                .frame(width: 80, height: 80)
                .accessibilityLabel("Resturlaub \(PQFormat.days(account.left)) von \(PQFormat.days(account.entitlement))")
                Grid(alignment: .leading, horizontalSpacing: 18, verticalSpacing: 8) {
                    GridRow {
                        tile("Anspruch", account.entitlement)
                        tile("Genommen", account.used)
                    }
                    GridRow {
                        tile("Geplant", account.planned)
                        tile("Rest", account.left, strong: true)
                    }
                }
            }
            Text("Krankheitstage \(String(year)): \(PQFormat.number(sickDays))")
                .font(.footnote)
                .foregroundStyle(.secondary)
        }
        .padding(18)
        .pqGlassCard(cornerRadius: 24)
        .padding(.vertical, 4)
    }

    private func tile(_ label: String, _ value: Double, strong: Bool = false) -> some View {
        VStack(alignment: .leading, spacing: 0) {
            Text(PQFormat.number(value))
                .font(strong ? .title3.weight(.heavy) : .title3.weight(.semibold))
                .monospacedDigit()
                .foregroundStyle(strong ? brand.primaryText : .primary)
            Text(label).font(.caption).foregroundStyle(.secondary)
        }
        .accessibilityElement(children: .combine)
    }
}

struct RequestRow: View {
    var request: AbsenceRequestItem

    var body: some View {
        HStack {
            VStack(alignment: .leading, spacing: 2) {
                Text(PQFormat.dateLong(request.date)).font(.body.weight(.semibold))
                Text(RequestType.label(for: request.type) + (request.createdAt.map { " · gestellt \(PQFormat.dateTime($0))" } ?? ""))
                    .font(.footnote)
                    .foregroundStyle(.secondary)
            }
            Spacer()
            StatusChip(text: request.statusLabel, color: color)
        }
        .accessibilityElement(children: .combine)
    }

    private var color: Color {
        switch request.knownStatus {
        case .approved: return .pqSuccess
        case .rejected: return .pqError
        default: return .pqWarning
        }
    }
}

/// Antrag stellen (je Werktag ein Antrag; Wochenenden und Feiertage werden übersprungen).
struct RequestFormView: View {
    var onDone: (Bool) -> Void
    @Environment(AppModel.self) private var model
    @Environment(\.dismiss) private var dismiss
    @State private var from = Date()
    @State private var to = Date()
    @State private var type: RequestType = .vacationFull
    @State private var remarks = ""
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        NavigationStack {
            Form {
                if let error {
                    Label(error, systemImage: "exclamationmark.triangle.fill")
                        .foregroundStyle(Color.pqError)
                }
                Section {
                    DatePicker("Von", selection: $from, in: Date()..., displayedComponents: .date)
                    DatePicker("Bis", selection: $to, in: from..., displayedComponents: .date)
                } footer: {
                    Text("Je Werktag wird ein Antrag gestellt. Wochenenden und Feiertage werden übersprungen.")
                }
                Section {
                    Picker("Art", selection: $type) {
                        ForEach(RequestType.allCases) { Text($0.label).tag($0) }
                    }
                    TextField("Bemerkung (optional)", text: $remarks, axis: .vertical)
                        .lineLimit(1...4)
                }
            }
            .environment(\.locale, Locale(identifier: "de_DE"))
            .navigationTitle("Antrag stellen")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Abbrechen") {
                        onDone(false)
                        dismiss()
                    }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button {
                        Task { await submit() }
                    } label: {
                        if busy { ProgressView() } else { Text("Senden").bold() }
                    }
                    .disabled(busy)
                    .accessibilityIdentifier("request.submit")
                }
            }
            .onChange(of: from) { _, newValue in
                if to < newValue { to = newValue }
            }
        }
        .presentationDetents([.medium, .large])
    }

    private func iso(_ d: Date) -> ISODay { ISOCalendar.today(now: d) }

    private func submit() async {
        error = nil
        let f = iso(from), t = iso(to)
        guard f <= t else {
            error = "Bitte einen gültigen Zeitraum wählen („Bis“ nicht vor „Von“)."
            return
        }
        busy = true
        defer { busy = false }
        do {
            let text = remarks.trimmingCharacters(in: .whitespacesAndNewlines)
            let r = try await model.session.createAbsenceRequest(AbsenceRequestCreate(from: f, to: t, type: type, remarks: text.isEmpty ? nil : text))
            model.show("\(r.created) \(r.created == 1 ? "Antrag" : "Anträge") gestellt" + (r.skipped > 0 ? ", \(r.skipped) übersprungen." : "."), style: .ok)
            onDone(true)
            dismiss()
        } catch {
            self.error = APIError.wrap(error).userMessage
        }
    }
}
