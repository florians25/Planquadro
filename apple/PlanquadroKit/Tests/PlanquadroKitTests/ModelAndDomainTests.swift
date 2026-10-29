import XCTest
@testable import PlanquadroKit

final class ModelDecodingTests: XCTestCase {
    func decode<T: Decodable>(_ t: T.Type, _ json: String) throws -> T {
        try JSONDecoder().decode(T.self, from: Data(json.utf8))
    }

    func testConfigFromApiDoc() throws {
        let c = try decode(ConfigResponse.self, """
        { "api_version": 1, "tenant": { "name": "Planquadro", "slug": "default",
          "branding": { "primary": "#2557d6", "accent": "#ff7a1a", "logo_url": "https://x/api/v1/branding/logo" } },
          "auth": { "password": true, "oidc": { "enabled": false, "label": "Mit Firmenkonto anmelden", "authorize_url": null } },
          "modules": { "absences": true, "approvals": false, "ical": true, "mobile": true } }
        """)
        XCTAssertEqual(c.apiVersion, 1)
        XCTAssertEqual(c.tenant.branding.logoURL, "https://x/api/v1/branding/logo")
        XCTAssertFalse(c.oidcAvailable)
        XCTAssertTrue(c.modules.ical)
        XCTAssertFalse(c.modules.approvals)
    }

