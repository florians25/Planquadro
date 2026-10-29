import { html, type SafeHtml } from '../lib/html';
import {
  hideSplash,
  isNative,
  minimizeApp,
  onAppStateChange,
  onAppUrlOpen,
  onBackButton,
  onNetworkChange,
  openSystemBrowser,
} from '../platform/native';
import { absencesScreen } from '../screens/absences';
import { detailsScreen } from '../screens/details';
import { renderLock, renderUnavailable } from '../screens/lock';
import { renderLogin } from '../screens/login';
import { moreScreen } from '../screens/more';
import { notificationsScreen } from '../screens/notifications';
import { planScreen } from '../screens/plan';
import { renderSetup } from '../screens/setup';
import { teamScreen } from '../screens/team';
import type { Loaded } from '../store/data';
import { NATIVE_REDIRECT_URI, type Session } from '../store/session';
import { offlineBanner, toast, messageOf } from './components';
import { icon } from './icons';
import { attachPullToRefresh } from './pull';
import type { Screen, ScreenCtx } from './screen';
import { setBrand, setThemeMode } from './theme';

interface Tab {
  id: string;
  route: string;
  label: string;
  icon: string;
  screen: Screen;
}

const DETAIL_ROUTES: Record<string, { screen: Screen; tab: string }> = {
  tag: { screen: detailsScreen, tab: 'plan' },
};

export class App {
  private shell: { main: HTMLElement; title: HTMLElement; back: HTMLAnchorElement; banner: HTMLElement; tabs: HTMLElement; pull: HTMLElement } | null = null;
  private routeSeq = 0;
  private refreshHandler: (() => Promise<void>) | null = null;
  private unread = 0;
  private lastDataState: Loaded<unknown> | null = null;
  private renderedStatus: string | null = null;

  constructor(private readonly root: HTMLElement, private readonly session: Session) {}

  start(): void {
    setThemeMode(this.session.prefs.theme);
    const b = this.session.me?.tenant.branding ?? this.session.prefs.branding;
    setBrand(b?.primary, b?.accent);

    this.session.onChange(() => this.renderForStatus());
    window.addEventListener('hashchange', () => {
      if (this.session.status === 'ready') this.route();
    });

    onNetworkChange((online) => {
      const was = this.session.online;
      this.session.online = online;
      if (this.session.status !== 'ready') return;
      if (online && !was) {
        toast('Wieder online.', 'ok');
        void this.afterReconnect();
        this.session.startLive();
      } else if (!online && was) {
        this.session.stopLive();
        this.updateBanner();
      }
    });

    onAppStateChange(async (active) => {
      // Live-Verbindung nur im Vordergrund
      if (!active) this.session.stopLive();
      await this.session.handleAppActive(active);
      if (active && this.session.status === 'ready') {
        void this.backgroundSync();
        this.session.startLive();
      }
    });

    onAppUrlOpen((url) => {
      if (url.startsWith(NATIVE_REDIRECT_URI)) {
        this.session.completeOidc(url).catch((e) => toast(messageOf(e), 'error'));
      }
    });

    onBackButton(() => {
      const back = this.shell?.back;
      if (back && !back.hidden && back.getAttribute('href')) {
        window.location.hash = back.getAttribute('href')!;
      } else {
        void minimizeApp();
      }
    });

    this.session.onSync((r) => {
      if (r.fullResync || r.refreshed.length) this.route();
    });

    // Externe Links (Karten) im System-Browser öffnen; tel:/mailto:/geo: übernimmt das System
    document.addEventListener('click', (ev) => {
      const a = (ev.target as HTMLElement).closest<HTMLAnchorElement>('a[rel~="external"]');
      if (!a) return;
      const href = a.getAttribute('href') ?? '';
      if (/^https:\/\//.test(href)) {
        ev.preventDefault();
        if (isNative) void openSystemBrowser(href);
        else window.open(href, '_blank', 'noopener');
      }
    });

    this.renderForStatus();
    void hideSplash();
  }

