#!/usr/bin/env node
/**
 * Erzeugt Platzhalter-App-Icons (PNG) ohne externe Abhängigkeiten – gleiches Motiv
 * wie public/icons/icon.svg. Für das Store-Release durch finale Grafiken ersetzen
 * (z. B. mit `npx @capacitor/assets generate`).
 *
 *   node scripts/gen-icons.mjs
 */
import { existsSync, mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { deflateSync } from 'node:zlib';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');

const RED = [0xe2, 0x00, 0x1a];
const NAVY = [0x0f, 0x17, 0x2a];
const WHITE = [0xff, 0xff, 0xff];

function roundRect(x, y, rx, ry, w, h, r) {
  if (x < rx || y < ry || x > rx + w || y > ry + h) return false;
  const cx = Math.min(Math.max(x, rx + r), rx + w - r);
  const cy = Math.min(Math.max(y, ry + r), ry + h - r);
  return (x - cx) ** 2 + (y - cy) ** 2 <= r * r;
}

/** Farbe an Position (Koordinaten im 512er-Raster). `pad` verkleinert das Motiv (maskable). */
function sample(x, y, { rounded, pad, transparentOutside }) {
  const bgOk = rounded ? roundRect(x, y, 0, 0, 512, 512, 112) : true;
  if (!bgOk) return transparentOutside ? null : RED;
  // Motiv skalieren
  const s = 1 - pad * 2;
  const mx = (x - 256) / s + 256;
  const my = (y - 256) / s + 256;
  const mix = (base, col, a) => base.map((v, i) => v + (col[i] - v) * a);
  let c = RED;
  if (roundRect(mx, my, 112, 128, 288, 264, 36)) c = WHITE;
  if (roundRect(mx, my, 112, 128, 288, 72, 36) || (mx >= 112 && mx <= 400 && my >= 164 && my <= 200)) c = NAVY;
  if (roundRect(mx, my, 168, 96, 32, 72, 16) || roundRect(mx, my, 312, 96, 32, 72, 16)) c = WHITE;
  const cells = [
    [152, 232, 1], [228, 232, 1], [304, 232, 0.35],
    [152, 304, 0.35], [228, 304, 1], [304, 304, 0.35],
  ];
  for (const [cx, cy, a] of cells) if (roundRect(mx, my, cx, cy, 56, 56, 10)) c = mix(WHITE, RED, a);
  return c;
}

function crc32(buf) {
  let c;
  const table = crc32.table || (crc32.table = Array.from({ length: 256 }, (_, n) => {
    c = n;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    return c >>> 0;
  }));
  let crc = 0xffffffff;
  for (const b of buf) crc = table[(crc ^ b) & 0xff] ^ (crc >>> 8);
  return (crc ^ 0xffffffff) >>> 0;
}

function chunk(type, data) {
  const len = Buffer.alloc(4);
  len.writeUInt32BE(data.length);
  const td = Buffer.concat([Buffer.from(type, 'ascii'), data]);
  const crc = Buffer.alloc(4);
  crc.writeUInt32BE(crc32(td));
  return Buffer.concat([len, td, crc]);
}

function png(size, opts) {
  const SS = 3; // Supersampling für Kantenglättung
  const raw = Buffer.alloc((size * 4 + 1) * size);
  for (let y = 0; y < size; y++) {
    raw[y * (size * 4 + 1)] = 0;
    for (let x = 0; x < size; x++) {
      let r = 0, g = 0, b = 0, a = 0;
      for (let sy = 0; sy < SS; sy++) {
        for (let sx = 0; sx < SS; sx++) {
          const px = ((x + (sx + 0.5) / SS) / size) * 512;
          const py = ((y + (sy + 0.5) / SS) / size) * 512;
          const c = sample(px, py, opts);
          if (c) {
            r += c[0]; g += c[1]; b += c[2]; a += 255;
          }
        }
      }
      const n = SS * SS;
      const o = y * (size * 4 + 1) + 1 + x * 4;
      const alpha = a / n;
      raw[o] = alpha ? Math.round(r / (a / 255)) : 0;
      raw[o + 1] = alpha ? Math.round(g / (a / 255)) : 0;
      raw[o + 2] = alpha ? Math.round(b / (a / 255)) : 0;
      raw[o + 3] = Math.round(alpha);
    }
  }
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(size, 0);
  ihdr.writeUInt32BE(size, 4);
  ihdr[8] = 8; ihdr[9] = 6; ihdr[10] = 0; ihdr[11] = 0; ihdr[12] = 0;
  return Buffer.concat([
    Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
    chunk('IHDR', ihdr),
    chunk('IDAT', deflateSync(raw, { level: 9 })),
    chunk('IEND', Buffer.alloc(0)),
  ]);
}

function write(rel, size, opts) {
  const file = join(root, rel);
  mkdirSync(dirname(file), { recursive: true });
  writeFileSync(file, png(size, opts));
  console.log(`  ${rel} (${size}×${size})`);
}

const square = { rounded: false, pad: 0, transparentOutside: false };
const rounded = { rounded: true, pad: 0, transparentOutside: true };
const maskable = { rounded: false, pad: 0.1, transparentOutside: false };

console.log('PWA-Icons:');
write('public/icons/icon-180.png', 180, square);
write('public/icons/icon-192.png', 192, rounded);
write('public/icons/icon-512.png', 512, rounded);
write('public/icons/icon-maskable-512.png', 512, maskable);

const androidRes = join(root, 'android/app/src/main/res');
if (existsSync(androidRes)) {
  console.log('Android-Icons:');
  const dens = { mdpi: 48, hdpi: 72, xhdpi: 96, xxhdpi: 144, xxxhdpi: 192 };
  for (const [d, s] of Object.entries(dens)) {
    write(`android/app/src/main/res/mipmap-${d}/ic_launcher.png`, s, rounded);
    write(`android/app/src/main/res/mipmap-${d}/ic_launcher_round.png`, s, rounded);
    // Adaptive-Icon-Vordergrund: 108dp-Fläche, Motiv in der sicheren Zone (66 %)
    write(`android/app/src/main/res/mipmap-${d}/ic_launcher_foreground.png`, Math.round((s * 108) / 48), { rounded: false, pad: 0.2, transparentOutside: false });
  }
}

const iosIcon = join(root, 'ios/App/App/Assets.xcassets/AppIcon.appiconset');
if (existsSync(iosIcon)) {
  console.log('iOS-Icon:');
  // iOS: 1024er-Icon ohne Transparenz, Rundung macht das System
  write('ios/App/App/Assets.xcassets/AppIcon.appiconset/AppIcon-512@2x.png', 1024, square);
}
