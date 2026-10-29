import type { NotificationItem, NotificationsResponse } from '../api/types';
import { formatDateTime } from '../lib/dates';
import { html } from '../lib/html';
import { CacheKeys } from '../store/cache';
import { loadCached, requireOnline } from '../store/data';
import { emptyState, errorState, loading, messageOf, toast } from '../ui/components';
import { icon } from '../ui/icons';
import type { Screen } from '../ui/screen';

/** Ziel-Route für den `link` einer Benachrichtigung. */
export function routeForLink(link: string | null): string | null {
  switch (link) {
    case 'mein_plan':
      return '#/plan';
    case 'abwesenheiten':
    case 'urlaub':
      return '#/abwesenheiten';
    case 'team':
    case 'gesamtplan':
      return '#/team';
    default:
      return null;
  }
}

function item(n: NotificationItem) {
  const target = routeForLink(n.link);
  return html`<li class="note ${n.is_read ? '' : 'unread'}">
    <div class="note-body">
      <p class="note-title">${n.is_read ? '' : html`<span class="dot" aria-hidden="true"></span><span class="sr-only">Ungelesen: </span>`}${n.title}</p>
      <p class="note-msg">${n.message}</p>
      <p class="note-time muted">${formatDateTime(n.created_at)}</p>
    </div>
    <div class="note-actions">
      ${target ? html`<button type="button" class="btn btn-sm" data-open="${n.id}" data-target="${target}">Öffnen</button>` : ''}
      ${n.is_read ? '' : html`<button type="button" class="btn btn-sm btn-ghost" data-read="${n.id}" aria-label="${n.title} als gelesen markieren">${icon('check')}<span>Gelesen</span></button>`}
    </div>
  </li>`;
}

export const notificationsScreen: Screen = async (ctx) => {
  const { session } = ctx;
  ctx.setTitle('Mitteilungen');
  let data: NotificationsResponse | null = null;

  const draw = async (force = false) => {
    if (!force) ctx.render(loading());
    try {
      const loaded = await loadCached<NotificationsResponse>(session.cache, CacheKeys.notifications, () => session.api!.getNotifications(30), {
        online: session.online,
      });
      if (!ctx.isCurrent()) return;
      data = loaded.data;
      ctx.setDataState(loaded);
      ctx.setUnread(data.unread);
      ctx.render(html`<div class="toolbar">
          <h2 class="toolbar-title">${data.unread ? `${data.unread} ungelesen` : 'Alles gelesen'}</h2>
          ${data.unread ? html`<button type="button" class="btn btn-sm" data-action="read-all">Alle gelesen</button>` : ''}
          <button type="button" class="btn btn-icon" data-action="refresh" aria-label="Aktualisieren">${icon('refresh')}</button>
        </div>
        ${data.items.length ? html`<ul class="notes">${data.items.map(item)}</ul>` : emptyState('Keine Mitteilungen.')}`);
    } catch (e) {
      if (!ctx.isCurrent()) return;
      ctx.setDataState(null);
      ctx.render(errorState(e));
    }
  };

  const markRead = async (id: number) => {
    requireOnline(session.online);
    await session.api!.markNotificationRead(id);
  };

  ctx.root.addEventListener('click', async (ev) => {
    const el = ev.target as HTMLElement;
    const action = el.closest('[data-action]')?.getAttribute('data-action');
    const readBtn = el.closest<HTMLElement>('[data-read]');
    const openBtn = el.closest<HTMLElement>('[data-open]');
    try {
      if (action === 'refresh' || action === 'retry') await draw(true);
      else if (action === 'read-all') {
        requireOnline(session.online);
        await session.api!.markAllNotificationsRead();
        await draw(true);
      } else if (readBtn) {
        await markRead(Number(readBtn.dataset['read']));
        await draw(true);
      } else if (openBtn) {
        const id = Number(openBtn.dataset['open']);
        const n = data?.items.find((x) => x.id === id);
        if (n && !n.is_read && session.online) await markRead(id).catch(() => undefined);
        ctx.navigate(openBtn.dataset['target'] ?? '#/plan');
      }
    } catch (e) {
      toast(messageOf(e), 'error');
    }
  });
  ctx.onRefresh(() => draw(true));
  await draw();
};
