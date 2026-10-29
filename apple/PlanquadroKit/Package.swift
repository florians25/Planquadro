// swift-tools-version: 5.9
//
// PlanquadroKit – plattformneutrale Logik der Apple-App (Modelle, API-Client,
// Token-Verwaltung, Offline-Cache, Sync, Live-Updates per SSE, Vertraulichkeitsregeln,
// Formatierung, Demo-Server). Läuft auch unter Linux (`swift build && swift test`),
// damit die Logik ohne Mac geprüft werden kann. Apple-spezifisches (Keychain,
// CryptoKit) steht hinter `#if canImport(...)`.
import PackageDescription

let package = Package(
    name: "PlanquadroKit",
    defaultLocalization: "de",
    platforms: [
        .iOS(.v17),
        .macOS(.v14),
        .watchOS(.v10),
    ],
    products: [
        .library(name: "PlanquadroKit", targets: ["PlanquadroKit"]),
    ],
    targets: [
        .target(
            name: "PlanquadroKit",
            resources: [.copy("Resources/Fixtures")]
        ),
        .testTarget(
            name: "PlanquadroKitTests",
            dependencies: ["PlanquadroKit"]
        ),
    ]
)
