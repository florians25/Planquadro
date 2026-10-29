import type { MyPlanResponse, PlanDay } from '../api/types';
import {
  addMonths,
  formatDateTime,
  formatDayMonth,
  isoWeekday,
  groupByIsoWeek,
  addDays,
  monthKey,
  monthLabel,
  monthRange,
  parseMonthKey,
  todayIso,
  weekdayShort,
  yearMonthOf,
} from '../lib/dates';
import { html, type SafeHtml } from '../lib/html';
import { CacheKeys } from '../store/cache';
import { loadCached } from '../store/data';
import { codeBadge, errorState, loading } from '../ui/components';
import { icon } from '../ui/icons';
import type { Screen } from '../ui/screen';

function dayRow(day: PlanDay, today: string): SafeHtml {
  const isToday = day.date === today;
  const weekend = day.weekday >= 6;
  const e = day.entry;
  const cls = ['day', isToday ? 'is-today' : '', weekend ? 'is-weekend' : '', day.holiday ? 'is-holiday' : '', e ? '' : 'is-free'].join(' ');
  const body = html`
    <span class="day-date">
      <span class="day-wd">${weekdayShort(day.date)}</span>
      <span class="day-num">${formatDayMonth(day.date)}</span>
    </span>
    <span class="day-main">
      ${e ? html`<span class="day-entry">${codeBadge(e.code, e.color, e.fg)}<span class="day-label">${e.label}</span></span>` : html`<span class="day-label muted">${day.holiday ? '' : 'frei'}</span>`}
      ${day.holiday ? html`<span class="chip chip-holiday">${day.holiday.name}</span>` : ''}
      ${e?.remarks ? html`<span class="day-remarks">${e.remarks}</span>` : ''}
    </span>
    ${isToday ? html`<span class="today-tag">Heute</span>` : ''}
    ${e ? icon('right', 'ic ic-chevron') : ''}`;
  const current = isToday ? html` aria-current="date"` : '';
  // Alle Tage mit Eintrag öffnen die Details; freie Tage sind reine Zeilen.
  return e
    ? html`<li><a class="${cls}" href="#/tag/${day.date}"${current}>${body}</a></li>`
    : html`<li><div class="${cls}"${current}>${body}</div></li>`;
}

export const planScreen: Screen = async (ctx) => {
  const { session } = ctx;
  const today = todayIso();
  const ym = (ctx.args[0] && parseMonthKey(ctx.args[0])) || yearMonthOf(today);
  const key = monthKey(ym);
  ctx.setTitle('Mein Plan');

  const draw = async (force = false) => {
    const isCurrentMonth = key === today.slice(0, 7);
    const nav = html`<div class="toolbar" role="group" aria-label="Monat wählen">
      <a class="btn btn-icon" href="#/plan/${monthKey(addMonths(ym, -1))}" aria-label="Vorheriger Monat">${icon('left')}</a>
      <h2 class="toolbar-title" aria-live="polite">${monthLabel(ym)}</h2>
      <a class="btn btn-icon" href="#/plan/${monthKey(addMonths(ym, 1))}" aria-label="Nächster Monat">${icon('right')}</a>
      ${isCurrentMonth ? '' : html`<a class="btn btn-sm" href="#/plan">Heute</a>`}
      <button type="button" class="btn btn-icon" data-action="refresh" aria-label="Aktualisieren">${icon('refresh')}</button>
    </div>`;
    if (!force) ctx.render(html`${nav}${loading()}`);
    try {
      const { from, to } = monthRange(ym);
      const loaded = await loadCached<MyPlanResponse>(session.cache, CacheKeys.plan(key), () => session.api!.getMyPlan(from, to), {
        online: session.online,
        force,
      });
      if (!ctx.isCurrent()) return;
      ctx.setDataState(loaded);
      const plan = loaded.data;
      // Lücken auffüllen, falls der Server freie Tage weglässt
      const byDate = new Map(plan.days.map((d) => [d.date, d]));
      const days: PlanDay[] = [];
      for (let d = from; d <= to; d = addDays(d, 1)) {
        days.push(byDate.get(d) ?? { date: d, weekday: isoWeekday(d), holiday: null, entry: null });
      }
      const groups = groupByIsoWeek(days);
      ctx.render(html`${nav}
        ${plan.published_at ? html`<p class="meta">Veröffentlicht: ${formatDateTime(plan.published_at)}</p>` : ''}
        ${groups.map(
          (g) => html`<section class="week" aria-labelledby="kw-${g.year}-${g.week}">
            <h3 class="week-head" id="kw-${g.year}-${g.week}">${g.label} <span class="muted">· ${formatDayMonth(g.start)}–${formatDayMonth(addDays(g.start, 6))}</span></h3>
            <ul class="day-list">${g.items.map((d) => dayRow(d, today))}</ul>
          </section>`,
        )}`);
      const todayEl = ctx.root.querySelector<HTMLElement>('[aria-current="date"]');
      if (todayEl && !force) todayEl.scrollIntoView({ block: 'center', behavior: 'auto' });
    } catch (e) {
      if (!ctx.isCurrent()) return;
      ctx.setDataState(null);
      ctx.render(html`${nav}${errorState(e)}`);
    }
  };

  const refresh = async () => {
    await session.sync();
    await draw(true);
  };
  ctx.onRefresh(refresh);
  ctx.root.addEventListener('click', (ev) => {
    const t = (ev.target as HTMLElement).closest('[data-action]');
    if (!t) return;
    const a = t.getAttribute('data-action');
    if (a === 'refresh' || a === 'retry') void refresh();
  });
  await draw();
};
