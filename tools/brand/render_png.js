/**
 * Planquadro – PNG-Varianten aus den SVG-Logos rendern (Chromium über Playwright).
 * Aufruf: node tools/brand/render_png.js
 * Schreibt Favicons/PWA-Icons nach public/assets/brand, die App-Icons und
 * Splash-Screens nach mobile/ (Android-mipmaps, iOS AppIcon, PWA-Icons) und die
 * App-Icons der nativen Apple-App nach apple/ (hell, dunkel, getönt für iOS 18/26).
 * Nur die Apple-Icons: node tools/brand/render_png.js --apple
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '../..');
const brand = path.join(root, 'public/assets/brand');
const svg = (f) => fs.readFileSync(path.join(brand, f), 'utf8');
const res = path.join(root, 'mobile/android/app/src/main/res');

const jobs = [
  // Web
  ['favicon.svg', 32, 'public/assets/brand/favicon-32.png'],
  ['planquadro-app-icon.svg', 180, 'public/assets/brand/apple-touch-icon.png'],
  ['planquadro-app-icon.svg', 192, 'public/assets/brand/icon-192.png'],
  ['planquadro-app-icon.svg', 512, 'public/assets/brand/icon-512.png'],
  // PWA der Mobile-App
  ['planquadro-app-icon.svg', 180, 'mobile/public/icons/icon-180.png'],
  ['planquadro-app-icon.svg', 192, 'mobile/public/icons/icon-192.png'],
  ['planquadro-app-icon.svg', 512, 'mobile/public/icons/icon-512.png'],
  ['planquadro-app-icon-maskable.svg', 512, 'mobile/public/icons/icon-maskable-512.png'],
  // iOS (ein 1024er-Icon, Xcode erzeugt den Rest); iOS rundet selbst → ohne Radius
  ['planquadro-app-icon-maskable.svg', 1024, 'mobile/ios/App/App/Assets.xcassets/AppIcon.appiconset/AppIcon-512@2x.png', { square: true }],
];
// Native Apple-App (apple/): 1024er-Icons für Standard, Dunkel (transparenter Grund,
// iOS legt den dunklen Hintergrund selbst an) und Getönt (Graustufen, iOS färbt ein)
const appleOnly = process.argv.includes('--apple');
const appleIcons = 'apple/App/Assets.xcassets/AppIcon.appiconset';
const appleJobs = [
  ['planquadro-app-icon-maskable.svg', 1024, `${appleIcons}/AppIcon-1024.png`, { square: true }],
  ['planquadro-app-icon-maskable.svg', 1024, `${appleIcons}/AppIcon-1024-dark.png`, { square: true, noBackground: true, transparent: true }],
  ['planquadro-app-icon-maskable.svg', 1024, `${appleIcons}/AppIcon-1024-tinted.png`, { square: true, noBackground: true, tinted: true }],
  // Bildmarke für den Startbildschirm (Launch Screen) der Apple-App
  ['planquadro-mark-white.svg', 240, 'apple/App/Assets.xcassets/LaunchMark.imageset/LaunchMark@2x.png', { transparent: true }],
  ['planquadro-mark-white.svg', 360, 'apple/App/Assets.xcassets/LaunchMark.imageset/LaunchMark@3x.png', { transparent: true }],
];
if (appleOnly) jobs.length = 0;
jobs.push(...appleJobs);
// Android: Launcher-Icons je Dichte, Vordergrund für adaptive Icons (108 dp)
for (const [d, px] of Object.entries(appleOnly ? {} : { mdpi: 48, hdpi: 72, xhdpi: 96, xxhdpi: 144, xxxhdpi: 192 })) {
  jobs.push(['planquadro-app-icon.svg', px, `mobile/android/app/src/main/res/mipmap-${d}/ic_launcher.png`]);
  jobs.push(['planquadro-app-icon.svg', px, `mobile/android/app/src/main/res/mipmap-${d}/ic_launcher_round.png`, { round: true }]);
  jobs.push(['planquadro-mark-white.svg', px * 2.25, `mobile/android/app/src/main/res/mipmap-${d}/ic_launcher_foreground.png`, { pad: 0.3, transparent: true }]);
}

function page(svgText, size, o = {}) {
  let inner = o.square ? svgText.replace(/rx="[\d.]+" fill="#14213D"/, 'fill="#14213D"') : svgText;
  if (o.noBackground) inner = inner.replace(/<rect width="1024" height="1024"[^>]*\/>/, '');
  const filter = o.tinted ? 'filter:grayscale(1) brightness(1.15);' : '';
  const pad = o.pad ? `padding:${size * o.pad}px;box-sizing:border-box;` : '';
  return `<html><body style="margin:0;background:${o.tinted ? '#000000' : 'transparent'}">
    <div style="width:${size}px;height:${size}px;${pad}${o.round ? 'border-radius:50%;overflow:hidden;' : ''}">
    ${inner.replace('<svg ', `<svg style="width:100%;height:100%;display:block;${filter}" `)}</div></body></html>`;
}

function splash(w, h) {
  const logo = svg('planquadro-logo-dark.svg').replace('<svg ', '<svg style="width:46%;max-width:520px;height:auto" ');
  return `<html><body style="margin:0;width:${w}px;height:${h}px;background:#14213D;display:flex;align-items:center;justify-content:center">${logo}</body></html>`;
}

/* Chromium bricht große Screenshots gelegentlich ab → bis zu 3 Versuche */
async function shot(b, viewport, html, file, opts = {}) {
  for (let i = 1; ; i++) {
    const p = await b.newPage(viewport);
    try {
      await p.setContent(html);
      await p.screenshot({ path: file, ...opts });
      return;
    } catch (e) {
      if (i >= 3) throw e;
    } finally {
      await p.close();
    }
  }
}

