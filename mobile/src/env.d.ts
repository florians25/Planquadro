declare const __APP_VERSION__: string;

interface ImportMetaEnv {
  /** "1" nur im CI-Screenshot-Build (siehe src/platform/screenshot.ts) */
  readonly VITE_SCREENSHOT_DEMO?: string;
}
