# OpsPilot

**Universal Field & Onsite Service Management Platform** – Einsatz-, Team- und
Abwesenheitsplanung für Service-Organisationen. OpsPilot entsteht aus der bewährten
Anwendung *Einsatzplanung* (v6.9.0) und wird schrittweise mandantenfähig, mobil und für
Self-Hosting wie SaaS betreibbar gemacht.

> Status: **Phase 2 – Stabilisierung.** Noch nicht für den Produktivbetrieb freigegeben.

## Dokumentation

| Dokument | Inhalt |
|---|---|
| [docs/OPSPILOT_ROADMAP.md](docs/OPSPILOT_ROADMAP.md) | Phasen, Befunde, offene Entscheidungen |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Ist-Architektur und Zielarchitektur |
| [docs/PERMISSIONS.md](docs/PERMISSIONS.md) | bestehendes Berechtigungsmodell |
| [docs/MIGRATION_PLAN.md](docs/MIGRATION_PLAN.md) | Migration bestehender Installationen |
| [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) | lokale Entwicklung, Tests, CI |
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
php -S 127.0.0.1:8080 -t public tests/router.php
```

## Lizenz

Copyright © 2026 Florian-Fynn Scheibel. Alle Rechte vorbehalten (siehe Roadmap, E-3).
