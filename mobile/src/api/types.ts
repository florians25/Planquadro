/**
 * Typen der Vireo API v1 – 1:1 nach docs/API.md (verbindlicher Vertrag).
 * Datumsangaben `Y-m-d` (ohne Uhrzeit, Zeitzone des Mandanten),
 * Zeitstempel ISO 8601 mit Offset.
 */

/** `Y-m-d` */
export type IsoDate = string;
/** ISO 8601 mit Offset */
export type IsoDateTime = string;

export interface ApiErrorBody {
  error: { code: ApiErrorCode | string; message: string };
}

export type ApiErrorCode =
  | 'invalid_request'
  | 'invalid_credentials'
  | 'invalid_token'
  | 'token_expired'
  | 'forbidden'
  | 'password_change_required'
  | 'module_disabled'
  | 'account_locked'
  | 'not_found'
  | 'conflict'
  | 'rate_limited'
  | 'maintenance';

export interface Branding {
  primary: string;
  accent: string;
  logo_url: string | null;
}

export interface TenantInfo {
  name: string;
  slug: string;
  branding: Branding;
}

export interface Modules {
  absences: boolean;
  approvals: boolean;
  ical: boolean;
  assistant?: boolean;
  [key: string]: boolean | undefined;
}

/** `GET config` */
export interface ConfigResponse {
  api_version: number;
  tenant: TenantInfo;
  auth: {
    password: boolean;
    oidc: { enabled: boolean; label: string; authorize_url: string | null };
  };
  modules: Modules;
}

/** `GET health` */
export interface HealthResponse {
  status: 'ok' | string;
}

export interface User {
  id: number;
  username: string;
  first_name: string;
  last_name: string;
  role: string;
  role_label: string;
}

export interface Permissions {
  plan_view: boolean;
  plan_edit: boolean;
  approve_requests: boolean;
  view_all_employees: boolean;
  [key: string]: boolean | undefined;
}

/** `GET me` */
export interface MeResponse {
  user: User;
  permissions: Permissions;
  modules: Modules;
  tenant: TenantInfo;
  server_time: IsoDateTime;
}

/** `POST auth/login` */
export interface LoginRequest {
  username: string;
  password: string;
  device_name: string;
}

/** Antwort von `auth/login`, `auth/refresh`, `auth/oidc/token`. */
export interface TokenResponse {
  access_token: string;
  token_type: 'Bearer';
  expires_in: number;
  refresh_token: string;
  refresh_expires_in: number;
  /** Laut Vertrag „wie GET me“. */
  user: MeResponse;
}

export interface RefreshRequest {
  refresh_token: string;
}

export interface OidcTokenRequest {
  code: string;
  code_verifier: string;
  /** nonce aus der Autorisierungs-URL; der Server prüft sie im ID-Token */
  nonce: string;
  redirect_uri: string;
}

export interface LogoutRequest {
  refresh_token?: string;
}

export interface Holiday {
  name: string;
  type: 'feiertag' | string;
}

export interface AssignmentEntry {
  kind: 'assignment';
  area_id?: number;
  code: string;
  label: string;
  color: string;
  fg: string;
  remarks: string | null;
}

export interface AbsenceEntry {
  kind: 'absence';
  type: string;
  code: string;
  label: string;
  color: string;
  fg: string;
  remarks: string | null;
}

export type PlanEntry = AssignmentEntry | AbsenceEntry;

export interface PlanDay {
  date: IsoDate;
  /** 1 = Montag … 7 = Sonntag */
  weekday: number;
  holiday: Holiday | null;
  entry: PlanEntry | null;
}

/** `GET plan/my?from&to` */
export interface MyPlanResponse {
  from: IsoDate;
  to: IsoDate;
  published_at: IsoDateTime | null;
  days: PlanDay[];
}

export interface TeamUser {
  id: number;
  name: string;
  role_label: string;
  is_me: boolean;
}

export interface TeamCell {
  kind: 'assignment' | 'absence';
  code: string;
  color: string;
  fg: string;
  confidential?: boolean;
}

/** `GET plan/team?year&month` */
export interface TeamPlanResponse {
  year: number;
  month: number;
  days: IsoDate[];
  holidays: Record<IsoDate, Holiday>;
  users: TeamUser[];
  /** Schlüssel: `<user_id>_<Y-m-d>` */
  cells: Record<string, TeamCell>;
}

/** `GET assignments/{date}?user_id` */
export interface AssignmentDetailsResponse {
  date: IsoDate;
  user_id: number;
  entry: PlanEntry | null;
  area: { id: number; code: string; name: string; type: string } | null;
  customer: {
    name: string;
    address: string | null;
    contact: string | null;
    phone: string | null;
    email: string | null;
    notes: string | null;
  } | null;
  location: { name: string; address: string | null; lat: number | null; lng: number | null } | null;
  colleagues: Array<{ id: number; name: string }>;
}

export interface AbsenceItem {
  start: IsoDate;
  end: IsoDate;
  days: number;
  type: string;
  label: string;
  published: boolean;
}

export type RequestType = 'urlaub_ganz' | 'urlaub_halb' | 'gleitzeit';

export interface AbsenceRequestItem {
  id: number;
  date: IsoDate;
  type: RequestType | string;
  status: 'pending' | 'approved' | 'rejected' | string;
  created_at: IsoDateTime;
}

/** `GET absences/my?year` */
export interface MyAbsencesResponse {
  year: number;
  vacation: { entitlement: number; used: number; planned: number; left: number };
  sick_days: number;
  items: AbsenceItem[];
  requests: AbsenceRequestItem[];
}

/** `POST absences/requests` */
export interface AbsenceRequestCreate {
  from: IsoDate;
  to: IsoDate;
  type: RequestType;
  remarks?: string;
}

export interface AbsenceRequestCreated {
  created: number;
  skipped: number;
}

/** `GET holidays?year` */
export interface HolidaysResponse {
  items: Array<{ date: IsoDate; name: string; type: string }>;
}

export interface NotificationItem {
  id: number;
  title: string;
  message: string;
  link: string | null;
  is_read: boolean;
  created_at: IsoDateTime;
}

/** `GET notifications?limit` */
export interface NotificationsResponse {
  unread: number;
  items: NotificationItem[];
}

/** `GET sync?since` */
export interface SyncResponse {
  cursor: string;
  changed_dates: IsoDate[];
  full_resync: boolean;
}

export type DevicePlatform = 'ios' | 'android' | 'web';
export type PushProvider = 'fcm' | 'apns' | 'ntfy';

/** `POST devices` */
export interface DeviceRegistration {
  platform: DevicePlatform;
  provider: PushProvider;
  token: string;
  app_version: string;
}

export interface DeviceRegistered {
  id: number;
}

/** Push-Nutzlast (data-Teil) laut Vertrag. */
export interface PlanChangedPushData {
  type: 'plan_changed';
  /** Kommagetrennte Liste `Y-m-d` */
  dates: string;
}