  private async afterReconnect(): Promise<void> {
    await this.backgroundSync();
    this.route();
  }

  private async backgroundSync(): Promise<void> {
    await this.session.sync();
    void this.refreshUnread();
  }

  // ---------------------------------------------------------------- Status → Bildschirm

  private renderForStatus(): void {
    const s = this.session;
    const status = s.status;
    if (status === 'ready') {
      const b = s.me?.tenant.branding;
      if (b) setBrand(b.primary, b.accent);
      if (this.renderedStatus !== 'ready' || !this.shell) {
        this.mountShell();
        this.renderedStatus = 'ready';
        if (!window.location.hash || window.location.hash === '#' || window.location.hash === '#/') {
          history.replaceState(null, '', '#/plan');
        }
        this.route();
        void this.backgroundSync();
        this.session.startLive();
      } else {
        this.renderTabs();
      }
      return;
    }
    this.shell = null;
    this.renderedStatus = status;
    if (status === 'needs_server') renderSetup(this.root, s);
    else if (status === 'needs_login') renderLogin(this.root, s);
    else if (status === 'locked') renderLock(this.root, s);
    else renderUnavailable(this.root, s);
    this.root.querySelector<HTMLElement>('h1')?.setAttribute('tabindex', '-1');
  }

  // ---------------------------------------------------------------- Shell

  private tabs(): Tab[] {
    const me = this.session.me;
    const tabs: Tab[] = [{ id: 'plan', route: '#/plan', label: 'Plan', icon: 'plan', screen: planScreen }];
    if (me?.permissions.plan_view) tabs.push({ id: 'team', route: '#/team', label: 'Team', icon: 'team', screen: teamScreen });
    if (me?.modules.absences) tabs.push({ id: 'abwesenheiten', route: '#/abwesenheiten', label: 'Abwesenheiten', icon: 'absences', screen: absencesScreen });
    tabs.push({ id: 'mitteilungen', route: '#/mitteilungen', label: 'Mitteilungen', icon: 'bell', screen: notificationsScreen });
    tabs.push({ id: 'mehr', route: '#/mehr', label: 'Mehr', icon: 'more', screen: moreScreen });
    return tabs;
  }

  private mountShell(): void {
    this.root.innerHTML = html`
      <a class="skip-link" href="#main">Zum Inhalt springen</a>
      <header class="topbar">
        <a class="btn btn-icon topbar-back" href="#/plan" hidden aria-label="Zurück">${icon('back')}</a>
        <h1 class="topbar-title" id="page-title" tabindex="-1">Planquadro</h1>
        ${this.session.isDemo ? html`<span class="chip chip-demo">Demo</span>` : ''}
      </header>
      <div class="banner-slot" id="banner" aria-live="polite"></div>
      <main id="main" class="main" tabindex="-1">
        <div class="pull-indicator" aria-hidden="true">${icon('refresh')}</div>
        <div class="screen"></div>
      </main>
      <nav class="tabbar" aria-label="Hauptnavigation"></nav>`.value;
    const main = this.root.querySelector<HTMLElement>('#main')!;
    this.shell = {
      main,
      title: this.root.querySelector<HTMLElement>('#page-title')!,
      back: this.root.querySelector<HTMLAnchorElement>('.topbar-back')!,
      banner: this.root.querySelector<HTMLElement>('#banner')!,
      tabs: this.root.querySelector<HTMLElement>('.tabbar')!,
      pull: this.root.querySelector<HTMLElement>('.pull-indicator')!,
    };
    attachPullToRefresh(main, this.shell.pull, () => this.refreshHandler);
    this.renderTabs();
  }

