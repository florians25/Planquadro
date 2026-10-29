import type { MyAbsencesResponse, RequestType } from '../api/types';
import { formatDate, formatDateTime, isIsoDate, todayIso } from '../lib/dates';
import { html, type SafeHtml } from '../lib/html';
import { CacheKeys } from '../store/cache';
import { loadCached, requireOnline } from '../store/data';
import { errorState, formError, loading, messageOf, toast } from '../ui/components';
import { icon } from '../ui/icons';
import type { Screen } from '../ui/screen';

export const REQUEST_TYPES: Record<RequestType, string> = {
  urlaub_ganz: 'Urlaub (ganzer Tag)',
  urlaub_halb: 'Urlaub (halber Tag)',
  gleitzeit: 'Gleitzeit',
};

const STATUS: Record<string, { label: string; cls: string }> = {
  pending: { label: 'Offen', cls: 'st-pending' },
  approved: { label: 'Genehmigt', cls: 'st-ok' },
  rejected: { label: 'Abgelehnt', cls: 'st-err' },
};

function num(n: number): string {
  return n.toLocaleString('de-DE', { minimumFractionDigits: 0, maximumFractionDigits: 1 });
}

function account(a: MyAbsencesResponse): SafeHtml {
  const v = a.vacation;
  const tile = (label: string, value: number, cls = '') => html`<div class="tile ${cls}"><span class="tile-v">${num(value)}</span><span class="tile-l">${label}</span></div>`;
  return html`<section class="card" aria-labelledby="h-account">
    <h3 id="h-account">Urlaubskonto ${a.year}</h3>
    <div class="tiles">
      ${tile('Anspruch', v.entitlement)}${tile('Genommen', v.used)}${tile('Geplant', v.planned)}${tile('Rest', v.left, 'tile-strong')}
    </div>
    <p class="meta">Krankheitstage ${a.year}: <strong>${num(a.sick_days)}</strong></p>
  </section>`;
}

function periods(a: MyAbsencesResponse): SafeHtml {
  return html`<section class="card" aria-labelledby="h-periods"><h3 id="h-periods">Zeiträume</h3>
    ${a.items.length
      ? html`<ul class="list">${a.items.map(
          (i) => html`<li class="list-row"><span class="list-main"><strong>${i.label}</strong>
            <span class="muted">${i.start === i.end ? formatDate(i.start) : `${formatDate(i.start)} – ${formatDate(i.end)}`}</span></span>
            <span class="list-side">${num(i.days)} ${i.days === 1 ? 'Tag' : 'Tage'}${i.published ? '' : html`<span class="chip">nicht veröffentlicht</span>`}</span></li>`,
        )}</ul>`
      : html`<p class="muted">Keine Abwesenheiten in diesem Jahr.</p>`}
  </section>`;
}

function requests(a: MyAbsencesResponse): SafeHtml {
  return html`<section class="card" aria-labelledby="h-req"><h3 id="h-req">Meine Anträge</h3>
    ${a.requests.length
      ? html`<ul class="list">${a.requests.map((r) => {
          const st = STATUS[r.status] ?? { label: r.status, cls: '' };
          return html`<li class="list-row"><span class="list-main"><strong>${formatDate(r.date)}</strong>
            <span class="muted">${REQUEST_TYPES[r.type as RequestType] ?? r.type} · gestellt ${formatDateTime(r.created_at)}</span></span>
            <span class="status ${st.cls}">${st.label}</span></li>`;
        })}</ul>`
      : html`<p class="muted">Keine Anträge.</p>`}
  </section>`;
}

