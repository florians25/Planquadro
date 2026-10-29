/** OAuth 2.0 Authorization Code + PKCE (RFC 7636) */

export function base64UrlEncode(bytes: Uint8Array): string {
  let bin = '';
  for (const b of bytes) bin += String.fromCharCode(b);
  return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

export function randomString(byteLength = 32): string {
  const bytes = new Uint8Array(byteLength);
  crypto.getRandomValues(bytes);
  return base64UrlEncode(bytes);
}

export async function codeChallengeS256(verifier: string): Promise<string> {
  const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(verifier));
  return base64UrlEncode(new Uint8Array(digest));
}

export interface PkceSession {
  state: string;
  nonce: string;
  verifier: string;
  redirectUri: string;
  createdAt: number;
}

/**
 * Baut die Autorisierungs-URL. `authorize_url` aus `GET config` kann bereits
 * client_id/scope enthalten; fehlende Standardparameter werden ergänzt.
 */
export async function buildAuthorizeUrl(authorizeUrl: string, redirectUri: string): Promise<{ url: string; session: PkceSession }> {
  const verifier = randomString(48);
  const state = randomString(16);
  const nonce = randomString(16);
  const challenge = await codeChallengeS256(verifier);
  const url = new URL(authorizeUrl);
  const set = (k: string, v: string) => {
    if (!url.searchParams.has(k)) url.searchParams.set(k, v);
  };
  set('response_type', 'code');
  set('scope', 'openid profile');
  url.searchParams.set('redirect_uri', redirectUri);
  url.searchParams.set('code_challenge', challenge);
  url.searchParams.set('code_challenge_method', 'S256');
  url.searchParams.set('state', state);
  url.searchParams.set('nonce', nonce);
  return { url: url.toString(), session: { state, nonce, verifier, redirectUri, createdAt: Date.now() } };
}

/** Liest `code`/`state`/`error` aus einer Redirect-URL (planquadro://auth?… oder https://…?…). */
export function parseAuthRedirect(redirect: string): { code: string | null; state: string | null; error: string | null } {
  let params: URLSearchParams;
  try {
    const u = new URL(redirect);
    params = u.searchParams.toString() ? u.searchParams : new URLSearchParams(u.hash.replace(/^#/, ''));
  } catch {
    params = new URLSearchParams(redirect.split('?')[1] ?? '');
  }
  return { code: params.get('code'), state: params.get('state'), error: params.get('error') };
}
