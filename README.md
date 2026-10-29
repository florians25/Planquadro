# OpsPilot

**Universal Field & Onsite Service Management Platform** – Einsatz-, Team- und
Abwesenheitsplanung für Service-Organisationen. OpsPilot ist aus der bewährten Anwendung
*Einsatzplanung* (v6.9.0) entstanden und erweitert sie, ohne bestehende Abläufe, Daten oder
Rechte zu verändern.

> Status: Entwicklungsstand, **noch nicht für den Produktivbetrieb freigegeben**.

## Funktionen

- **Planung:** Dashboard, Mein Plan, Gesamtplan, Monats- und Jahresplanung, Einsatzcodes,
  Dauereinsätze, Planungsassistent, Entwürfe mit Änderungsmarkierung und Veröffentlichung
- **Team & Abwesenheiten:** Mitarbeiter, Teams (Vorgesetzte), Rollen und Rechte, Urlaub,
  Krankheit, Gleitzeit, Feiertage/Ferien, Vertretungen, Änderungsanträge
- **Mehrbenutzerbetrieb:** zellgenaue Versionsprüfung mit Konfliktdialog, Sperren beim
  Speichern/Veröffentlichen, Änderungsprotokoll je Zelle, Live-Aktualisierung
- **Plattform:** Mandantenfähigkeit (eine Datenbank je Mandant), TenantConfig mit Branding,
  Modulen, Navigation, Dashboard-Widgets und Planungsregeln, Stammdaten (Kunden, Standorte,
  Qualifikationen), optional SSO per OpenID Connect, zentrale Plattform-Administration
- **Apps:** iOS/Android/PWA (`mobile/`) über die API v1 – persönlicher Plan, Team,
  Abwesenheiten und Anträge, Einsatzdetails mit Kundeninfos, Push, Offline-Lesemodus,
  Biometrie, Dark Mode, Mandanten-Branding
- **Betrieb:** Docker-Image und Compose-Stack (Self-Hosting und SaaS), Healthchecks,
  verschlüsselte Backups mit externen Zielen, Aufbewahrung, automatischem Restore-Test
  und Notfallhandbuch
- **Oberfläche:** OpsPilot-Designsystem, Dark Mode, Tastaturbedienung der Planungsmatrix,
  Barrierefreiheit (WCAG 2.2 AA), deutlich schnellere große Pläne

## Dokumentation

| Dokument | Inhalt |
|---|---|
| [docs/OPSPILOT_ROADMAP.md](docs/OPSPILOT_ROADMAP.md) | Phasen, Befunde, Entscheidungen |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Ist- und Zielarchitektur, Mandanten, Mobile-Entscheidung |
| [docs/PERMISSIONS.md](docs/PERMISSIONS.md) | Berechtigungsmodell |
| [docs/MIGRATION_PLAN.md](docs/MIGRATION_PLAN.md) | Update bestehender Installationen |
| [docs/API.md](docs/API.md) | REST-API v1 für Apps |
| [docs/DESIGN_SYSTEM.md](docs/DESIGN_SYSTEM.md) | Designsystem, Dark Mode, Barrierefreiheit |
| [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) | Entwicklung, Tests, CI, Branches |
| [mobile/README.md](mobile/README.md) | Mobile-Apps bauen und veröffentlichen |
| [docs/INSTALLATION.md](docs/INSTALLATION.md) | Installation: Docker Compose, Reverse Proxy/HTTPS, Apache/PHP, Shared Hosting |
| [docs/CONFIGURATION.md](docs/CONFIGURATION.md) | alle Umgebungsvariablen |
| [docs/UPDATE.md](docs/UPDATE.md) | sicheres Update und Rollback |
| [docs/BACKUP.md](docs/BACKUP.md) | Backup, Verschlüsselung, Ziele, Aufbewahrung, Restore |
| [docs/DISASTER_RECOVERY.md](docs/DISASTER_RECOVERY.md) | Notfallhandbuch mit RPO/RTO |
| [CHANGELOG.md](CHANGELOG.md) | Änderungen je Version |

## Schnellstart (Entwicklung)

```bash
composer install
export OPSPILOT_DB_HOST=127.0.0.1 OPSPILOT_DB_NAME=opspilot OPSPILOT_DB_USER=opspilot OPSPILOT_DB_PASS=…
php bin/migrate.php up
php public/install.php
php -S 127.0.0.1:8080 -t public tests/router.php
```

## Lizenz

Copyright © 2026 Florian-Fynn Scheibel. Alle Rechte vorbehalten (siehe Roadmap, E-3).
