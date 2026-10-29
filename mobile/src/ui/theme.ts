import { brandTokens } from '../lib/color';
import { applyNativeTheme } from '../platform/native';
import type { ThemeMode } from '../store/prefs';

let currentMode: ThemeMode = 'system';
let brand = { primary: '#2557d6', accent: '#ff7a1a' };
const media = typeof window !== 'undefined' && window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

export function effectiveTheme(): 'light' | 'dark' {
  if (currentMode === 'light' || currentMode === 'dark') return currentMode;
  return media?.matches ? 'dark' : 'light';
}

function apply(): void {
  const root = document.documentElement;
  if (currentMode === 'system') root.removeAttribute('data-theme');
  else root.setAttribute('data-theme', currentMode);
  const theme = effectiveTheme();
  const t = brandTokens(brand.primary, brand.accent, theme);
  root.style.setProperty('--p', t.primary);
  root.style.setProperty('--p-fg', t.primaryFg);
  root.style.setProperty('--p-text', t.primaryText);
  root.style.setProperty('--a', t.accent);
  root.style.setProperty('--a-fg', t.accentFg);
  root.style.setProperty('--a-text', t.accentText);
  const meta = document.querySelector('meta[name="theme-color"]');
  if (meta) meta.setAttribute('content', theme === 'dark' ? '#0b1220' : '#0f172a');
  void applyNativeTheme(theme === 'dark');
}

export function setThemeMode(mode: ThemeMode): void {
  currentMode = mode;
  apply();
}

/** Markenfarben des Mandanten (aus GET config / GET me) übernehmen. */
export function setBrand(primary: string | null | undefined, accent: string | null | undefined): void {
  brand = { primary: primary || '#2557d6', accent: accent || '#ff7a1a' };
  apply();
}

media?.addEventListener('change', () => {
  if (currentMode === 'system') apply();
});