    func testBrandingNullFallsBackToPlanquadro() throws {
        let b = try decode(Branding.self, #"{"primary": null, "accent": null, "logo_url": null}"#)
        XCTAssertEqual(b.primary, BrandColors.quadroBlueHex)
        XCTAssertEqual(b.accent, BrandColors.signalOrangeHex)
    }

    func testPlanMyWithAssignmentAbsenceHoliday() throws {
        let p = try decode(MyPlanResponse.self, """
        { "from": "2026-11-01", "to": "2026-11-04", "published_at": "2026-10-29T17:56:31+01:00",
          "days": [
            { "date": "2026-11-02", "weekday": 1, "holiday": null,
              "entry": { "kind": "assignment", "area_id": 3, "code": "K1", "label": "Kunde Nord", "color": "#dc2626", "fg": "#ffffff", "remarks": "Rollout Phase 2" } },
            { "date": "2026-11-03", "weekday": 2, "holiday": null,
              "entry": { "kind": "absence", "type": "urlaub_genehmigt", "code": "U", "label": "Urlaub (genehmigt)", "color": "#16a34a", "fg": "#ffffff", "remarks": null } },
            { "date": "2026-11-01", "weekday": 7, "holiday": { "name": "Allerheiligen", "type": "feiertag" }, "entry": null }
          ] }
        """)
        XCTAssertEqual(p.days.count, 3)
        XCTAssertEqual(p.days[0].entry?.areaID, 3)
        XCTAssertEqual(p.days[1].entry?.type, "urlaub_genehmigt")
        XCTAssertTrue(p.days[2].holiday?.isFullHoliday ?? false)
        // Lücken werden aufgefüllt und sortiert
        let filled = p.filledDays
        XCTAssertEqual(filled.map(\.date), ["2026-11-01", "2026-11-02", "2026-11-03", "2026-11-04"])
        XCTAssertNil(filled[3].entry)
        XCTAssertEqual(filled[3].weekday, 3)
    }

    func testTeamCellsKeepUnderscoreKeysAndConfidentialFlag() throws {
        let t = try decode(TeamPlanResponse.self, """
        { "year": 2026, "month": 11, "days": ["2026-11-02"],
          "holidays": { "2026-11-01": { "name": "Allerheiligen", "type": "feiertag" } },
          "users": [ { "id": 12, "name": "Muster, Max", "role_label": "Techniker", "is_me": true } ],
          "cells": { "12_2026-11-02": { "kind": "assignment", "code": "K1", "color": "#dc2626", "fg": "#ffffff" },
                     "14_2026-11-02": { "kind": "absence", "code": "A", "color": "#ef4444", "fg": "#ffffff", "confidential": true } } }
        """)
        XCTAssertEqual(t.cell(userID: 12, date: "2026-11-02")?.code, "K1")
        XCTAssertEqual(t.cell(userID: 14, date: "2026-11-02")?.confidential, true)
        XCTAssertEqual(t.users.first?.isMe, true)
        XCTAssertEqual(t.holidays["2026-11-01"]?.name, "Allerheiligen")
    }

    func testTeamWithEmptyArraysFromPhp() throws {
        // PHP serialisiert leere assoziative Arrays als []
        let t = try decode(TeamPlanResponse.self, #"{"year":2026,"month":2,"days":[],"holidays":[],"users":[],"cells":{}}"#)
        XCTAssertTrue(t.holidays.isEmpty)
        XCTAssertTrue(t.cells.isEmpty)
    }

    func testTokenResponseContainsMe() throws {
        let t = try decode(TokenResponse.self, """
        { "access_token": "a", "token_type": "Bearer", "expires_in": 900, "refresh_token": "r", "refresh_expires_in": 2592000,
          "user": { "user": { "id": 12, "username": "mmuster", "first_name": "Max", "last_name": "Muster", "role": "mitarbeiter", "role_label": "Techniker" },
                    "permissions": { "plan_view": true, "plan_edit": false, "approve_requests": false, "view_all_employees": true, "manage_absences": false },
                    "modules": { "absences": true, "approvals": true, "assistant": false, "ical": true },
                    "tenant": { "name": "T", "slug": "t", "branding": { "primary": "#2557d6", "accent": "#ff7a1a", "logo_url": null } },
                    "server_time": "2026-10-29T18:00:00+01:00" } }
        """)
        XCTAssertEqual(t.user.user.fullName, "Max Muster")
        XCTAssertEqual(t.user.user.initials, "MM")
        XCTAssertTrue(t.user.permissions.planView)
    }

    func testSyncCursorAsNumberOrString() throws {
        XCTAssertEqual(try decode(SyncResponse.self, #"{"cursor":"1842","changed_dates":["2026-11-02"],"full_resync":false}"#).cursor, "1842")
        XCTAssertEqual(try decode(SyncResponse.self, #"{"cursor":1843,"changed_dates":[],"full_resync":true}"#).cursor, "1843")
    }

    func testLiveEventCellsArrayAndNull() throws {
        let e = try decode(LiveEvent.self, #"{"type":"published","published":true,"months":["2026-11"],"cells":[[12,"2026-11-02"]],"self":true,"own":false}"#)
        XCTAssertEqual(e.cells?.first?.userID, 12)
        XCTAssertEqual(e.selfAffected, true)
        let n = try decode(LiveEvent.self, #"{"type":"cells_changed","published":false,"months":["2026-11"],"cells":null,"self":null,"own":false}"#)
        XCTAssertNil(n.cells)
        XCTAssertNil(n.selfAffected)
        // Rundreise
        let back = try JSONDecoder().decode(LiveEvent.self, from: JSONEncoder().encode(e))
        XCTAssertEqual(back, e)
    }

    func testPushPayload() {
        let p = PlanChangedPush(userInfo: ["aps": ["alert": "x"], "data": ["type": "plan_changed", "dates": "2026-11-02, 2026-11-03,kaputt"]])
        XCTAssertEqual(p?.dates, ["2026-11-02", "2026-11-03"])
        XCTAssertNil(PlanChangedPush(userInfo: ["type": "anderes"]))
        XCTAssertEqual(PlanChangedPush(userInfo: ["type": "plan_changed", "dates": "2026-12-01"])?.dates, ["2026-12-01"])
    }

    func testCalendarWebcal() {
        let c = CalendarSubscription(enabled: true, url: "https://pq.example/api/ical?token=abc")
        XCTAssertEqual(c.webcalURL?.absoluteString, "webcal://pq.example/api/ical?token=abc")
    }
}

final class ISOCalendarTests: XCTestCase {
    func testValidation() {
        XCTAssertTrue(ISOCalendar.isValid("2026-02-28"))
        XCTAssertFalse(ISOCalendar.isValid("2026-02-30"))
        XCTAssertFalse(ISOCalendar.isValid("2026-2-3"))
        XCTAssertFalse(ISOCalendar.isValid("kaputt"))
        XCTAssertTrue(ISOCalendar.isValid("2028-02-29"))
    }

    func testWeekdayAndWeeks() {
        XCTAssertEqual(ISOCalendar.weekday("2026-11-02"), 1) // Montag
        XCTAssertEqual(ISOCalendar.weekday("2026-11-01"), 7) // Sonntag
        XCTAssertEqual(ISOCalendar.isoWeek("2026-01-01"), ISOWeek(year: 2026, week: 1))
        XCTAssertEqual(ISOCalendar.isoWeek("2027-01-01"), ISOWeek(year: 2026, week: 53))
        XCTAssertEqual(ISOCalendar.isoWeek("2026-11-02")?.week, 45)
        XCTAssertEqual(ISOCalendar.startOfWeek("2026-11-05"), "2026-11-02")
        XCTAssertEqual(ISOCalendar.week(containing: "2026-11-01").first, "2026-10-26")
    }

    func testDayArithmeticAcrossDST() {
        XCTAssertEqual(ISOCalendar.addDays("2026-03-28", 2), "2026-03-30")
        XCTAssertEqual(ISOCalendar.addDays("2026-10-24", 2), "2026-10-26")
        XCTAssertEqual(ISOCalendar.addDays("2026-12-31", 1), "2027-01-01")
        XCTAssertEqual(ISOCalendar.daysBetween("2026-11-01", "2026-11-30"), 29)
        XCTAssertEqual(ISOCalendar.eachDay(from: "2026-11-29", to: "2026-12-02").count, 4)
        XCTAssertTrue(ISOCalendar.eachDay(from: "2026-12-02", to: "2026-11-29").isEmpty)
    }

    func testYearMonth() {
        let ym = YearMonth(year: 2026, month: 1)
        XCTAssertEqual(ym.adding(months: -1), YearMonth(year: 2025, month: 12))
        XCTAssertEqual(ym.adding(months: 13), YearMonth(year: 2027, month: 2))
        XCTAssertEqual(YearMonth(year: 2028, month: 2).last, "2028-02-29")
        XCTAssertEqual(YearMonth(key: "2026-11")?.key, "2026-11")
        XCTAssertEqual(YearMonth(key: "2026-11-02"), YearMonth(year: 2026, month: 11))
        XCTAssertNil(YearMonth(key: "2026-13"))
        XCTAssertEqual(ISOCalendar.months(of: ["2026-11-03", "2026-10-31", "2026-11-01", "x"]), ["2026-10", "2026-11"])
    }

    func testGroupingByWeek() {
        let p = MyPlanResponse(from: "2026-11-01", to: "2026-11-10", publishedAt: nil, days: [])
        let groups = p.filledDays.groupedByISOWeek()
        XCTAssertEqual(groups.map(\.label), ["KW 44", "KW 45", "KW 46"])
        XCTAssertEqual(groups[1].items.count, 7)
        XCTAssertEqual(groups[1].start, "2026-11-02")
    }

    func testTodayRespectsTimeZone() {
        // 23:30 UTC am 31.10. ist in Berlin schon der 1.11.
        let d = ISO8601DateFormatter().date(from: "2026-10-31T23:30:00Z")!
        XCTAssertEqual(ISOCalendar.today(now: d, timeZone: TimeZone(identifier: "Europe/Berlin")!), "2026-11-01")
        XCTAssertEqual(ISOCalendar.today(now: d, timeZone: TimeZone(identifier: "UTC")!), "2026-10-31")
    }
}

final class FormattingTests: XCTestCase {
    func testDates() {
        XCTAssertEqual(PQFormat.date("2026-11-02"), "02.11.2026")
        XCTAssertEqual(PQFormat.dayMonth("2026-11-02"), "02.11.")
        XCTAssertEqual(PQFormat.dateLong("2026-11-02"), "Montag, 02.11.2026")
        XCTAssertEqual(PQFormat.dateShort("2026-11-02"), "Mo, 02.11.")
        XCTAssertEqual(PQFormat.monthLabel(YearMonth(year: 2026, month: 3)), "März 2026")
        XCTAssertEqual(PQFormat.dayAndMonthName("2026-11-02"), "2. November")
        XCTAssertEqual(PQFormat.relativeDay("2026-11-03", today: "2026-11-02"), "Morgen")
        XCTAssertEqual(PQFormat.relativeDay("2026-11-02", today: "2026-11-02"), "Heute")
        XCTAssertEqual(PQFormat.relativeDay("2026-11-05", today: "2026-11-02"), "Do, 05.11.")
        XCTAssertEqual(PQFormat.range("2026-07-20", "2026-07-31"), "20.07.2026 – 31.07.2026")
    }

    func testTimestamps() {
        let berlin = TimeZone(identifier: "Europe/Berlin")!
        XCTAssertEqual(PQFormat.dateTime("2026-10-29T17:56:31+01:00", timeZone: berlin), "29.10.2026, 17:56")
        XCTAssertEqual(PQFormat.dateTime("2026-10-29T16:56:31.123Z", timeZone: berlin), "29.10.2026, 17:56")
        XCTAssertEqual(PQFormat.dateTime("kaputt"), "")
    }

    func testNumbers() {
        XCTAssertEqual(PQFormat.number(12.5), "12,5")
        XCTAssertEqual(PQFormat.number(30), "30")
        XCTAssertEqual(PQFormat.number(14.25), "14,3")
        XCTAssertEqual(PQFormat.days(1), "1 Tag")
        XCTAssertEqual(PQFormat.days(2.5), "2,5 Tage")
    }
}

final class ColorTests: XCTestCase {
    func testParsingAndContrast() {
        XCTAssertEqual(RGB(hex: "#fff")?.hex, "#ffffff")
        XCTAssertNil(RGB(hex: "#ggg"))
        XCTAssertNil(RGB(hex: "red"))
        let blue = RGB(hex: BrandColors.quadroBlueHex)!
        XCTAssertEqual(blue.contrast(with: .white), 6.2, accuracy: 0.1) // docs/BRAND.md
        let ink = RGB(hex: BrandColors.inkHex)!
        XCTAssertGreaterThan(ink.contrast(with: .white), 15)
        XCTAssertEqual(RGB(hex: BrandColors.signalOrangeTextHex)!.contrast(with: .white), 5.2, accuracy: 0.15)
    }

    func testReadableAndSafeForeground() {
        XCTAssertEqual(ColorContrast.readableForeground(on: "#facc15"), "#111827")
        XCTAssertEqual(ColorContrast.readableForeground(on: "#14213d"), "#ffffff")
        // gelieferte fg unlesbar → berechnet
        XCTAssertEqual(ColorContrast.safeForeground(bg: "#facc15", fg: "#ffffff"), "#111827")
        XCTAssertEqual(ColorContrast.safeForeground(bg: "#dc2626", fg: "#ffffff"), "#ffffff")
    }

    func testBrandTokensMeetContrast() {
        for dark in [false, true] {
            // sehr helle Mandantenfarbe
            let t = BrandTokens(primary: "#fde047", accent: "#ffffff", dark: dark)
            let surface = RGB(hex: dark ? BrandColors.darkCardHex : BrandColors.lightCardHex)!
            XCTAssertGreaterThanOrEqual(RGB(hex: t.primary)!.contrast(with: surface), 3)
            XCTAssertGreaterThanOrEqual(RGB(hex: t.primaryText)!.contrast(with: surface), 4.5)
            XCTAssertGreaterThanOrEqual(RGB(hex: t.primaryForeground)!.contrast(with: RGB(hex: t.primary)!), 4.5)
        }
        // ungültige Farbe → Planquadro-Standard
        XCTAssertEqual(BrandTokens(primary: "kaputt", accent: "", dark: false).primaryText, "#2557d6")
    }
}

final class ServerAddressTests: XCTestCase {
    func testNormalize() throws {
        XCTAssertEqual(try ServerAddress.normalize("planquadro.firma.de").absoluteString, "https://planquadro.firma.de/api/v1/")
        XCTAssertEqual(try ServerAddress.normalize(" https://PQ.Firma.de/ ").absoluteString, "https://pq.firma.de/api/v1/")
        XCTAssertEqual(try ServerAddress.normalize("https://x.de/pq/api/v1").absoluteString, "https://x.de/pq/api/v1/")
        XCTAssertEqual(try ServerAddress.normalize("https://x.de:8443/pq?x=1#a").absoluteString, "https://x.de:8443/pq/api/v1/")
        XCTAssertEqual(try ServerAddress.normalize("http://localhost:8080").absoluteString, "http://localhost:8080/api/v1/")
        XCTAssertThrowsError(try ServerAddress.normalize("http://firma.de"))
        XCTAssertThrowsError(try ServerAddress.normalize("ftp://firma.de"))
        XCTAssertThrowsError(try ServerAddress.normalize("https://user:pw@firma.de"))
        XCTAssertThrowsError(try ServerAddress.normalize(""))
        XCTAssertThrowsError(try ServerAddress.normalize("https://bad host"))
    }

    func testDisplayAndTenant() throws {
        XCTAssertEqual(ServerAddress.display(URL(string: "https://pq.firma.de/api/v1/")!), "https://pq.firma.de")
        XCTAssertEqual(try ServerAddress.normalizeTenant(" ACME "), "acme")
        XCTAssertNil(try ServerAddress.normalizeTenant(""))
        XCTAssertThrowsError(try ServerAddress.normalizeTenant("ac me"))
        XCTAssertThrowsError(try ServerAddress.normalizeTenant("-acme"))
    }
}

final class PKCETests: XCTestCase {
    func testSHA256Vectors() {
        XCTAssertEqual(PQSHA256.hex(Data("abc".utf8)), "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad")
        XCTAssertEqual(PQSHA256.hex(Data()), "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855")
        let long = String(repeating: "a", count: 1000)
        XCTAssertEqual(PQSHA256.hex(Data(long.utf8)), "41edece42d63e8d9bf515a9ba6932e1c20cbc9f5a5d134645adb5db1b9737ea3")
    }

    func testRFC7636Example() {
        XCTAssertEqual(PKCE.challengeS256("dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk"), "E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM")
    }

    func testAuthorizeURLAndCallback() throws {
        let now = Date(timeIntervalSince1970: 1_800_000_000)
        let r = try PKCE.authorizeRequest(authorizeURL: "https://idp.example/authorize?client_id=planquadro-app&scope=openid%20email", now: now)
        let items = URLComponents(url: r.url, resolvingAgainstBaseURL: false)!.queryItems!
        func v(_ n: String) -> String? { items.first { $0.name == n }?.value }
        XCTAssertEqual(v("client_id"), "planquadro-app")
        XCTAssertEqual(v("scope"), "openid email", "vorhandener scope bleibt")
        XCTAssertEqual(v("response_type"), "code")
        XCTAssertEqual(v("redirect_uri"), "planquadro://auth")
        XCTAssertEqual(v("code_challenge_method"), "S256")
        XCTAssertEqual(v("code_challenge"), PKCE.challengeS256(r.session.verifier))
        XCTAssertEqual(v("state"), r.session.state)
        XCTAssertEqual(v("nonce"), r.session.nonce)
        XCTAssertGreaterThanOrEqual(r.session.verifier.count, 43)
        XCTAssertLessThanOrEqual(r.session.verifier.count, 128)

        let ok = URL(string: "planquadro://auth?code=abc&state=\(r.session.state)")!
        let req = try PKCE.tokenRequest(callback: ok, session: r.session, deviceName: "iPhone", now: now.addingTimeInterval(30))
        XCTAssertEqual(req.code, "abc")
        XCTAssertEqual(req.codeVerifier, r.session.verifier)
        XCTAssertEqual(req.nonce, r.session.nonce)
        XCTAssertEqual(req.redirectURI, "planquadro://auth")

        // falscher state, Ablauf, Fehler, fehlende Sitzung
        XCTAssertThrowsError(try PKCE.tokenRequest(callback: URL(string: "planquadro://auth?code=abc&state=x")!, session: r.session, deviceName: nil, now: now))
        XCTAssertThrowsError(try PKCE.tokenRequest(callback: ok, session: r.session, deviceName: nil, now: now.addingTimeInterval(11 * 60)))
        XCTAssertThrowsError(try PKCE.tokenRequest(callback: URL(string: "planquadro://auth?error=access_denied&state=\(r.session.state)")!, session: r.session, deviceName: nil, now: now))
        XCTAssertThrowsError(try PKCE.tokenRequest(callback: ok, session: nil, deviceName: nil, now: now))
        // Fragment-Variante
        XCTAssertEqual(PKCE.parseCallback(URL(string: "planquadro://auth#code=z&state=s")!).code, "z")
    }

    func testRejectsInsecureAuthorizeURL() {
        XCTAssertThrowsError(try PKCE.authorizeRequest(authorizeURL: "http://idp.example/authorize"))
    }
}

final class ErrorTests: XCTestCase {
    func testMappingFromContractBody() {
        let e = APIError.from(status: 401, body: Data(#"{"error":{"code":"token_expired","message":"Sitzung abgelaufen."}}"#.utf8), retryAfterHeader: nil)
        XCTAssertEqual(e.knownCode, .tokenExpired)
        XCTAssertEqual(e.userMessage, "Sitzung abgelaufen.")
        let f = APIError.from(status: 503, body: Data("<html>".utf8), retryAfterHeader: nil)
        XCTAssertEqual(f.knownCode, .maintenance)
        XCTAssertTrue(f.allowsCachedRead)
        let r = APIError.from(status: 429, body: Data(), retryAfterHeader: "30")
        XCTAssertEqual(r.userMessage, "Zu viele Anfragen. Bitte warten Sie 30 Sekunden.")
        let now = Date(timeIntervalSince1970: 1_800_000_000)
        let http = APIError.parseRetryAfter("Fri, 15 Jan 2027 08:00:30 GMT", now: Date(timeIntervalSince1970: 1_800_000_000))
        XCTAssertNotNil(http)
        _ = now
    }

    func testWrapAndLoginMessages() {
        XCTAssertEqual(APIError.wrap(URLError(.notConnectedToInternet)).knownCode, .networkError)
        XCTAssertEqual(APIError.wrap(URLError(.timedOut)).knownCode, .timeout)
        XCTAssertTrue(loginErrorMessage(APIError(status: 403, .passwordChangeRequired, serverMessage: "x")).contains("Weboberfläche"))
        XCTAssertTrue(loginErrorMessage(APIError(status: 503, .maintenance, serverMessage: "Bis 20 Uhr.")).hasSuffix("Bis 20 Uhr."))
        XCTAssertEqual(loginErrorMessage(APIError(status: 401, .invalidCredentials, serverMessage: "egal")), "Benutzername oder Passwort ist falsch.")
    }
}
