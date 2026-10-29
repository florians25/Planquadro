# Planquadro API v1

Basis-URL: `https://<server>/api/v1/` · Format: JSON (UTF-8) · Zeitzone der Datumsangaben:
die des Mandanten (`Y-m-d`, ohne Uhrzeit), Zeitstempel als ISO 8601 mit Offset.

Die API ist die einzige Schnittstelle der Mobile-Apps und der PWA. Sie nutzt dieselben
Rechte-, Team-Scope- und Vertraulichkeitsregeln wie die Weboberfläche
(siehe `PERMISSIONS.md`). Mitarbeiter sehen ausschließlich **veröffentlichte** Planstände.

## Mandant

- **Self-Hosting:** genau ein Mandant, keine Angabe nötig.
- **SaaS:** der Mandant ergibt sich aus dem Hostnamen (`acme.planquadro.example`). Läuft die
  App gegen einen zentralen Host, wird der Mandant per Header `X-PQ-Tenant: <slug>`
  gewählt. Tokens sind an den Mandanten gebunden; ein Token eines anderen Mandanten wird
  mit `401 invalid_token` abgewiesen.

## Authentifizierung

Bearer-Tokens, keine Cookies, daher kein CSRF-Token.

| Token | Lebensdauer | Speicherort in der App |
|---|---|---|
| Access-Token | 15 Minuten | Arbeitsspeicher |
| Refresh-Token | 30 Tage, **rotierend** (jede Verwendung liefert ein neues; ein bereits verwendetes Refresh-Token sperrt die ganze Token-Familie) | sicherer Speicher (Keychain/Keystore) |

```
Authorization: Bearer <access_token>
```

### Fehlerformat (alle Endpunkte)

```json
{ "error": { "code": "invalid_token", "message": "Lesbarer Text auf Deutsch" } }
```

| HTTP | code | Bedeutung |
|---|---|---|
| 400 | `invalid_request` | Parameter fehlen/ungültig |
| 401 | `invalid_credentials`, `invalid_token`, `token_expired` | Login fehlgeschlagen / Token ungültig / abgelaufen → Refresh versuchen |
| 403 | `forbidden`, `password_change_required`, `module_disabled`, `account_locked` | |
| 404 | `not_found` | |
| 409 | `conflict` | |
| 429 | `rate_limited` | Header `Retry-After` |
| 503 | `maintenance` | Wartungsmodus |

## Öffentliche Endpunkte

### `GET config`
Branding für den Login-Bildschirm.
```json
{ "api_version": 1, "tenant": { "name": "Planquadro", "slug": "default",
  "branding": { "primary": "#2557d6", "accent": "#ff7a1a", "logo_url": "https://…/api/v1/branding/logo" } },
  "auth": { "password": true, "oidc": { "enabled": false, "label": "Mit Firmenkonto anmelden", "authorize_url": null } },
  "modules": { "absences": true, "approvals": false, "ical": true } }
```

### `GET health`
`{ "status": "ok" }`

### `POST auth/login`
```json
{ "username": "mmuster", "password": "…", "device_name": "iPhone von M." }
```
→ `200`
```json
{ "access_token": "…", "token_type": "Bearer", "expires_in": 900,
  "refresh_token": "…", "refresh_expires_in": 2592000,
  "user": { …wie GET me… } }
```
Fehler: `401 invalid_credentials`, `403 account_locked`, `403 password_change_required`
(Passwort muss zuerst im Web geändert werden).

Ist nur die Anmeldung per Firmenkonto erlaubt, antwortet der Endpunkt für alle
Nicht-Administratoren einheitlich mit `403 forbidden` – unabhängig davon, ob das
Passwort stimmte.

### `POST auth/refresh`
`{ "refresh_token": "…" }` → wie Login (neues Paar). Die Familie behält ihr
ursprüngliches Ablaufdatum (absolute Laufzeit 30 Tage ab Login, keine Verlängerung
durch Rotation). Fehler: `401 invalid_token`, `401 token_expired`,
`401 password_change_required`. Ein Passwortwechsel oder -reset widerruft alle
App-Sitzungen des Benutzers.

### `POST auth/oidc/token` (nur wenn OIDC aktiv)
`{ "code": "…", "code_verifier": "…", "nonce": "…", "redirect_uri": "planquadro://auth" }` → wie Login.
`code_verifier` (PKCE, mind. 43 Zeichen) und `nonce` (aus der Autorisierungs-URL) sind Pflicht.
Administratorkonten werden per SSO nur angemeldet, wenn die Organisation das ausdrücklich erlaubt.

## Geschützte Endpunkte (Bearer)

### `POST auth/logout`
`{ "refresh_token": "…" }` (optional) → `204`. Sperrt die Token-Familie.