function requestForm(): SafeHtml {
  const t = todayIso();
  return html`<section class="card" aria-labelledby="h-form"><h3 id="h-form">Antrag stellen</h3>
    <form id="req-form" class="form" novalidate>
      <div id="req-error"></div>
      <div class="form-row2">
        <div class="field"><label for="req-from">Von</label><input id="req-from" name="from" type="date" required min="${t}" value="${t}"></div>
        <div class="field"><label for="req-to">Bis</label><input id="req-to" name="to" type="date" required min="${t}" value="${t}"></div>
      </div>
      <div class="field"><label for="req-type">Art</label>
        <select id="req-type" name="type">${Object.entries(REQUEST_TYPES).map(([v, l]) => html`<option value="${v}">${l}</option>`)}</select></div>
      <div class="field"><label for="req-remarks">Bemerkung (optional)</label><input id="req-remarks" name="remarks" type="text" maxlength="255" autocomplete="off"></div>
      <p class="hint">Je Werktag wird ein Antrag gestellt. Wochenenden und Feiertage werden übersprungen.</p>
      <button type="submit" class="btn btn-primary btn-block">Antrag senden</button>
    </form>
  </section>`;
}

export const absencesScreen: Screen = async (ctx) => {
  const { session } = ctx;
  const year = ctx.args[0] && /^\d{4}$/.test(ctx.args[0]) ? Number(ctx.args[0]) : new Date().getFullYear();
  const approvals = !!session.me?.modules.approvals;
  ctx.setTitle('Abwesenheiten');

  const toolbar = html`<div class="toolbar" role="group" aria-label="Jahr wählen">
    <a class="btn btn-icon" href="#/abwesenheiten/${year - 1}" aria-label="Vorheriges Jahr">${icon('left')}</a>
    <h2 class="toolbar-title" aria-live="polite">${year}</h2>
    <a class="btn btn-icon" href="#/abwesenheiten/${year + 1}" aria-label="Nächstes Jahr">${icon('right')}</a>
    <button type="button" class="btn btn-icon" data-action="refresh" aria-label="Aktualisieren">${icon('refresh')}</button>
  </div>`;

  const draw = async (force = false) => {
    if (!force) ctx.render(html`${toolbar}${loading()}`);
    try {
      const loaded = await loadCached<MyAbsencesResponse>(session.cache, CacheKeys.absences(year), () => session.api!.getMyAbsences(year), {
        online: session.online,
      });
      if (!ctx.isCurrent()) return;
      ctx.setDataState(loaded);
      const a = loaded.data;
      ctx.render(html`${toolbar}${account(a)}${approvals ? requestForm() : ''}${requests(a)}${periods(a)}`);
    } catch (e) {
      if (!ctx.isCurrent()) return;
      ctx.setDataState(null);
      ctx.render(html`${toolbar}${errorState(e)}`);
    }
  };

  ctx.root.addEventListener('click', (ev) => {
    const a = (ev.target as HTMLElement).closest('[data-action]')?.getAttribute('data-action');
    if (a === 'refresh' || a === 'retry') void draw(true);
  });

  ctx.root.addEventListener('submit', async (ev) => {
    const form = ev.target as HTMLFormElement;
    if (form.id !== 'req-form') return;
    ev.preventDefault();
    const errBox = form.querySelector('#req-error')!;
    const fd = new FormData(form);
    const from = String(fd.get('from') ?? '');
    const to = String(fd.get('to') ?? '');
    const type = String(fd.get('type') ?? '') as RequestType;
    const remarks = String(fd.get('remarks') ?? '').trim();
    errBox.innerHTML = '';
    if (!isIsoDate(from) || !isIsoDate(to) || to < from) {
      errBox.innerHTML = formError('Bitte einen gültigen Zeitraum wählen („Bis“ nicht vor „Von“).').value;
      return;
    }
    const btn = form.querySelector<HTMLButtonElement>('button[type="submit"]')!;
    btn.disabled = true;
    try {
      requireOnline(session.online);
      const res = await session.api!.createAbsenceRequest({ from, to, type, ...(remarks ? { remarks } : {}) });
      toast(
        `${res.created} ${res.created === 1 ? 'Antrag' : 'Anträge'} gestellt${res.skipped ? `, ${res.skipped} übersprungen` : ''}.`,
        'ok',
      );
      await draw(true);
    } catch (e) {
      errBox.innerHTML = formError(messageOf(e)).value;
      btn.disabled = false;
    }
  });

  ctx.onRefresh(() => draw(true));
  await draw();
};
