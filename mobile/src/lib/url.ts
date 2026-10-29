/** Hosts, für die (nur in der Entwicklung) Klartext-HTTP erlaubt ist. */
export const CLEARTEXT_DEV_HOSTS = ['localhost', '127.0.0.1', '10.0.2.2'];

export class ServerUrlError extends Error {}

/**
 * Normalisiert eine eingegebene Server-Adresse auf die API-Basis `…/api/v1/`.
 * - ohne Schema → https://
 * - http:// nur für localhost/127.0.0.1/10.0.2.2
 * - vorhandenes `/api/v1` wird nicht verdoppelt
 */
export function normalizeServerUrl(input: string): string {
  let s = input.trim();
  if (!s) throw new ServerUrlError('Bitte gib die Adresse deines OpsPilot-Servers ein.');
  if (!/^[a-z][a-z0-9+.-]*:\/\//i.test(s)) s = `https://${s}`;
  let url: URL;
  try {
    url = new URL(s);
  } catch {
    throw new ServerUrlError('Die Server-Adresse ist ungültig.');
  }
  if (url.protocol !== 'https:' && url.protocol !== 'http:') {
    throw new ServerUrlError('Nur https://-Adressen werden unterstützt.');
  }
  if (url.protocol === 'http:' && !CLEARTEXT_DEV_HOSTS.includes(url.hostname)) {
    throw new ServerUrlError('Aus Sicherheitsgründen ist nur https:// erlaubt (http:// nur für localhost in der Entwicklung).');
  }
  if (url.username || url.password) throw new ServerUrlError('Die Adresse darf keine Zugangsdaten enthalten.');
  url.search = '';
  url.hash = '';
  let path = url.pathname.replace(/\/+$/, '');
  path = path.replace(/\/api\/v1$/i, '');
  url.pathname = `${path}/api/v1/`;
  return url.toString();
}

/** Anzeigeform der Server-Adresse (ohne /api/v1/). */
export function displayServerUrl(apiBase: string): string {
  return apiBase.replace(/\/api\/v1\/?$/, '');
}

/** Gültiger Mandanten-Slug (oder leer). */
export function normalizeTenantSlug(input: string): string | null {
  const s = input.trim().toLowerCase();
  if (!s) return null;
  if (!/^[a-z0-9][a-z0-9-]{0,62}$/.test(s)) {
    throw new ServerUrlError('Der Mandanten-Kürzel darf nur Kleinbuchstaben, Ziffern und Bindestriche enthalten.');
  }
  return s;
}
