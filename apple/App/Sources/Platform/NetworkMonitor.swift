import Foundation
import Network

/// Online/Offline-Erkennung (NWPathMonitor).
final class NetworkMonitor: @unchecked Sendable {
    private let monitor = NWPathMonitor()
    private let queue = DispatchQueue(label: "de.planquadro.network")

    /// Liefert bei jeder Änderung `true` (online) bzw. `false`.
    func updates() -> AsyncStream<Bool> {
        AsyncStream { continuation in
            monitor.pathUpdateHandler = { path in
                continuation.yield(path.status == .satisfied)
            }
            continuation.onTermination = { [monitor] _ in monitor.cancel() }
            monitor.start(queue: queue)
        }
    }
}
