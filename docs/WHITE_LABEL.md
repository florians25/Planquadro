# Planquadro im eigenen Firmenauftritt (White-Label)

Planquadro lässt sich vollständig im Erscheinungsbild Ihrer Firma betreiben – mit eigenem
Namen, eigenen Farben, eigenem Logo, eigener Adresse und auf Wunsch eigener App. Im
SaaS-Betrieb gilt das je Mandant: jede Kundenfirma hat ihren eigenen Auftritt.

## Was sich anpassen lässt

| Bereich | Möglichkeiten | Wo |
|---|---|---|
| **Name** | Name der Organisation in Titelzeile, Anmeldeseite, Navigation, App, Benachrichtigungen | *Organisation → Branding* |
| **Farben** | Markenfarbe und Akzentfarbe; Kontraste werden automatisch abgesichert (WCAG AA) | *Organisation → Branding* |
| **Logo** | PNG, JPEG oder WebP bis 512 KB – in der Navigation, auf der Anmeldeseite und in der App | *Organisation → Branding* |
| **Herstellerhinweis** | „· mit Planquadro“ unter der Anmeldung ein-/ausblenden | *Organisation → Branding* |
| **Funktionsumfang** | Module ein/aus (Assistent, Anträge, Abwesenheiten, Urlaub, Kalender-Abo, App-Zugang …) | *Organisation → Module* |
| **Navigation** | Reihenfolge und Sichtbarkeit der Menüpunkte | *Organisation → Navigation* |
| **Dashboard** | Welche Kacheln alle sehen | *Organisation → Dashboard-Widgets* |
| **Anmeldung** | Single Sign-on mit Ihrem Firmenkonto (Entra ID, Google Workspace, Keycloak, Authentik …), eigene Beschriftung des Knopfes, Passwort-Login abschaltbar | *Organisation → Anmeldung (OIDC)* |
| **Rollen** | eigene Rollen mit eigenen Namen und Farben | *Rollen & Rechte* |
| **Einsatzbereiche** | Ihre Kunden, Projekte, Kürzel und Farben | *Bereiche*, *Stammdaten* |
| **Adresse** | eigene Domain, z. B. `planung.ihre-firma.de` | Betrieb (siehe unten) |
| **App** | Firmen-Branding automatisch in der Planquadro-App – oder eine eigene App unter Ihrem Namen | siehe unten |

## Schritt für Schritt

1. Als **Administrator** anmelden → **Organisation**.
2. **Branding:** Namen eintragen, Markenfarbe wählen (die Vorschau zeigt den Kontrast der
   Schrift auf Schaltflächen). Ist die Farbe als Textfarbe zu hell, verwendet Planquadro für
   Links und Text automatisch eine dunklere Variante.
3. **Logo hochladen:** am besten ein querformatiges Logo mit transparentem Hintergrund,
   mindestens 400 px breit (wird auf 64 px Höhe dargestellt). Aus Sicherheitsgründen keine SVG
   (SVG kann Skripte enthalten).
4. Optional den Hinweis „mit Planquadro“ ausblenden.
5. **Module, Navigation, Dashboard** auf Ihre Abläufe zuschneiden – ausgeblendete Funktionen
   bleiben erhalten und lassen sich jederzeit wieder einschalten.
6. Abmelden und die Anmeldeseite prüfen.

> Tipp: Mit `php bin/demo.php` auf einer Testinstanz lässt sich das eigene Branding mit
> realistischen (erfundenen) Daten vorführen, bevor es produktiv geht.

## Eigene Adresse (Domain)

- **Self-Hosting:** DNS-Eintrag (A/AAAA oder CNAME) auf Ihren Server, im Compose-Stack
  `PQ_DOMAIN=planung.ihre-firma.de` setzen – Caddy holt das HTTPS-Zertifikat automatisch
  ([INSTALLATION.md](INSTALLATION.md)).
- **SaaS:** Der Betreiber ordnet Ihre Domain Ihrem Mandanten zu
  (`php bin/tenant.php domain-add <mandant> planung.ihre-firma.de`).

## Die App im Firmenauftritt

**Variante A – Planquadro-App (sofort):** Mitarbeitende installieren die Planquadro-App und
geben Ihre Adresse ein. Name, Farben und Logo Ihrer Firma werden nach der Anmeldung
übernommen.

**Variante B – eigene App unter Ihrem Namen:** Für Firmen, die eine App mit eigenem Namen und
Icon in den Stores möchten:
1. `mobile/capacitor.config.ts`: `appId` (z. B. `de.ihre-firma.planung`) und `appName`.
2. Eigene Icons/Splash: Bildmarke als SVG in `public/assets/brand/` ablegen bzw. die Farben in
   `tools/brand/build_logo.py` anpassen, dann `npm run icons` im Ordner `mobile/`.
3. Bauen und mit Ihrem eigenen Apple-/Google-Entwicklerkonto veröffentlichen
   ([mobile/README.md](../mobile/README.md)).

## E-Mail und Benachrichtigungen

Alarm- und Systemmails tragen den Absender aus `PQ_ALERT_FROM` – z. B.
`planung@ihre-firma.de` über Ihren eigenen SMTP-Server ([CONFIGURATION.md](CONFIGURATION.md)).

## Was bewusst gleich bleibt

- Die Bedienung, das Planungsraster und die Sicherheitsregeln sind für alle Firmen gleich –
  so bleiben Updates einfach und Schulungen übertragbar.
- Lizenz- und Urheberhinweise im Quellcode bleiben erhalten.

## Für Agenturen und Partner

Planquadro eignet sich als Grundlage für eigene Angebote an Service-Firmen: eine Instanz pro
Kunde (Self-Hosting) oder ein Mandanten-Betrieb mit eigener Domain je Kunde (SaaS) –
jeweils mit eigenem Branding, ohne Code zu ändern.
