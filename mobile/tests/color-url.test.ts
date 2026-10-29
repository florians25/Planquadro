import { describe, expect, it } from 'vitest';
import { brandTokens, contrastRatio, ensureContrast, parseHex, readableForeground, safeForeground, THEME_SURFACES } from '../src/lib/color';
import { cssColor, esc, html } from '../src/lib/html';
import { buildAuthorizeUrl, codeChallengeS256, parseAuthRedirect } from '../src/lib/pkce';
import { normalizeServerUrl, normalizeTenantSlug, ServerUrlError } from '../src/lib/url';

const ratio = (a: string, b: string) => contrastRatio(parseHex(a)!, parseHex(b)!);

describe('lesbare Vordergrundfarbe', () => {
  it.each([
    ['#ffffff', '#111827'],
    ['#facc15', '#111827'], // Gelb → dunkle Schrift
    ['#a3e635', '#111827'],
    ['#000080', '#ffffff'],
    ['#dc2626', '#ffffff'],
    ['#e2001a', '#ffffff'],
    ['#0f172a', '#ffffff'],
    ['#fff', '#111827'],
  ])('%s → %s', (bg, fg) => {
    expect(readableForeground(bg)).toBe(fg);
  });

  it('ungültige Farbe → dunkle Schrift', () => {
    expect(readableForeground('rot')).toBe('#111827');
  });

  it('übernimmt Server-fg nur bei ausreichendem Kontrast', () => {
    expect(safeForeground('#dc2626', '#fff')).toBe('#ffffff');
    expect(safeForeground('#facc15', '#ffffff')).toBe('#111827'); // Weiß auf Gelb unlesbar
    expect(safeForeground('#16a34a', null)).toBe(readableForeground('#16a34a'));
  });

  it('Kontrastverhältnis nach WCAG', () => {
    expect(ratio('#000000', '#ffffff')).toBeCloseTo(21, 0);
    expect(ratio('#777777', '#777777')).toBeCloseTo(1, 5);
  });
});

describe('Markenfarben in Hell und Dunkel', () => {
  it('ensureContrast hellt dunkle Farben auf dunklem Grund auf', () => {
    const bg = THEME_SURFACES.dark.card;
    const navy = '#1e3a8a';
    expect(ratio(navy, bg)).toBeLessThan(4.5);
    const fixed = ensureContrast(navy, bg, 4.5);
    expect(ratio(fixed, bg)).toBeGreaterThanOrEqual(4.5);
  });

  it('ensureContrast lässt ausreichende Farben unverändert', () => {
    expect(ensureContrast('#111827', '#ffffff')).toBe('#111827');
  });

  it.each(['#e2001a', '#2563eb', '#facc15', '#000000', '#ffffff', '#0f172a', '#22c55e'])('Marke %s erfüllt die Kontrastziele', (brand) => {
    for (const theme of ['light', 'dark'] as const) {
      const t = brandTokens(brand, '#2563eb', theme);
      const surface = THEME_SURFACES[theme].card;
      expect(ratio(t.primary, surface)).toBeGreaterThanOrEqual(3);
      expect(ratio(t.primaryText, surface)).toBeGreaterThanOrEqual(4.5);
      expect(ratio(t.primaryFg, t.primary)).toBeGreaterThanOrEqual(4.5);
    }
  });

  it('ungültige Markenfarbe → Standardfarbe', () => {
    expect(brandTokens('nix', 'auch nix', 'light').primary).toBe('#e2001a');
  });
});

describe('HTML-Escaping', () => {
  it('escaped Interpolationen, auch Anführungszeichen', () => {
    const evil = '<img src=x onerror="alert(1)">\'';
    expect(html`<p title="${evil}">${evil}</p>`.value).toBe(
      '<p title="&lt;img src=x onerror=&quot;alert(1)&quot;&gt;&#39;">&lt;img src=x onerror=&quot;alert(1)&quot;&gt;&#39;</p>',
    );
    expect(esc(null)).toBe('');
  });

  it('verschachtelte Templates werden nicht doppelt escaped', () => {
    expect(html`<ul>${['a&b', 'c'].map((x) => html`<li>${x}</li>`)}</ul>`.value).toBe('<ul><li>a&amp;b</li><li>c</li></ul>');
  });

  it('cssColor lässt nur Hex-Farben durch', () => {
    expect(cssColor('#dc2626')).toBe('#dc2626');
    expect(cssColor('red;background:url(x)')).toBe('#6b7280');
  });
});

describe('Server-Adresse', () => {
  it.each([
    ['planquadro.firma.de', 'https://planquadro.firma.de/api/v1/'],
    ['https://planquadro.firma.de/', 'https://planquadro.firma.de/api/v1/'],
    ['https://planquadro.firma.de/api/v1', 'https://planquadro.firma.de/api/v1/'],
    ['https://firma.de/planquadro', 'https://firma.de/planquadro/api/v1/'],
    ['  HTTPS://Firma.DE:8443/x/?a=1#b ', 'https://firma.de:8443/x/api/v1/'],
    ['http://localhost:8080', 'http://localhost:8080/api/v1/'],
    ['http://10.0.2.2', 'http://10.0.2.2/api/v1/'],
  ])('%s → %s', (input, out) => {
    expect(normalizeServerUrl(input)).toBe(out);
  });

  it.each(['', 'http://planquadro.firma.de', 'ftp://x', 'https://user:pw@x.de', 'https://'])('lehnt „%s“ ab', (input) => {
    expect(() => normalizeServerUrl(input)).toThrow(ServerUrlError);
  });

  it('Mandanten-Kürzel', () => {
    expect(normalizeTenantSlug('  ACME ')).toBe('acme');
    expect(normalizeTenantSlug('')).toBeNull();
    expect(() => normalizeTenantSlug('a b')).toThrow(ServerUrlError);
  });
});

describe('PKCE / OIDC', () => {
  it('S256-Challenge nach RFC 7636 (Anhang B)', async () => {
    expect(await codeChallengeS256('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk')).toBe('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM');
  });

  it('baut die Autorisierungs-URL und liest den Rücksprung', async () => {
    const { url, session } = await buildAuthorizeUrl('https://idp.example/authorize?client_id=app&scope=openid', 'planquadro://auth');
    const u = new URL(url);
    expect(u.searchParams.get('client_id')).toBe('app');
    expect(u.searchParams.get('scope')).toBe('openid');
    expect(u.searchParams.get('response_type')).toBe('code');
    expect(u.searchParams.get('redirect_uri')).toBe('planquadro://auth');
    expect(u.searchParams.get('code_challenge_method')).toBe('S256');
    expect(u.searchParams.get('code_challenge')).toBe(await codeChallengeS256(session.verifier));
    expect(u.searchParams.get('state')).toBe(session.state);
    expect(u.searchParams.get('nonce')).toBe(session.nonce);
    expect(session.nonce.length).toBeGreaterThan(15);
    expect(parseAuthRedirect(`planquadro://auth?code=abc&state=${session.state}`)).toEqual({ code: 'abc', state: session.state, error: null });
    expect(parseAuthRedirect('planquadro://auth?error=access_denied&state=x').error).toBe('access_denied');
  });
});