### `GET me`
```json
{ "user": { "id": 12, "username": "mmuster", "first_name": "Max", "last_name": "Muster",
            "role": "mitarbeiter", "role_label": "Techniker" },
  "permissions": { "plan_view": true, "plan_edit": false, "approve_requests": false, "view_all_employees": true },
  "modules": { "absences": true, "approvals": true, "assistant": false, "ical": true },
  "tenant": { "name": "…", "slug": "…", "branding": { "primary": "#…", "accent": "#…", "logo_url": "…" } },
  "server_time": "2026-10-29T18:00:00+01:00" }
```

### `GET plan/my?from=2026-11-01&to=2026-11-30`
Eigener veröffentlichter Plan, maximal 93 Tage je Abfrage.
```json
{ "from": "2026-11-01", "to": "2026-11-30", "published_at": "2026-10-29T17:56:31+01:00",
  "days": [
    { "date": "2026-11-02", "weekday": 1,
      "holiday": null,
      "entry": { "kind": "assignment", "area_id": 3, "code": "K1", "label": "Kunde Nord",
                 "color": "#dc2626", "fg": "#ffffff", "remarks": "Rollout Phase 2" } },
    { "date": "2026-11-03", "weekday": 2, "holiday": null,
      "entry": { "kind": "absence", "type": "urlaub_genehmigt", "code": "U", "label": "Urlaub (genehmigt)",
                 "color": "#16a34a", "fg": "#ffffff", "remarks": null } },
    { "date": "2026-11-01", "weekday": 7, "holiday": { "name": "Allerheiligen", "type": "feiertag" }, "entry": null }
  ] }
```
`weekday`: 1 = Montag … 7 = Sonntag. `entry` ist `null` für freie Tage.

### `GET plan/team?year=2026&month=11`
Teamübersicht gemäß Rechten (`plan_view`; sichtbare Mitarbeiter wie im Gesamtplan).
```json
{ "year": 2026, "month": 11, "days": ["2026-11-01", "…"],
  "holidays": { "2026-11-01": { "name": "Allerheiligen", "type": "feiertag" } },
  "users": [ { "id": 12, "name": "Muster, Max", "role_label": "Techniker", "is_me": true } ],
  "cells": { "12_2026-11-02": { "kind": "assignment", "code": "K1", "color": "#dc2626", "fg": "#ffffff" },
             "14_2026-11-02": { "kind": "absence", "code": "A", "color": "#ef4444", "fg": "#ffffff", "confidential": true } } }
```
Vertrauliche Abwesenheiten anderer erscheinen nur als `A` mit `"confidential": true`.

### `GET assignments/{date}?user_id=12`
Einsatzdetails für einen Tag (eigene, oder fremde im Scope).
```json
{ "date": "2026-11-02", "user_id": 12,
  "entry": { "kind": "assignment", "code": "K1", "label": "Kunde Nord", "color": "#dc2626", "fg": "#fff", "remarks": "…" },
  "area": { "id": 3, "code": "K1", "name": "Kunde Nord", "type": "kunde" },
  "customer": { "name": "Kunde Nord GmbH", "address": "Musterstr. 1, 50667 Köln", "contact": "Frau Beispiel",
                "phone": "+49 221 000000", "email": null, "notes": "Zugang über Tor 3" },
  "location": { "name": "Köln Nord", "address": "…", "lat": null, "lng": null },
  "colleagues": [ { "id": 14, "name": "Beispiel, Erika" } ] }
```
`customer`/`location` sind `null`, wenn dem Einsatzcode nichts zugeordnet ist.

### `GET absences/my?year=2026`
```json
{ "year": 2026,
  "vacation": { "entitlement": 30.0, "used": 12.5, "planned": 3.0, "left": 14.5 },
  "sick_days": 4.0,
  "items": [ { "start": "2026-07-20", "end": "2026-07-31", "days": 10, "type": "urlaub_genehmigt",
               "label": "Urlaub (genehmigt)", "published": true } ],
  "requests": [ { "id": 7, "date": "2026-12-23", "type": "urlaub_ganz", "status": "pending", "created_at": "…" } ] }
```

### `POST absences/requests`
Urlaubs-/Gleitzeitantrag (nur wenn Modul `approvals` aktiv). Je Werktag ein Antrag.
```json
{ "from": "2026-12-21", "to": "2026-12-23", "type": "urlaub_ganz", "remarks": "Weihnachten" }
```
`type` ∈ `urlaub_ganz`, `urlaub_halb`, `gleitzeit`. → `201 { "created": 3, "skipped": 0 }`

### `GET holidays?year=2026`
`{ "items": [ { "date": "2026-01-01", "name": "Neujahr", "type": "feiertag" } ] }`

