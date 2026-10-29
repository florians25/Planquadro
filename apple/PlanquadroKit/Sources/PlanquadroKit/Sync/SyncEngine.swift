import Foundation

/// Was ein Sync-Ergebnis für den Cache bedeutet (reine Funktion, wie `mobile/src/store/sync.ts`).
public struct SyncPlan: Hashable, Sendable {
    public var fullResync: Bool
    /// Monate (YYYY-MM) des eigenen Plans, die neu geladen werden
    public var planMonths: [String]
    /// Monate der Teamübersicht, die neu geladen werden
    public var teamMonths: [String]
    /// Jahre der Abwesenheitsübersicht, die neu geladen werden
    public var absenceYears: [Int]
    /// Detail-Einträge (Cache-Schlüssel), die verworfen werden
    public var dropKeys: [String]

    /// Nur bereits gecachte Monate werden neu geladen – nicht gecachte Monate lädt
    /// die App beim nächsten Öffnen ohnehin frisch.
    public static func make(_ res: SyncResponse, cachedKeys: [String]) -> SyncPlan {
        if res.fullResync { return SyncPlan(fullResync: true, planMonths: [], teamMonths: [], absenceYears: [], dropKeys: []) }
        let months = Set(ISOCalendar.months(of: res.changedDates))
        let dates = Set(res.changedDates)
        let years = Set(months.compactMap { Int($0.prefix(4)) })
        var plan: [String] = [], team: [String] = [], abs: [Int] = [], drop: [String] = []
        for key in cachedKeys {
            let parts = key.split(separator: ":", omittingEmptySubsequences: false).map(String.init)
            guard parts.count >= 2 else { continue }
            let kind = parts[0], a = parts[1]
            if kind == "plan" && months.contains(a) { plan.append(a) }
            else if kind == "team" && months.contains(a) { team.append(a) }
            else if kind == "abs", let y = Int(a), years.contains(y) { abs.append(y) }
            else if kind == "det" && parts.count >= 3 && dates.contains(a) { drop.append(key) }
        }
        return SyncPlan(fullResync: false, planMonths: plan.sorted(), teamMonths: team.sorted(), absenceYears: abs.sorted(), dropKeys: drop)
    }
}

/// Was ein Live-Ereignis für die App bedeutet (nur bereits gecachte Daten).
public struct LivePlan: Hashable, Sendable {
    /// eigener Plan betroffen → `sync` ausführen
    public var sync: Bool
    /// gecachte Team-Monate, die neu geladen werden
    public var teamMonths: [String]
    /// gecachte Abwesenheitsjahre (eigene Anträge), die neu geladen werden
    public var absenceYears: [Int]

    public static func make(_ ev: LiveEvent, myID: Int, cachedKeys: [String]) -> LivePlan {
        let cached = Set(cachedKeys)
        let selfAffected = ev.selfAffected != false && (ev.cells == nil || ev.cells!.contains { $0.userID == myID })
        var otherMonths = Set<String>()
        if let cells = ev.cells {
            for c in cells where c.userID != myID { otherMonths.insert(String(c.date.prefix(7))) }
        } else {
            ev.months.forEach { otherMonths.insert($0) }
        }
        // Eigene veröffentlichte Änderungen erscheinen auch in der Teamübersicht
        if selfAffected && ev.published { ev.months.forEach { otherMonths.insert($0) } }
        let team = ev.published ? otherMonths.filter { cached.contains(CacheKeys.team($0)) }.sorted() : []
        let years = Set(ev.months.compactMap { Int($0.prefix(4)) })
        let abs = selfAffected && (ev.type == .requestChanged || ev.published)
            ? years.filter { cached.contains(CacheKeys.absences($0)) }.sorted()
            : []
        return LivePlan(sync: selfAffected && ev.published, teamMonths: team, absenceYears: abs)
    }
}

public struct SyncResult: Hashable, Sendable {
    /// Erster Sync: nur Cursor gemerkt
    public var initial: Bool
    public var fullResync: Bool
    public var changedDates: [ISODay]
    /// erneuerte Cache-Schlüssel
    public var refreshed: [String]

    public init(initial: Bool = false, fullResync: Bool = false, changedDates: [ISODay] = [], refreshed: [String] = []) {
        self.initial = initial
        self.fullResync = fullResync
        self.changedDates = changedDates
        self.refreshed = refreshed
    }
}

/// Führt Syncs aus (Single-Flight). Der neue Cursor wird erst gespeichert, wenn alle
/// betroffenen Monate erfolgreich neu geladen wurden – bei Fehlern wird beim nächsten
/// Mal mit dem alten Cursor erneut synchronisiert.
public actor SyncEngine {
    private let api: APIClient
    private let cache: ScopedCache
    private var running: Task<SyncResult, Error>?

    public init(api: APIClient, cache: ScopedCache) {
        self.api = api
        self.cache = cache
    }

    public func run() async throws -> SyncResult {
        if let r = running { return try await r.value }
        let t = Task { try await self.perform() }
        running = t
        defer { running = nil }
        return try await t.value
    }

    private func perform() async throws -> SyncResult {
        let cursor = await cache.get(CacheKeys.cursor, as: String.self)?.data
        let res = try await api.sync(since: cursor)
        guard cursor != nil else {
            try await cache.put(CacheKeys.cursor, res.cursor)
            return SyncResult(initial: true)
        }
        let plan = SyncPlan.make(res, cachedKeys: await cache.keys())
        if plan.fullResync {
            let me = await cache.get(CacheKeys.me, as: MeResponse.self)?.data
            await cache.clear()
            if let me { try? await cache.put(CacheKeys.me, me) }
            try await cache.put(CacheKeys.cursor, res.cursor)
            return SyncResult(fullResync: true)
        }
        var refreshed: [String] = []
        for m in plan.planMonths {
            guard let ym = YearMonth(key: m) else { continue }
            try await cache.put(CacheKeys.plan(m), try await api.myPlan(month: ym))
            refreshed.append(CacheKeys.plan(m))
        }
        refreshed += try await refreshTeam(plan.teamMonths)
        refreshed += try await refreshAbsences(plan.absenceYears)
        for k in plan.dropKeys { await cache.delete(k) }
        try await cache.put(CacheKeys.cursor, res.cursor)
        return SyncResult(changedDates: res.changedDates, refreshed: refreshed)
    }

    func refreshTeam(_ months: [String]) async throws -> [String] {
        var out: [String] = []
        for m in months {
            guard let ym = YearMonth(key: m) else { continue }
            try await cache.put(CacheKeys.team(m), try await api.teamPlan(ym))
            out.append(CacheKeys.team(m))
        }
        return out
    }

    func refreshAbsences(_ years: [Int]) async throws -> [String] {
        var out: [String] = []
        for y in years {
            try await cache.put(CacheKeys.absences(y), try await api.myAbsences(year: y))
            out.append(CacheKeys.absences(y))
        }
        return out
    }

    /// Verarbeitet ein Live-Ereignis: ggf. Sync, dann betroffene Team-/Abwesenheitsdaten nachladen.
    public func apply(_ ev: LiveEvent, myID: Int) async throws -> [String] {
        let plan = LivePlan.make(ev, myID: myID, cachedKeys: await cache.keys())
        var refreshed: [String] = []
        if plan.sync { refreshed += try await run().refreshed }
        refreshed += try await refreshTeam(plan.teamMonths)
        refreshed += try await refreshAbsences(plan.absenceYears)
        return refreshed
    }
}
