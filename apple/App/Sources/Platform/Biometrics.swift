import Foundation
import LocalAuthentication
import PlanquadroKit

/// Face ID / Touch ID / Optic ID über LocalAuthentication.
struct DeviceBiometrics: BiometricAuthenticating {
    func availability() -> BiometryInfo {
        let ctx = LAContext()
        var error: NSError?
        let ok = ctx.canEvaluatePolicy(.deviceOwnerAuthenticationWithBiometrics, error: &error)
        let label: String
        switch ctx.biometryType {
        case .faceID: label = "Face ID"
        case .touchID: label = "Touch ID"
        case .opticID: label = "Optic ID"
        default: label = "Biometrie"
        }
        if ok { return BiometryInfo(available: true, label: label) }
        let reason: String
        switch LAError.Code(rawValue: error?.code ?? 0) {
        case .biometryNotEnrolled: reason = "\(label) ist auf diesem Gerät nicht eingerichtet."
        case .biometryLockout: reason = "\(label) ist gesperrt. Entsperren Sie das Gerät einmal mit dem Code."
        case .passcodeNotSet: reason = "Für \(label) muss ein Gerätecode gesetzt sein."
        default: reason = "Auf diesem Gerät ist keine Biometrie verfügbar."
        }
        return BiometryInfo(available: false, label: label, reason: reason)
    }

    func authenticate(reason: String) async -> Bool {
        let ctx = LAContext()
        ctx.localizedCancelTitle = "Abbrechen"
        do {
            return try await ctx.evaluatePolicy(.deviceOwnerAuthenticationWithBiometrics, localizedReason: reason)
        } catch {
            return false
        }
    }
}
