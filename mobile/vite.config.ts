import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import type { Plugin } from 'vite';
import { defineConfig } from 'vitest/config';

const pkg = JSON.parse(readFileSync(new URL('./package.json', import.meta.url), 'utf8')) as { version: string };

function listFiles(dir: string): string[] {
  const out: string[] = [];
  for (const name of readdirSync(dir)) {
    const p = join(dir, name);
    if (statSync(p).isDirectory()) out.push(...listFiles(p));
    else out.push(p);
  }
  return out;
}

/**
 * Kleiner, selbst geschriebener Service Worker statt Workbox: erzeugt beim Build
 * `sw.js` mit der Liste aller gebündelten Dateien (App-Shell-Precache).
 * API-Antworten werden bewusst NICHT vom Service Worker gecacht – die App legt
 * sie verschlüsselt in IndexedDB ab.
 */
function serviceWorkerPlugin(): Plugin {
  return {
    name: 'planquadro-service-worker',
    apply: 'build',
    generateBundle(_options, bundle) {
      const publicDir = new URL('./public', import.meta.url).pathname;
      const publicFiles = listFiles(publicDir).map((f) => relative(publicDir, f).split('\\').join('/'));
      const files = ['./', ...Object.keys(bundle), ...publicFiles]
        .filter((f) => f !== 'sw.js' && !f.endsWith('.map'))
        .map((f) => (f === './' ? f : `./${f}`));
      const template = readFileSync(new URL('./src/sw/sw.js', import.meta.url), 'utf8');
      const hash = Object.keys(bundle).sort().join('|');
      let h = 0;
      for (let i = 0; i < hash.length; i++) h = (h * 31 + hash.charCodeAt(i)) | 0;
      const source = template
        .replace('__PRECACHE_FILES__', JSON.stringify(files))
        .replace('__CACHE_VERSION__', JSON.stringify(`${pkg.version}-${(h >>> 0).toString(36)}`));
      this.emitFile({ type: 'asset', fileName: 'sw.js', source });
    },
  };
}

// Schutz: Der Screenshot-Demo-Modus (src/platform/screenshot.ts) darf nie in einen Release-Build.
if (process.env.VITE_SCREENSHOT_DEMO === '1' && process.env.PQ_RELEASE_BUILD === '1') {
  throw new Error('VITE_SCREENSHOT_DEMO=1 ist in Release-Builds (PQ_RELEASE_BUILD=1) nicht erlaubt.');
}

export default defineConfig({
  base: './',
  define: {
    __APP_VERSION__: JSON.stringify(pkg.version),
  },
  build: {
    target: 'es2022',
    sourcemap: false,
    outDir: 'dist',
    emptyOutDir: true,
  },
  plugins: [serviceWorkerPlugin()],
  test: {
    environment: 'node',
    include: ['tests/**/*.test.ts'],
    setupFiles: ['tests/setup.ts'],
  },
});
