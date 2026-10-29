import XCTest
import SwiftUI
@testable import Planquadro
import PlanquadroKit

@MainActor
final class RouterTests: XCTestCase {
    func testDeepLinksSelectSectionAndPushDetails() {
        let r = Router()
        r.open(.day("2026-11-02", userID: nil))
        XCTAssertEqual(r.section, .plan)
        XCTAssertEqual(r.planPath.count, 1)
        XCTAssertEqual(r.planMonth, YearMonth(year: 2026, month: 11))
        r.open(.absences(year: 2027, newRequest: true))
        XCTAssertEqual(r.section, .absences)
        XCTAssertTrue(r.showRequestForm)
        XCTAssertEqual(r.absencesYear, 2027)
        r.open(.team(YearMonth(year: 2026, month: 12)))
        XCTAssertEqual(r.section, .team)
        XCTAssertEqual(r.teamMonth, YearMonth(year: 2026, month: 12))
        r.open(.plan(YearMonth(year: 2026, month: 12)))
        XCTAssertEqual(r.planMode, .month)
        XCTAssertEqual(r.planPath.count, 0)
    }
}

@MainActor
final class AppHelperTests: XCTestCase {
    func testPushTokenHex() {
        XCTAssertEqual(PushManager.hex(Data([0x00, 0x0f, 0xab, 0xff])), "000fabff")
    }

    func testNameSearchIgnoresCaseAndDiacritics() {
        XCTAssertTrue(TeamView.normalize("Yılmaz, Aylin").contains(TeamView.normalize("yilmaz")))
        XCTAssertTrue(TeamView.normalize("Müller").contains("muller"))
    }

    func testBrandThemeFallsBackToPlanquadro() {
        let t = BrandTheme(branding: Branding(primary: "kaputt", accent: "", logoURL: "http://unsicher.example/logo.png"), tenantName: "X", dark: false)
        XCTAssertNil(t.logoURL, "Logo nur über HTTPS")
        XCTAssertEqual(t.tenantName, "X")
    }

    func testCalendarLocalDate() {
        let d = CalendarExporter.localDate("2026-11-02")
        let c = Calendar.current.dateComponents([.year, .month, .day, .hour], from: d)
        XCTAssertEqual(c.year, 2026)
        XCTAssertEqual(c.month, 11)
        XCTAssertEqual(c.day, 2)
        XCTAssertEqual(c.hour, 0)
    }

    func testIntentAnswersFromSnapshot() {
        let today = ISOCalendar.today()
        let snap = PlanSnapshot(generatedAt: Date(), firstName: "Max", tenantName: "T", primaryColor: "#2557d6", accentColor: "#ff7a1a", demo: true,
                                days: [.init(date: ISOCalendar.addDays(today, 1), weekday: 1,
                                             entry: PublicEntry(kind: .assignment, code: "K1", label: "Kunde Nord", color: "#dc2626", fg: "#ffffff"))])
        XCTAssertTrue(snap.spokenSummary(for: ISOCalendar.addDays(today, 1), today: today).hasPrefix("Morgen"))
        XCTAssertEqual(PlanDayOption.dayAfterTomorrow.offset, 2)
    }
}
