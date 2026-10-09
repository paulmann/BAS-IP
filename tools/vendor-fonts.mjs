#!/usr/bin/env node
/**
 * vendor-fonts.mjs — copy the woff2 fonts used by the UI from @fontsource
 * packages into public/assets/fonts, plus their OFL licences.
 *
 *   node tools/vendor-fonts.mjs
 *
 * The app ships latin + cyrillic subsets only, weights the design uses:
 *   Inter           400 / 500 / 600 / 700   (UI)
 *   JetBrains Mono  400 / 500               (code, plate numbers, sums)
 *
 * resources/css/fonts.css references exactly these file names — the script
 * verifies the two stay in sync and fails loudly when they drift.
 * No CDN at runtime, ever.
 */

import { copyFileSync, readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const outDir = join(root, 'public', 'assets', 'fonts');
mkdirSync(outDir, { recursive: true });

/** package → [target prefix, subsets, weights, licence output name] */
const families = [
  {
    pkg: '@fontsource/inter',
    prefix: 'inter',
    subsets: ['latin', 'cyrillic'],
    weights: [400, 500, 600, 700],
    license: 'LICENSE-inter',
  },
  {
    pkg: '@fontsource/jetbrains-mono',
    prefix: 'jbmono',
    subsets: ['latin', 'cyrillic'],
    weights: [400, 500],
    license: 'LICENSE-jetbrains-mono',
  },
];

const expected = [];
let copied = 0;

for (const family of families) {
  const pkgDir = join(root, 'node_modules', family.pkg);
  if (!existsSync(pkgDir)) {
    console.error(`✗ ${family.pkg} not installed — run \`npm install\` first.`);
    process.exit(1);
  }
  const pkgJson = JSON.parse(readFileSync(join(pkgDir, 'package.json'), 'utf8'));

  for (const subset of family.subsets) {
    for (const weight of family.weights) {
      const src = join(pkgDir, 'files', `${family.prefix === 'jbmono' ? 'jetbrains-mono' : family.prefix}-${subset}-${weight}-normal.woff2`);
      const destName = `${family.prefix}-${subset}-${weight}.woff2`;
      const dest = join(outDir, destName);
      if (!existsSync(src)) {
        console.error(`✗ missing in ${family.pkg}@${pkgJson.version}: files/${src.split('/files/')[1]}`);
        process.exit(1);
      }
      copyFileSync(src, dest);
      expected.push(destName);
      copied++;
    }
  }

  const licenseSrc = join(pkgDir, 'LICENSE');
  if (existsSync(licenseSrc)) {
    writeFileSync(join(outDir, family.license), readFileSync(licenseSrc, 'utf8'));
  }
}

/* ── verify resources/css/fonts.css only references vendored files ──────── */

const fontsCss = readFileSync(join(root, 'resources', 'css', 'fonts.css'), 'utf8');
const referenced = new Set();
for (const m of fontsCss.matchAll(/url\(['"]?\.\.\/fonts\/([^'")]+)['"]?\)/g)) {
  referenced.add(m[1]);
}

const problems = [];
for (const name of referenced) {
  if (!expected.includes(name)) problems.push(`fonts.css references ${name} but it was not vendored`);
}
for (const name of expected) {
  if (!referenced.has(name)) problems.push(`${name} was vendored but fonts.css never references it`);
}
if (problems.length > 0) {
  console.error('✗ font manifest drift:\n  ' + problems.join('\n  '));
  process.exit(1);
}

console.log(`✓ fonts: ${copied} woff2 files → public/assets/fonts (referenced by resources/css/fonts.css)`);
