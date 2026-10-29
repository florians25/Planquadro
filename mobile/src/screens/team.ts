import type { TeamPlanResponse } from '../api/types';
import { addMonths, formatDate, isoWeekday, monthKey, monthLabel, parseMonthKey, todayIso, weekdayShort, yearMonthOf } from '../lib/dates';
import { html, type SafeHtml } from '../lib/html';
import { CacheKeys } from '../store/cache';
import { loadCached } from '../store/data';
import { codeBadge, emptyState, errorState, loading } from '../ui/components';
import { icon } from '../ui/icons';
import type { Screen } from '../ui/screen';

/** Suchtext bleibt beim Monatswechsel erhalten. */
let searchTerm = '';

export function normalizeName(s: string): string {
  return s.toLocaleLowerCase('de-DE').normalize('NFD').replace(/[̀-ͯ]/g, '');
}

function grid(t: TeamPlanResponse, today: string): SafeHtml {
  const head = t.days.map((d) => {
    const cls = [isoWeekday(d) >= 6 ? 'we' : '', t.holidays[d] ? 'hol' : '', d === today ? 'today' : ''].join(' ');
    const title = t.holidays[d]?.name ?? '';
    return html`<th scope="col" class="${cls}" ${title ? html`title="${title}"` : ''} abbr="${formatDate(d)}">
      <span class="tg-wd">${weekdayShort(d).slice(0, 2)}</span><span class="tg-dn">${d.slice(8, 10)}</span></th>`;
  });
  const rows = t.users.map((u) => {
    const cells = t.days.map((d) => {
      const c = t.cells[`${u.id}_${d}`];
      const cls = [isoWeekday(d) >= 6 ? 'we' : '', t.holidays[d] ? 'hol' : '', d === today ? 'today' : ''].join(' ');
      if (!c) return html`<td class="${cls}"></td>`;
      if (c.confidential) {
        // Vertrauliche Abwesenheit: nur „A“, keine Details, kein Link
        return html`<td class="${cls}">${codeBadge('A', c.color, c.fg, 'badge-cell')}<span class="sr-only">abwesend</span></td>`;
      }
      const label = `${u.name}, ${formatDate(d)}: ${c.code}`;
      if (c.kind === 'assignment') {
        return html`<td class="${cls}"><a href="#/tag/${d}/${u.id}" aria-label="${label}">${codeBadge(c.code, c.color, c.fg, 'badge-cell')}</a></td>`;
      }
      return html`<td class="${cls}" aria-label="${label}">${codeBadge(c.code, c.color, c.fg, 'badge-cell')}</td>`;
    });
    return html`<tr data-name="${normalizeName(u.name)}" class="${u.is_me ? 'me' : ''}">
      <th scope="row" class="tg-name"><span class="tg-n">${u.name}</span>${u.role_label ? html`<span class="tg-r">${u.role_label}</span>` : ''}</th>
      ${cells}
    </tr>`;
  });
  return html`<div class="team-scroll" tabindex="0" role="region" aria-label="Teamplan ${monthLabel({ year: t.year, month: t.month })}">
    <table class="team-grid">
      <caption class="sr-only">Teamplan ${monthLabel({ year: t.year, month: t.month })}</caption>
      <thead><tr><th scope="col" class="tg-name">Name</th>${head}</tr></thead>
      <tbody>${rows}</tbody>
    </table>
  </div>`;
}

function applyFilter(root: HTMLElement): void {
  const q = normalizeName(searchTerm.trim());
  let visible = 0;
  root.querySelectorAll<HTMLTableRowElement>('tbody tr').forEach((tr) => {
    const show = !q || (tr.dataset['name'] ?? '').includes(q);
    tr.hidden = !show;
    if (show) visible++;
  });
  const info = root.querySelector('#team-count');
  if (info) info.textContent = q ? `${visible} Treffer` : '';
}

export const teamScreen: Screen = async (ctx) => {
  const { session } = ctx;
  const today = todayIso();
  const ym = (ctx.args[0] && parseMonthKey(ctx.args[0])) || yearMonthOf(today);
  const key = monthKey(ym);
  ctx.setTitle('Team');

  const toolbar = html`<div class="toolbar" role="group" aria-label="Monat wählen">
      <a class="btn btn-icon" href="#/team/${monthKey(addMonths(ym, -1))}" aria-label="Vorheriger Monat">${icon('left')}</a>
      <h2 class="toolbar-title" aria-live="polite">${monthLabel(ym)}</h2>
      <a class="btn btn-icon" href="#/team/${monthKey(addMonths(ym, 1))}" aria-label="Nächster Monat">${icon('right')}</a>
      <button type="button" class="btn btn-icon" data-action="refresh" aria-label="Aktualisieren">${icon('refresh')}</button>
    </div>
    <div class="search">
      <label for="team-search" class="sr-only">Nach Namen filtern</label>
      ${icon('search')}
      <input id="team-search" type="search" placeholder="Name suchen" autocomplete="off" enterkeyhint="search" value="${searchTerm}">
      <span id="team-count" class="muted" aria-live="polite"></span>
    </div>`;

  const draw = async (force = false) => {
    if (!force) ctx.render(html`${toolbar}${loading()}`);
    try {
      const loaded = await loadCached<TeamPlanResponse>(session.cache, CacheKeys.team(key), () => session.api!.getTeamPlan(ym.year, ym.month), {
        online: session.online,
      });
      if (!ctx.isCurrent()) return;
      ctx.setDataState(loaded);
      const t = loaded.data;
      ctx.render(html`${toolbar}${t.users.length ? grid(t, today) : emptyState('Keine Mitarbeiter sichtbar.')}
        <p class="legend muted">„A“ = abwesend (vertraulich). Tippe auf einen Einsatz für Details.</p>`);
      applyFilter(ctx.root);
      const todayTh = ctx.root.querySelector<HTMLElement>('thead th.today');
      const scroller = ctx.root.querySelector<HTMLElement>('.team-scroll');
      if (todayTh && scroller) scroller.scrollLeft = Math.max(0, todayTh.offsetLeft - 160);
    } catch (e) {
      if (!ctx.isCurrent()) return;
      ctx.setDataState(null);
      ctx.render(html`${toolbar}${errorState(e)}`);
    }
  };

  ctx.root.addEventListener('input', (ev) => {
    const t = ev.target as HTMLInputElement;
    if (t.id === 'team-search') {
      searchTerm = t.value;
      applyFilter(ctx.root);
    }
  });
  ctx.root.addEventListener('click', (ev) => {
    const a = (ev.target as HTMLElement).closest('[data-action]')?.getAttribute('data-action');
    if (a === 'refresh' || a === 'retry') void draw(true);
  });
  ctx.onRefresh(() => draw(true));
  await draw();
};