(async () => {
  const b = await chromium.launch();
  for (const [file, size, out, o = {}] of jobs) {
    fs.mkdirSync(path.dirname(path.join(root, out)), { recursive: true });
    await shot(b, { viewport: { width: Math.round(size), height: Math.round(size) } }, page(svg(file), Math.round(size), o),
      path.join(root, out), { omitBackground: true });
    console.log('PNG:', out);
  }
  if (appleOnly) {
    await b.close();
    return;
  }
  // Splash: iOS 2732², Android je Ausrichtung und Dichte
  const splashes = [['mobile/android/app/src/main/res/drawable/splash.png', 480, 320]];
  for (const [d, [w, h]] of Object.entries({ mdpi: [320, 480], hdpi: [480, 800], xhdpi: [720, 1280], xxhdpi: [960, 1600], xxxhdpi: [1280, 1920] })) {
    splashes.push([`mobile/android/app/src/main/res/drawable-port-${d}/splash.png`, w, h]);
    splashes.push([`mobile/android/app/src/main/res/drawable-land-${d}/splash.png`, h, w]);
  }
  for (const [out, w, h] of splashes) {
    await shot(b, { viewport: { width: w, height: h } }, splash(w, h), path.join(root, out));
    console.log('Splash:', out);
  }
  await b.close();
  /* iOS-Splash 2732² zuletzt in eigener Browser-Instanz (große Screenshots belasten Chromium) */
  const b2 = await chromium.launch();
  await shot(b2, { viewport: { width: 1366, height: 1366 }, deviceScaleFactor: 2 }, splash(1366, 1366),
    path.join(root, 'mobile/ios/App/App/Assets.xcassets/Splash.imageset/splash-2732x2732.png'));
  await b2.close();
  console.log('Splash: iOS 2732x2732');
  /* iOS erwartet drei Dateien gleichen Inhalts (1x/2x/3x) */
  const ios = path.join(root, 'mobile/ios/App/App/Assets.xcassets/Splash.imageset/splash-2732x2732');
  for (const n of ['-1', '-2']) fs.copyFileSync(ios + '.png', ios + n + '.png');
})();