### `GET notifications?limit=30` · `POST notifications/{id}/read` · `POST notifications/read-all`
```json
{ "unread": 2, "items": [ { "id": 5, "title": "Plan veröffentlicht", "message": "…", "link": "mein_plan",
  "is_read": false, "created_at": "…" } ] }
```

### `GET sync?since=<cursor>`
Für den Offline-Lesemodus: welche eigenen Tage sich seit dem Cursor geändert haben
(nur veröffentlichte Änderungen). Ohne `since` liefert der Server nur den aktuellen Cursor.
```json
{ "cursor": "1842", "changed_dates": ["2026-11-02", "2026-11-03"], "full_resync": false }
```
`full_resync: true` → Cache verwerfen und neu laden.

### `POST events/ticket` · `GET events` (Live-Aktualisierung, Server-Sent Events)
Im Vordergrund meldet der Server Änderungen sofort per SSE. `EventSource` kann keinen
`Authorization`-Header setzen, und das Access-Token darf nie in eine URL. Deshalb:

1. `POST events/ticket` (Bearer) →
   ```json
   { "ticket": "pqe.…", "expires_in": 30, "url": "events?ticket=pqe.…" }
   ```
   Das Ticket gilt **einmal**, 30 Sekunden, nur für diesen Benutzer, diese Token-Familie
   (Abmelden widerruft es) und diesen Mandanten. Auf dem zentralen API-Host enthält `url`
   zusätzlich `tenant=<slug>`.
2. `new EventSource(<API-Basis> + url [+ "&last_event_id=<id>"])`.

`GET events` akzeptiert alternativ den Bearer-Header (Clients mit `fetch`-Streaming).
Fehler vor Beginn des Streams im üblichen JSON-Format (`401 invalid_token` bei
ungültigem, abgelaufenem oder bereits verwendetem Ticket). `204` = Live-Aktualisierung
serverseitig abgeschaltet → nur `sync`.

Antwort `text/event-stream`:
```
retry: 3000

id: 1842
event: ready
data: {"cursor":"1842"}

id: 1845
event: published
data: {"type":"published","published":true,"months":["2026-11"],"cells":[[12,"2026-11-02"]],"self":true,"own":false}

: hb

event: bye
data: {}
```

| Ereignis | Bedeutung |
|---|---|
| `ready` | Verbindung steht; ohne `last_event_id` mit `id` = aktueller Stand |
| `published` | Veröffentlichung (Monat/Jahr) |
| `cells_changed` | sonstige Änderung des veröffentlichten Plans (z. B. Abwesenheitsmodul) |
| `request_changed` | eigener Antrag eingereicht/genehmigt/abgelehnt (`published: true` bei Genehmigung) |
| `reset` | Cursor unbekannt oder Ereignisse aufgeräumt → Cache neu laden (`sync`) |
| `bye` | reguläres Ende nach der begrenzten Laufzeit (Standard 40 s) → neues Ticket, neu verbinden |

Felder: `months` (`YYYY-MM`), `cells` = `[user_id, Datum]` – nur Zellen, die der Benutzer
sehen darf (eigene; andere nur mit `plan_view` im Team-Scope); `null` = ganzer Monat.
`self`: eigene Zellen betroffen, `own`: selbst ausgelöst. Die App erhält nur
**veröffentlichte** Änderungen und eigene Anträge, nie Entwürfe, nie Inhalte oder
Abwesenheitsarten – Details lädt sie über `sync`, `plan/team`, `absences/my`.
Fortsetzen: `last_event_id` (bzw. Header `Last-Event-ID`) = zuletzt empfangene `id`.
Kommentarzeilen (`: hb`) sind Heartbeats; Zeilen nur mit `id:` rücken den Cursor vor.

Die Weboberfläche nutzt denselben Strom unter `/api/events` (Session-Anmeldung, zusätzlich
Entwürfe und Präsenz für Planer); ohne EventSource bleibt sie beim Polling.

### `POST devices` · `DELETE devices/{id}`
Push-Registrierung.
```json
{ "platform": "ios", "provider": "apns", "token": "…", "app_version": "1.0.0" }
```
`provider` ∈ `fcm`, `apns`, `ntfy`. → `201 { "id": 3 }`

## Push-Nachrichten

Bei Veröffentlichung einer Planänderung erhalten betroffene Mitarbeiter eine Nachricht:
```json
{ "title": "Plan geändert", "body": "Dein Einsatz am 02.11. wurde geändert.",
  "data": { "type": "plan_changed", "dates": "2026-11-02,2026-11-03" } }
```
Die App ruft daraufhin `GET sync` auf. Nachrichten enthalten **keine** Abwesenheitsgründe.
