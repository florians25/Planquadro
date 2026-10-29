import XCTest
@testable import PlanquadroKit

final class DeepLinkTests: XCTestCase {
    func testRoundTripAndParsing() {
        let links: [DeepLink] = [.plan(nil), .plan(YearMonth(year: 2026, month: 11)), .day("2026-11-02", userID: nil), .day("2026-11-02", userID: 14),
                                 .team(YearMonth(year: 2026, month: 12)), .absences(year: 2026, newRequest: true), .absences(year: nil, newRequest: false),
                                 .notifications, .more]
        for l in links { XCTAssertEqual(DeepLink(url: l.url), l, l.url.absoluteString) }
        XCTAssertNil(DeepLink(url: URL(string: "planquadro://auth?code=x")!))
        XCTAssertNil(DeepLink(url: URL(string: "https://plan/2026-11")!))
        XCTAssertNil(DeepLink(url: URL(string: "planquadro://tag/kaputt")!))
        XCTAssertEqual(DeepLink.day("2026-11-02", userID: nil).section, .plan)
    }

    func testNotificationAndPushTargets() {
        XCTAssertEqual(DeepLink.forNotificationLink("mein_plan"), .plan(nil))
        XCTAssertEqual(DeepLink.forNotificationLink("urlaub"), .absences(year: nil, newRequest: false))
        XCTAssertNil(DeepLink.forNotificationLink(nil))
        XCTAssertEqual(DeepLink.forPush(["data": ["type": "plan_changed", "dates": "2026-12-03,2026-11-30"]]), .plan(YearMonth(year: 2026, month: 11)))
        XCTAssertEqual(DeepLink.forPush(["link": "team"]), .team(nil))
    }

    func testSectionVisibility() throws {
        let json = ##"{"user":{"id":1,"username":"u","first_name":"A","last_name":"B","role":"r","role_label":"R"},"permissions":{"plan_view":false},"modules":{"absences":false},"tenant":{"name":"T","slug":"t","branding":{"primary":"#000000","accent":"#ffffff","logo_url":null}}}"##
        var me = try JSONDecoder().decode(MeResponse.self, from: Data(json.utf8))
        XCTAssertFalse(AppSection.team.isVisible(for: me))
        XCTAssertFalse(AppSection.absences.isVisible(for: me))
        me.permissions.planView = true
        XCTAssertTrue(AppSection.team.isVisible(for: me))
        XCTAssertTrue(AppSection.more.isVisible(for: me))
    }
}
