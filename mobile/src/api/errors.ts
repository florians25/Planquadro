import type { ApiErrorCode } from './types';

/** Zusätzliche, nur clientseitige Fehlercodes. */
export type ClientErrorCode =
  | 'network_error' // keine Verbindung / DNS / TLS
  | 'timeout'
  | 'server_error' // 5xx ohne Vertragsformat oder unlesbare Antwort
  | 'session_expired' // Refresh fehlgeschlagen → neu anmelden
  | 'not_configured'
  | 'offline_write'; // Schreibzugriff ohne Verbindung

export type ErrorCode = ApiErrorCode | ClientErrorCode;

const DEFAULT_MESSAGES: Record<ErrorCode, string> = {
  invalid_request: 'Die Anfrage war ungültig.',
  invalid_credentials: 'Benutzername oder Passwort ist falsch.',
  invalid_token: 'Deine Anmeldung ist nicht mehr gültig. Bitte melde dich erneut an.',
  token_expired: 'Deine Anmeldung ist abgelaufen.',
  forbidden: 'Dafür fehlt dir die Berechtigung.',
  password_change_required:
    'Du musst dein Passwort zuerst in der Weboberfläche von Planquadro ändern. Danach kannst du dich hier anmelden.',
  module_disabled: 'Diese Funktion ist für deinen Mandanten nicht aktiviert.',
  account_locked: 'Dein Konto ist gesperrt. Bitte wende dich an deine Planung oder Administration.',
  not_found: 'Der Eintrag wurde nicht gefunden.',
  conflict: 'Der Eintrag wurde zwischenzeitlich geändert. Bitte lade neu.',
  rate_limited: 'Zu viele Anfragen. Bitte warte kurz.',
  maintenance: 'Planquadro wird gerade gewartet. Bitte versuche es später erneut.',
  network_error: 'Keine Verbindung zum Server.',
  timeout: 'Der Server antwortet nicht rechtzeitig.',
  server_error: 'Der Server hat einen Fehler gemeldet.',
  session_expired: 'Deine Sitzung ist abgelaufen. Bitte melde dich erneut an.',
  not_configured: 'Es ist noch kein Server eingerichtet.',
  offline_write: 'Ohne Verbindung können keine Änderungen gesendet werden. Bitte versuche es online erneut.',
};

export class ApiError extends Error {
  readonly status: number;
  readonly code: ErrorCode | string;
  readonly retryAfter: number | null;
  /** Meldung des Servers (bereits deutsch), falls vorhanden. */
  readonly serverMessage: string | null;

  constructor(status: number, code: ErrorCode | string, serverMessage: string | null = null, retryAfter: number | null = null) {
    super(serverMessage || defaultMessage(code));
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.serverMessage = serverMessage;
    this.retryAfter = retryAfter;
  }

  /** Fehler, bei denen ein Lesezugriff aus dem Offline-Cache bedient werden darf. */
  get isConnectivity(): boolean {
    return this.code === 'network_error' || this.code === 'timeout';
  }

  /** Fehler, die eine erneute Anmeldung erfordern. */
  get requiresLogin(): boolean {
    return this.code === 'invalid_token' || this.code === 'session_expired';
  }

  /** Für die Anzeige: Servertext bevorzugen, sonst deutscher Standardtext. */
  get userMessage(): string {
    if (this.code === 'rate_limited' && this.retryAfter) {
      return `Zu viele Anfragen. Bitte warte ${this.retryAfter} Sekunden.`;
    }
    // Für diese Codes ist unser Text handlungsorientierter als ein generischer Servertext.
    if (this.code === 'password_change_required') return defaultMessage(this.code);
    return this.serverMessage || defaultMessage(this.code);
  }
}

export function defaultMessage(code: string): string {
  return (DEFAULT_MESSAGES as Record<string, string>)[code] ?? 'Unbekannter Fehler.';
}

function parseRetryAfter(value: string | null): number | null {
  if (!value) return null;
  const n = Number(value);
  if (Number.isFinite(n) && n >= 0) return Math.round(n);
  const date = Date.parse(value);
  if (!Number.isNaN(date)) return Math.max(0, Math.round((date - Date.now()) / 1000));
  return null;
}

/** Wandelt eine Nicht-2xx-Antwort in einen ApiError (Vertragsformat `{error:{code,message}}`). */
export async function errorFromResponse(res: Response): Promise<ApiError> {
  let code: string | null = null;
  let message: string | null = null;
  try {
    const text = await res.text();
    if (text) {
      const body = JSON.parse(text) as unknown;
      if (body && typeof body === 'object' && 'error' in body) {
        const err = (body as { error: unknown }).error;
        if (err && typeof err === 'object') {
          const c = (err as { code?: unknown }).code;
          const m = (err as { message?: unknown }).message;
          if (typeof c === 'string') code = c;
          if (typeof m === 'string' && m.trim() !== '') message = m;
        }
      }
    }
  } catch {
    /* kein JSON – Fallback unten */
  }
  if (!code) code = fallbackCode(res.status);
  return new ApiError(res.status, code, message, parseRetryAfter(res.headers.get('Retry-After')));
}

function fallbackCode(status: number): ErrorCode {
  switch (status) {
    case 400: return 'invalid_request';
    case 401: return 'invalid_token';
    case 403: return 'forbidden';
    case 404: return 'not_found';
    case 409: return 'conflict';
    case 429: return 'rate_limited';
    case 503: return 'maintenance';
    default: return 'server_error';
  }
}

export function toApiError(e: unknown): ApiError {
  if (e instanceof ApiError) return e;
  if (e instanceof DOMException && (e.name === 'AbortError' || e.name === 'TimeoutError')) {
    return new ApiError(0, 'timeout');
  }
  // fetch() wirft TypeError bei Netzwerkfehlern
  return new ApiError(0, 'network_error');
}
