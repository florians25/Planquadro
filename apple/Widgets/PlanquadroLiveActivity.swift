#if canImport(ActivityKit) && !PQ_NO_LIVE_ACTIVITIES
import ActivityKit
import SwiftUI
import WidgetKit
import PlanquadroKit

/// Live-Aktivität „Heutiger Einsatz“: Sperrbildschirm und Dynamic Island.
struct PlanquadroLiveActivity: Widget {
    var body: some WidgetConfiguration {
        ActivityConfiguration(for: PlanquadroActivityAttributes.self) { context in
            LiveActivityLockView(attributes: context.attributes, state: context.state)
                .padding(16)
                .activityBackgroundTint(Color.pqInk.opacity(0.85))
                .activitySystemActionForegroundColor(.white)
                .widgetURL(DeepLink.day(context.attributes.date, userID: nil).url)
        } dynamicIsland: { context in
            DynamicIsland {
                DynamicIslandExpandedRegion(.leading) {
                    CodeBadge(code: context.state.code, color: context.state.color, fg: context.state.fg, size: .large)
                }
                DynamicIslandExpandedRegion(.center) {
                    VStack(alignment: .leading, spacing: 2) {
                        Text(context.state.headline).font(.caption).foregroundStyle(.secondary)
                        Text(context.state.label).font(.headline).lineLimit(1)
                    }
                }
                DynamicIslandExpandedRegion(.trailing) {
                    PlanquadroMark(style: .onDark).frame(width: 22, height: 22)
                }
                DynamicIslandExpandedRegion(.bottom) {
                    if let place = context.state.place {
                        Label(place, systemImage: "mappin.and.ellipse")
                            .font(.caption)
                            .frame(maxWidth: .infinity, alignment: .leading)
                    }
                }
            } compactLeading: {
                Text(context.state.code)
                    .font(.caption.weight(.bold))
                    .foregroundStyle(Color(hex: context.state.color, fallback: .pqOrange))
            } compactTrailing: {
                Text(context.state.label)
                    .font(.caption2)
                    .lineLimit(1)
                    .frame(maxWidth: 70)
            } minimal: {
                Text(context.state.code)
                    .font(.caption2.weight(.bold))
                    .foregroundStyle(Color(hex: context.state.color, fallback: .pqOrange))
            }
            .widgetURL(DeepLink.day(context.attributes.date, userID: nil).url)
            .keylineTint(Color.pqOrange)
        }
    }
}

struct LiveActivityLockView: View {
    var attributes: PlanquadroActivityAttributes
    var state: PlanquadroActivityAttributes.ContentState

    var body: some View {
        HStack(spacing: 14) {
            CodeBadge(code: state.code, color: state.color, fg: state.fg, size: .large)
            VStack(alignment: .leading, spacing: 3) {
                Text(state.headline)
                    .font(.caption.weight(.semibold))
                    .foregroundStyle(Color.pqOrange)
                Text(state.label)
                    .font(.headline)
                    .foregroundStyle(.white)
                    .lineLimit(1)
                if let place = state.place {
                    Label(place, systemImage: "mappin.and.ellipse")
                        .font(.caption)
                        .foregroundStyle(Color.white.opacity(0.8))
                        .lineLimit(1)
                }
            }
            Spacer(minLength: 0)
            VStack(alignment: .trailing, spacing: 4) {
                PlanquadroMark(style: .onDark).frame(width: 22, height: 22)
                Text(PQFormat.dateShort(attributes.date))
                    .font(.caption2)
                    .foregroundStyle(Color.white.opacity(0.8))
            }
        }
        .accessibilityElement(children: .combine)
    }
}
#endif
