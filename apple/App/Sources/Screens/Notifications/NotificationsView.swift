import SwiftUI
import PlanquadroKit

/// Mitteilungen: ungelesen markiert, „Gelesen“ per Wischen, Öffnen springt zum Ziel.
struct NotificationsView: View {
    @Environment(AppModel.self) private var model
    @State private var state: LoadState<NotificationsResponse> = .loading

    var body: some View {
        List {
            LoadedContentRows(state: state, retry: load) { loaded in
                let n = loaded.data
                if n.items.isEmpty {
                    ContentUnavailableView("Keine Mitteilungen", systemImage: "bell.slash",
                                           description: Text("Hier erscheinen Hinweise zu Planänderungen und Anträgen."))
                        .listRowBackground(Color.clear)
                } else {
                    Section {
                        ForEach(n.items) { item in
                            NotificationRow(item: item) { await open(item) }
                                .swipeActions(edge: .trailing) {
                                    if !item.isRead {
                                        Button {
                                            Task { await markRead(item) }
                                        } label: {
                                            Label("Gelesen", systemImage: "checkmark")
                                        }
                                        .tint(.pqSuccess)
                                    }
                                }
                        }
                    } header: {
                        Text(n.unread > 0 ? "\(n.unread) ungelesen" : "Alles gelesen")
                    }
                }
            }
        }
        .listStyle(.insetGrouped)
        .dataStateBanner(state.loaded)
        .navigationTitle("Mitteilungen")
        .toolbar {
            ToolbarItem(placement: .topBarTrailing) {
                Button("Alle gelesen") { Task { await markAll() } }
                    .disabled((state.value?.unread ?? 0) == 0)
            }
        }
        .refreshable { await load() }
        .task(id: model.session.dataVersion) { await load() }
    }

    private func load() async {
        do {
            state = .loaded(try await model.session.notifications())
            model.push.setBadge(model.session.unreadCount)
        } catch {
            state = .failed(APIError.wrap(error).userMessage)
        }
    }

    private func markRead(_ item: NotificationItem) async {
        do {
            try await model.session.markNotificationRead(item.id)
            await load()
        } catch {
            model.show(error: error)
        }
    }

    private func markAll() async {
        do {
            try await model.session.markAllNotificationsRead()
            await load()
        } catch {
            model.show(error: error)
        }
    }

    private func open(_ item: NotificationItem) async {
        if !item.isRead && model.session.online { try? await model.session.markNotificationRead(item.id) }
        if let link = DeepLink.forNotificationLink(item.link) {
            model.handle(link: link)
        } else {
            await load()
        }
    }
}

struct NotificationRow: View {
    var item: NotificationItem
    var open: () async -> Void

    var body: some View {
        Button {
            Task { await open() }
        } label: {
            HStack(alignment: .top, spacing: 12) {
                Circle()
                    .fill(item.isRead ? Color.clear : Color.pqOrange)
                    .frame(width: 10, height: 10)
                    .padding(.top, 6)
                VStack(alignment: .leading, spacing: 4) {
                    Text(item.title)
                        .font(.body.weight(item.isRead ? .regular : .semibold))
                        .foregroundStyle(.primary)
                    Text(item.message)
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                    if let ts = item.createdAt {
                        Text(PQFormat.dateTime(ts))
                            .font(.caption)
                            .foregroundStyle(.tertiary)
                    }
                }
                Spacer(minLength: 0)
                if DeepLink.forNotificationLink(item.link) != nil {
                    Image(systemName: "chevron.right")
                        .font(.footnote.weight(.semibold))
                        .foregroundStyle(.tertiary)
                        .padding(.top, 6)
                }
            }
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .accessibilityElement(children: .combine)
        .accessibilityLabel((item.isRead ? "" : "Ungelesen: ") + item.title + ". " + item.message)
    }
}
