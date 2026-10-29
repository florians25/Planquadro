/**
 * Screenshot-/Demo-Modus für native CI-Builds.
 *
 * Nur aktiv, wenn beim Web-Build `VITE_SCREENSHOT_DEMO=1` gesetzt ist – ausschließlich im
 * Workflow `.github/workflows/apps.yml` für die Screenshot-APK. Release-Builds setzen die
 * Variable nie (vite.config.ts bricht ab, wenn zusätzlich `PQ_RELEASE_BUILD=1` gesetzt ist).
 *
 * Wirkung: Demo-Modus mit Fixtures (src/api/mock.ts) ohne Backend, automatische
 * Demo-Anmeldung, keine Berechtigungsdialoge (Push/Erinnerungen), Deep Links dürfen das
 * Farbschema setzen (`planquadro://screen/team?theme=dark`).
 */
export const SCREENSHOT_DEMO: boolean = import.meta.env.VITE_SCREENSHOT_DEMO === '1';
