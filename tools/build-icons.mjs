#!/usr/bin/env node
/**
 * build-icons.mjs — generate the local Lucide SVG sprite.
 *
 *   node tools/build-icons.mjs            # union(used icons, existing manifest)
 *   node tools/build-icons.mjs --prune    # only icons actually found in sources
 *
 * Scans PHP views / help / JS for icon names, pulls the matching symbols from
 * node_modules/lucide-static and writes:
 *
 *   public/assets/icons/lucide.svg     — <symbol id="i-NAME">…</symbol> sprite
 *   public/assets/icons/manifest.json  — generator + sorted icon list
 *   public/assets/icons/LICENSE        — attribution (ISC)
 *
 * No CDN at runtime: the sprite is the single source of every glyph the UI
 * references via `$view->icon('name')` → `<use href="…lucide.svg#i-name">`.
 */

import { readdirSync, readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const iconsDir = join(root, 'node_modules', 'lucide-static', 'icons');
const outDir = join(root, 'public', 'assets', 'icons');
const prune = process.argv.includes('--prune');

if (!existsSync(iconsDir)) {
  console.error('✗ node_modules/lucide-static not found — run `npm install` first.');
  process.exit(1);
}

const pkg = JSON.parse(readFileSync(join(root, 'node_modules', 'lucide-static', 'package.json'), 'utf8'));

/* ── 1. collect icon names used anywhere in the sources ─────────────────── */

const scanRoots = [
  join(root, 'resources', 'views'),
  join(root, 'resources', 'help'),
  join(root, 'src'),
  join(root, 'public', 'assets', 'js'),
];

/** Every literal shape an icon name can appear in. */
const patterns = [
  /->\s*icon\(\s*['"]([a-z0-9][a-z0-9-]*)['"]/g,          // $this->icon('name'
  /[^->]\bicon\(\s*['"]([a-z0-9][a-z0-9-]*)['"]/g,        // icon('name'  (JS/plain)
  /['"]icon['"]\s*=>\s*['"]([a-z0-9][a-z0-9-]*)['"]/g,    // 'icon' => 'name' (component args)
  /data-icon=['"]([a-z0-9][a-z0-9-]*)['"]/g,              // data-icon="name"
  /#i-([a-z0-9][a-z0-9-]*)/g,                             // direct <use href="#i-name">
  /\bicon:\s*['"]([a-z0-9][a-z0-9-]*)['"]/g,              // { icon: 'name' } (JS)
];

function* walk(dir) {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = join(dir, entry.name);
    if (entry.isDirectory()) {
      if (entry.name === 'vendor' || entry.name === 'node_modules') continue;
      yield* walk(full);
    } else if (/\.(php|js|mjs|md)$/i.test(entry.name)) {
      yield full;
    }
  }
}

const used = new Set();
for (const scanRoot of scanRoots) {
  if (!existsSync(scanRoot)) continue;
  for (const file of walk(scanRoot)) {
    const text = readFileSync(file, 'utf8');
    for (const re of patterns) {
      re.lastIndex = 0;
      let m;
      while ((m = re.exec(text)) !== null) used.add(m[1]);
    }
  }
}

/* ── 2. merge with the committed manifest (never lose a dynamic icon) ───── */

const manifestFile = join(outDir, 'manifest.json');
let icons = [...used];
if (!prune && existsSync(manifestFile)) {
  const prev = JSON.parse(readFileSync(manifestFile, 'utf8'));
  if (Array.isArray(prev.icons)) icons.push(...prev.icons);
}
icons = [...new Set(icons)].sort((a, b) => a.localeCompare(b));

/* ── 3. build the sprite from lucide-static ─────────────────────────────── */

const SKIP_ATTRS = new Set(['xmlns', 'width', 'height', 'class']);
const missing = [];
const symbols = [];

for (const name of icons) {
  const file = join(iconsDir, `${name}.svg`);
  if (!existsSync(file)) {
    missing.push(name);
    continue;
  }
  let svg = readFileSync(file, 'utf8');
  svg = svg.replace(/<!--[\s\S]*?-->\s*/g, '');               // strip @license comment
  const openEnd = svg.indexOf('>');
  const openTag = svg.slice(0, openEnd);
  const inner = svg.slice(openEnd + 1, svg.lastIndexOf('</svg>')).trim();

  const attrs = [];
  const attrRe = /([a-zA-Z-]+)="([^"]*)"/g;
  let m;
  while ((m = attrRe.exec(openTag)) !== null) {
    if (!SKIP_ATTRS.has(m[1])) attrs.push(`${m[1]}="${m[2]}"`);
  }

  symbols.push(`<symbol id="i-${name}" ${attrs.join(' ')}>${inner}</symbol>`);
}

if (missing.length > 0) {
  console.error(`✗ ${missing.length} icon(s) not found in lucide-static ${pkg.version}: ${missing.join(', ')}`);
  console.error('  Fix the name in the source, or bump lucide-static. Sprite NOT written.');
  process.exit(1);
}

mkdirSync(outDir, { recursive: true });

const sprite =
  '<svg xmlns="http://www.w3.org/2000/svg" style="display:none" aria-hidden="true"><defs>\n' +
  symbols.join('\n') +
  '\n</defs></svg>\n';

writeFileSync(join(outDir, 'lucide.svg'), sprite);
writeFileSync(
  manifestFile,
  JSON.stringify({
    generator: `lucide-static ${pkg.version} (vendored locally, ISC License)`,
    count: symbols.length,
    icons: symbols.map((_, i) => icons[i]),
  }) + '\n',
);

const licenseSrc = join(root, 'node_modules', 'lucide-static', 'LICENSE');
if (existsSync(licenseSrc)) {
  writeFileSync(
    join(outDir, 'LICENSE'),
    'Icons: Lucide (https://lucide.dev) — ISC License, Copyright (c) Lucide contributors.\n' +
    'Vendored locally as an SVG sprite so the app never depends on a CDN.\n\n' +
    readFileSync(licenseSrc, 'utf8'),
  );
}

console.log(`✓ icons: ${symbols.length} symbols → public/assets/icons/lucide.svg (lucide-static ${pkg.version}, scanned ${used.size} used)`);
