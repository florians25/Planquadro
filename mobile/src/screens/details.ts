import type { AssignmentDetailsResponse } from '../api/types';
import { formatDateLong, isIsoDate } from '../lib/dates';
import { html, type SafeHtml } from '../lib/html';
import { platform } from '../platform/native';
import { CacheKeys } from '../store/cache';
import { loadCached } from '../store/data';
import { codeBadge, emptyState, errorState, loading } from '../ui/components';
import { icon } from '../ui/icons';
import type { Screen } from '../ui/screen';

/** Link in die Karten-App (Apple Karten auf iOS, sonst Google Maps / geo:). */
export function mapsUrl(address: string | null, lat?: number | null, lng?: number | null): string {
  const hasCoords = typeof lat === 'number' && typeof lng === 'number';
  if (platform === 'ios') {
    return hasCoords ? `https://maps.apple.com/?ll=${lat},${lng}&q=${encodeURIComponent(address ?? '')}` : `https://maps.apple.com/?q=${encodeURIComponent(address ?? '')}`;
  }
  if (platform === 'android') {
    return hasCoords ? `geo:${lat},${lng}?q=${lat},${lng}(${encodeURIComponent(address ?? '')})` : `geo:0,0?q=${encodeURIComponent(address ?? '')}`;
  }
  const q = hasCoords ? `${lat},${lng}` : address ?? '';
  return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(q)}`;
}

function telHref(phone: string): string {
  return `tel:${phone.replace(/[^\d+]/g, '')}`;
}

function row(label: string, value: SafeHtml | string | null | undefined): SafeHtml {
  if (value === null || value === undefined || value === '') return html``;
  return html`<div class="kv"><dt>${label}</dt><dd>${value}</dd></div>`;
}

function linkRow(label: string, href: string, text: string, ic: string, external = false): SafeHtml {
  return html`<div class="kv"><dt>${label}</dt><dd><a class="link-row" href="${href}"${external ? html` rel="external noopener"` : ''}>${icon(ic)}<span>${text}</span></a></dd></div>`;
}

function renderDetails(d: AssignmentDetailsResponse): SafeHtml {
  const e = d.entry;
  const c = d.customer;
  const l = d.location;
  return html`
    <header class="detail-head card">
      <p class="detail-date">${formatDateLong(d.date)}</p>
      ${e
        ? html`<div class="detail-entry">${codeBadge(e.code, e.color, e.fg, 'badge-lg')}<h2>${e.label}</h2></div>`
        : html`<h2>Kein Eintrag</h2>`}
    </header>
    ${e?.remarks ? html`<section class="card"><h3>Bemerkungen</h3><p class="pre">${e.remarks}</p></section>` : ''}
    ${d.area
      ? html`<section class="card"><h3>Bereich</h3><dl>${row('Code', d.area.code)}${row('Name', d.area.name)}${row('Art', d.area.type)}</dl></section>`
      : ''}
    ${c
      ? html`<section class="card"><h3>Kunde</h3><dl>
          ${row('Name', c.name)}
          ${row('Kontakt', c.contact)}
          ${c.address ? linkRow('Adresse', mapsUrl(c.address), c.address, 'map', true) : ''}
          ${c.phone ? linkRow('Telefon', telHref(c.phone), c.phone, 'phone') : ''}
          ${c.email ? linkRow('E-Mail', `mailto:${c.email}`, c.email, 'mail') : ''}
          ${row('Hinweise', c.notes)}
        </dl></section>`
      : ''}
    ${l
      ? html`<section class="card"><h3>Standort</h3><dl>
          ${row('Name', l.name)}
          ${l.address || (l.lat !== null && l.lng !== null)
            ? linkRow('Adresse', mapsUrl(l.address, l.lat, l.lng), l.address ?? `${l.lat}, ${l.lng}`, 'map', true)
            : ''}
        </dl></section>`
      : ''}
    <section class="card"><h3>Kolleginnen und Kollegen</h3>
      ${d.colleagues.length
        ? html`<ul class="plain-list">${d.colleagues.map((p) => html`<li>${p.name}</li>`)}</ul>`
        : html`<p class="muted">Niemand sonst eingeplant.</p>`}
    </section>`;
}

export const detailsScreen: Screen = async (ctx) => {
  const { session } = ctx;
  const date = ctx.args[0] ?? '';
  const userId = ctx.args[1] ? Number(ctx.args[1]) : session.me!.user.id;
  const own = userId === session.me!.user.id;
  ctx.setTitle('Einsatzdetails', own ? `#/plan/${date.slice(0, 7)}` : `#/team/${date.slice(0, 7)}`);
  if (!isIsoDate(date) || !Number.isInteger(userId)) {
    ctx.render(emptyState('Ungültiges Datum.'));
    return;
  }
  const draw = async () => {
    ctx.render(loading());
    try {
      const loaded = await loadCached<AssignmentDetailsResponse>(
        session.cache,
        CacheKeys.details(date, userId),
        () => session.api!.getAssignment(date, own ? undefined : userId),
        { online: session.online },
      );
      if (!ctx.isCurrent()) return;
      ctx.setDataState(loaded);
      ctx.render(renderDetails(loaded.data));
    } catch (e) {
      if (!ctx.isCurrent()) return;
      ctx.setDataState(null);
      ctx.render(errorState(e));
    }
  };
  ctx.onRefresh(draw);
  ctx.root.addEventListener('click', (ev) => {
    if ((ev.target as HTMLElement).closest('[data-action="retry"]')) void draw();
  });
  await draw();
};