  private currentTabId(): string {
    const seg = window.location.hash.replace(/^#\/?/, '').split('/')[0] ?? '';
    return DETAIL_ROUTES[seg]?.tab ?? (seg || 'plan');
  }

  private renderTabs(): void {
    if (!this.shell) return;
    const active = this.currentTabId();
    const items: SafeHtml[] = this.tabs().map((t) => {
      const isActive = t.id === active;
      const badge = t.id === 'mitteilungen' && this.unread > 0
        ? html`<span class="tab-badge" aria-hidden="true">${this.unread > 99 ? '99+' : this.unread}</span>`
        : '';
      const srUnread = t.id === 'mitteilungen' && this.unread > 0 ? html`<span class="sr-only">, ${this.unread} ungelesen</span>` : '';
      return html`<a class="tab ${isActive ? 'active' : ''}" href="${t.route}" ${isActive ? html`aria-current="page"` : ''}>
        <span class="tab-icon">${icon(t.icon)}${badge}</span><span class="tab-label">${t.label}${srUnread}</span></a>`;
    });
    this.shell.tabs.innerHTML = html`${items}`.value;
  }

  private async refreshUnread(): Promise<void> {
    if (this.session.status !== 'ready' || !this.session.online) return;
    try {
      const n = await this.session.api!.getNotifications(1);
      this.setUnread(n.unread);
    } catch {
      /* Badge ist optional */
    }
  }

  private setUnread(n: number): void {
    if (n !== this.unread) {
      this.unread = n;
      this.renderTabs();
    }
  }

  private updateBanner(): void {
    if (!this.shell) return;
    const l = this.lastDataState;
    if (l?.fromCache) this.shell.banner.innerHTML = offlineBanner(l.savedAt, l.offlineReason).value;
    else if (!this.session.online) this.shell.banner.innerHTML = html`<div class="banner banner-offline" role="status">${icon('offline')}<span><strong>Offline</strong> – Änderungen sind erst wieder online möglich.</span></div>`.value;
    else this.shell.banner.innerHTML = '';
  }

  // ---------------------------------------------------------------- Routing

  route(): void {
    if (!this.shell || this.session.status !== 'ready') return;
    const parts = window.location.hash.replace(/^#\/?/, '').split('/').filter(Boolean).map(decodeURIComponent);
    const name = parts[0] ?? 'plan';
    const args = parts.slice(1);
    const tabs = this.tabs();
    const tab = tabs.find((t) => t.id === name);
    const detail = DETAIL_ROUTES[name];
    if (!tab && !detail) {
      history.replaceState(null, '', '#/plan');
      return this.route();
    }
    const screen = tab ? tab.screen : detail!.screen;
    const seq = ++this.routeSeq;
    this.refreshHandler = null;
    this.lastDataState = null;
    this.renderTabs();

    const old = this.shell.main.querySelector('.screen');
    const container = document.createElement('div');
    container.className = 'screen';
    old?.replaceWith(container);
    this.shell.main.scrollTop = 0;
    this.shell.back.hidden = true;

    const ctx: ScreenCtx = {
      session: this.session,
      root: container,
      args,
      setTitle: (title, back) => {
        if (!this.shell || seq !== this.routeSeq) return;
        this.shell.title.textContent = title;
        document.title = `${title} – Planquadro`;
        this.shell.back.hidden = !back;
        if (back) this.shell.back.setAttribute('href', back);
      },
      setDataState: (loaded) => {
        if (seq !== this.routeSeq) return;
        this.lastDataState = loaded;
        this.updateBanner();
      },
      onRefresh: (fn) => {
        if (seq === this.routeSeq) this.refreshHandler = fn;
      },
      navigate: (hash) => {
        window.location.hash = hash;
      },
      isCurrent: () => seq === this.routeSeq && this.session.status === 'ready',
      render: (content) => {
        if (seq === this.routeSeq) container.innerHTML = content.value;
      },
      setUnread: (n) => this.setUnread(n),
    };
    this.updateBanner();
    Promise.resolve(screen(ctx)).catch((e) => {
      if (seq === this.routeSeq) container.innerHTML = html`<div class="state state-error" role="alert"><p>${messageOf(e)}</p></div>`.value;
    });
    // Fokus für Screenreader auf den Titel setzen (nicht beim allerersten Laden)
    if (seq > 1) this.shell.title.focus({ preventScroll: true });
  }
}
