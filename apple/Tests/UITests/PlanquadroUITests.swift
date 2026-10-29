import XCTest

/// UI-Tests im Demo-Modus (ohne Backend). Die Screenshots für README/Store entstehen im CI
/// zusätzlich per `simctl` (apple/scripts/screenshots.sh); hier werden die Bildschirme
/// angesteuert, geprüft und als Anhang im Testergebnis abgelegt.
final class PlanquadroUITests: XCTestCase {
    override func setUp() {
        continueAfterFailure = false
    }

    private func launch(_ screen: String, extra: [String] = []) -> XCUIApplication {
        let app = XCUIApplication()
        app.launchArguments = ["-PQDemo", "YES", "-PQReset", "YES", "-PQScreen", screen, "-PQNoAnimations", "YES"] + extra
        app.launch()
        return app
    }

    private func attach(_ app: XCUIApplication, _ name: String) {
        let a = XCTAttachment(screenshot: app.screenshot())
        a.name = name
        a.lifetime = .keepAlways
        add(a)
    }

    func testDemoPlanTabsAndDetails() {
        let app = launch("week")
        XCTAssertTrue(app.navigationBars["Mein Plan"].waitForExistence(timeout: 15))
        attach(app, "plan-woche")
        app.tabBars.buttons["Team"].tap()
        XCTAssertTrue(app.navigationBars["Team"].waitForExistence(timeout: 10))
        attach(app, "team")
        app.tabBars.buttons["Abwesenheiten"].tap()
        XCTAssertTrue(app.navigationBars["Abwesenheiten"].waitForExistence(timeout: 10))
        app.tabBars.buttons["Mitteilungen"].tap()
        XCTAssertTrue(app.navigationBars["Mitteilungen"].waitForExistence(timeout: 10))
        app.tabBars.buttons["Mehr"].tap()
        XCTAssertTrue(app.navigationBars["Mehr"].waitForExistence(timeout: 10))
        attach(app, "mehr")
    }

    func testLoginErrorsInDemo() {
        let app = launch("login")
        let user = app.textFields["login.username"]
        XCTAssertTrue(user.waitForExistence(timeout: 15))
        user.tap()
        user.typeText("gesperrt")
        let pw = app.secureTextFields["login.password"]
        pw.tap()
        pw.typeText("demo")
        app.buttons["login.submit"].tap()
        XCTAssertTrue(app.staticTexts.containing(NSPredicate(format: "label CONTAINS 'gesperrt'")).firstMatch.waitForExistence(timeout: 10))
        attach(app, "login-fehler")
    }

    func testRequestFormOpensFromDeepLinkScreen() {
        let app = launch("request")
        XCTAssertTrue(app.navigationBars["Antrag stellen"].waitForExistence(timeout: 15))
        attach(app, "antrag")
    }
}
