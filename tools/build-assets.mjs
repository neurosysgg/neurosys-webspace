/**
 * Stamps every built asset with a hash of its content, and writes what came out to
 * src/NeuroSYS/AssetManifest.php, which Layout reads to emit the stylesheet, the entry script and
 * the modulepreload list.
 *
 * Two jobs that have to be one tool, because the second depends on the first:
 *
 *   1. Version. `/assets/js/model/Tag.js?v=a1b2c3d4` is a different URL from the same file at a
 *      different version, so it can be cached for a year with `immutable`. Without it the site
 *      asks for `/assets/js/main.js` by that exact name forever, and a long max-age would mean
 *      "keep serving the old one after we replace it" rather than "keep this one".
 *   2. Preload. An ES module graph is discovered a wave at a time and this one is five deep, so
 *      the browser would otherwise spend five sequential round trips learning what to fetch.
 *      `<link rel="modulepreload">` in <head> flattens that to one.
 *
 * The href in a preload hint must match the specifier the module actually resolves, query and all,
 * or the browser fetches the file twice and the hint is worse than useless. That is why one tool
 * owns both: the hash it stamps into a specifier is the hash it writes into the manifest.
 *
 * **A version segment in the path, not a renamed file and not a query.** `Tag.a1b2c3d4.js` is the
 * conventional shape and would break every test that imports `public/assets/js/model/Tag.js` by
 * name — costing the property that the client tests load exactly what the browser loads. `?v=` on
 * each import specifier reads well and cost the front end's 100% coverage gate, for the reason
 * written at the stamp below. A path segment costs neither: a relative specifier resolves against
 * the URL it was loaded from, so `/assets/js/v-a1b2c3d4/main.js` importing `./model/Tag.js` asks
 * for `/assets/js/v-a1b2c3d4/model/Tag.js` with nothing having to rewrite anything.
 *
 * So this tool **writes no file but the manifest**. The compiled JS is byte-identical to what tsc
 * emitted, which is what keeps the drift check a straight diff, the tests importing plain paths,
 * and coverage attributing to the file it is measuring.
 *
 * The segment is stripped by the server — `public/.htaccess` in production and
 * `tools/dev-router.php` under the `php -S` the verify script runs. Those two are a mirror, and the
 * verify script pins that they strip the same shape.
 *
 * Deliberately not versioned: everything under `assets/img/`. Those are vendored, hand-placed and
 * referenced from `Platform::icon()` and `Config::COVER_PLACEHOLDER` as plain constants — teaching
 * a Model enum to consult a build artefact would cost more than a calendar TTL on files that change
 * about never. The line is: assets the build generates get a content hash, assets a person drops in
 * keep a date. `public/.htaccess` gives those thirty days.
 *
 * Usage:
 *   node tools/build-assets.mjs                       # stamps public/, writes the manifest
 *   node tools/build-assets.mjs --js-dir <dir> \      # against a scratch tree, for the drift check
 *                              --css <file> --out <path>
 *
 * No dependencies. Exits non-zero with the reason on stderr; it never writes a partial manifest.
 */

import { createHash } from 'node:crypto';
import { readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');

/** The URL prefixes public/assets/{js,css}/ are served under. */
const JS_BASE  = '/assets/js';
const CSS_BASE = '/assets/css';

/** A line comment, and a block comment, non-greedy — stripped before scanning for imports. */
const COMMENT = /\/\/[^\n]*|\/\*[\s\S]*?\*\//g;

/**
 * `import x from 'y'`, `import 'y'` and `export … from 'y'` — the three forms that name a
 * dependency the browser must fetch. Bare `import()` is deliberately not matched: a dynamic import
 * is a decision to fetch later, and stamping it would undo the reason it was written that way.
 *
 * Anchored to a whole line, and `[^'"\n]` rather than `[^'"]`, because the loose version walked out
 * of `export class Config {` and into the string on the line below it, then reported `neuro.SYS` as
 * an unresolvable import. tsc emits one statement per line and terminates each with a semicolon, so
 * requiring both costs nothing and makes that false match impossible.
 */
const SPECIFIER = /^\s*(?:import|export)\b[^'"\n]*?['"]([^'"\n]+)['"]\s*;\s*$/gm;

/**
 * The path segment carrying the build stamp. `public/.htaccess` and `tools/dev-router.php` both
 * strip `v-<8 hex>` directly under the asset root; the verify script pins that they agree.
 */
const VERSION_PREFIX = 'v-';

function fail(message) {
  console.error(`build-assets: ${message}`);
  process.exit(1);
}

function label(file) {
  return relative(ROOT, file).split(/[\\/]/).join('/');
}

function flag(name, fallback) {
  const at = process.argv.indexOf(name);

  return at === -1 ? fallback : resolve(process.argv[at + 1] ?? fail(`${name} needs a path`));
}

const JS_DIR   = flag('--js-dir', join(ROOT, 'public/assets/js'));
const CSS_FILE = flag('--css', join(ROOT, 'public/assets/css/style.css'));
const MANIFEST = flag('--out', join(ROOT, 'src/NeuroSYS/AssetManifest.php'));
const ENTRY    = join(JS_DIR, 'main.js');

/** Eight hex characters of SHA-256 — 32 bits over forty-two files, so a collision is not a risk. */
function digest(content) {
  return createHash('sha256').update(content).digest('hex').slice(0, 8);
}

/** The unversioned path below the js dir, under the served prefix. */
function jsUrl(file) {
  return `${JS_BASE}/${relative(JS_DIR, file).split(/[\\/]/).join('/')}`;
}

/**
 * Inserts the build stamp as a path segment: /assets/js/x.js -> /assets/js/v-a1b2c3d4/x.js
 *
 * Directly after the asset root and before everything else, because that is the only position a
 * relative specifier carries with it. A stamp at the end would not survive `./model/Tag.js`.
 */
function versioned(path) {
  return path.replace(/^(\/assets\/(?:js|css))\//, `$1/${VERSION_PREFIX}${stamp}/`);
}

// ── walk ────────────────────────────────────────────────────────────────────────────────────────

/** file → its dependencies, in the order they are imported. */
const graph = new Map();

/** Grey while a file's subtree is being walked, so a cycle is reported rather than looped on. */
const walking = new Set();

function walk(file, importedBy) {
  if (graph.has(file)) {
    return;
  }

  if (walking.has(file)) {
    fail(`${label(file)} is part of an import cycle, reached again from ${importedBy}.\n`
       + '              A per-file content hash has no fixpoint around a cycle: each file\'s hash\n'
       + '              would depend on its own. Break the cycle, or stamp one hash per build.');
  }

  walking.add(file);

  let source;

  try {
    source = readFileSync(file, 'utf8');
  } catch {
    fail(`${importedBy} imports ${label(file)}, which does not exist.\n`
       + '              Run `npm run build` — the committed JS is behind assets/ts/.');
  }

  const deps = [];

  for (const [, bare] of source.replace(COMMENT, '').matchAll(SPECIFIER)) {

    if (!bare.startsWith('.')) {
      fail(`${label(file)} imports "${bare}", which is not a relative path.\n`
         + '              Nothing here is bundled, so a bare specifier is a URL the browser 404s on.');
    }

    if (!bare.endsWith('.js')) {
      fail(`${label(file)} imports "${bare}" without a .js extension.\n`
         + '              tsconfig\'s nodenext should have made that a compile error — rebuild.');
    }

    const dep = resolve(dirname(file), bare);

    deps.push(dep);
    walk(dep, label(file));
  }

  walking.delete(file);
  graph.set(file, deps);
}

walk(ENTRY, 'the build');

// ── the build stamp ────────────────────────────────────────────────────────────────────────────

/**
 * One hash over every built asset, rather than one per file.
 *
 * Per-file would be better — editing one element would bust that element and its ancestors instead
 * of all forty-two — and it is what an earlier version of this tool did, by writing the hash into
 * each import specifier as `?v=`. That works in a browser and it cost the front end's 100% coverage
 * gate: a module reached through a stamped specifier is attributed by V8 to
 * `…/CoverArt.js?v=48f0b166`, which `--test-coverage-include` does not match, so every module the
 * tests reach through `main.js` reported zero and `--test-coverage-include-all` listed the bare path
 * as uncovered. The gate is a deliberate property and worth more than per-file granularity over
 * twelve kilobytes.
 *
 * Putting the version in the *path* instead costs nothing, because a relative specifier resolves
 * against the URL it was loaded from: `/assets/js/v-a1b2c3d4/main.js` importing `./model/Tag.js`
 * asks for `/assets/js/v-a1b2c3d4/model/Tag.js` without anything having to rewrite it. So the
 * committed JS is byte-identical to what tsc emitted, which is what keeps the drift check a
 * straight diff and the client tests loading exactly the files the browser runs.
 *
 * The server strips the segment: `public/.htaccess` for production, `tools/dev-router.php` for the
 * `php -S` the verify script runs. That pair is a mirror, and pinned like the others.
 */
const stamp = digest(
  [...graph.keys()]
    .sort()
    .map((file) => `${jsUrl(file)}\u0000${readFileSync(file, 'utf8')}`)
    .concat(`${CSS_BASE}\u0000${readFileSync(CSS_FILE, 'utf8')}`)
    .join('\u0000'),
);

// main.js is the <script src> itself; hinting the browser to preload the file it is already
// fetching is noise, so the list is everything the entry reaches and not the entry.
const modules = [...graph.keys()]
  .filter((file) => file !== ENTRY)
  .map((file) => versioned(jsUrl(file)))
  .sort();

if (modules.length === 0) {
  fail('main.js reaches no other module. That is either a broken build or a bundler appearing,\n'
     + '              and either way this file should not be generated from it.');
}

// ── the stylesheet ──────────────────────────────────────────────────────────────────────────────

// Read to prove it is there and to fail with a useful sentence if it is not. Its content is already
// inside the build stamp above, so the manifest needs nothing from it but the path.
try {
  readFileSync(CSS_FILE, 'utf8');
} catch {
  fail(`${label(CSS_FILE)} does not exist. Run \`npm run build:css\` first — the manifest names it.`);
}

const stylesheet = versioned(`${CSS_BASE}/${relative(dirname(CSS_FILE), CSS_FILE)}`);

// ── the manifest ────────────────────────────────────────────────────────────────────────────────

const php = `<?php

declare(strict_types=1);

namespace NeuroSYS;

/**
 * Generated by tools/build-assets.mjs — do not edit.
 *
 * Every built asset the shell loads, as the URL the browser asks for, carrying a hash of the
 * content at that URL. {@link Layout} emits all three: the stylesheet, the entry script, and one
 * \`<link rel="modulepreload">\` per module so the graph is discovered in one round trip instead of
 * the wave-at-a-time walk an ES module tree is otherwise found by.
 *
 * The version is what lets \`public/.htaccess\` mark these \`immutable\` for a year. It is a hash of
 * the file's content *after* its own imports were stamped, so a change to a leaf module changes
 * every hash above it and none beside it.
 *
 * These are the versioned URLs. {@link Config}'s \`SCRIPT\` and \`STYLESHEET\` remain the canonical
 * unversioned paths — the fact about where the file lives, which is Config's business; this is the
 * fact about which copy of it, which is the build's. \`ViewTest\` pins that the two agree.
 *
 * Regenerate with \`npm run build\`. test/basic_test.sh rebuilds this file and diffs, so an edit
 * made here is lost at the next build and fails the verify script in the meantime — the same
 * arrangement as public/assets/css/style.css and public/assets/js/.
 */
final class AssetManifest
{
    /** The stylesheet, versioned. */
    public const string STYLESHEET = '${stylesheet}';

    /** The entry point — the only \`<script>\` the site loads — versioned. */
    public const string SCRIPT = '${versioned(jsUrl(ENTRY))}';

    /** @var list<string> Every module the entry reaches, versioned, sorted, the entry excluded. */
    public const array MODULES = [
${modules.map((module) => `        '${module}',`).join('\n')}
    ];
}
`;

mkdirSync(dirname(MANIFEST), { recursive: true });
writeFileSync(MANIFEST, php, 'utf8');

console.log(`build-assets: ${graph.size} modules at ${VERSION_PREFIX}${stamp}, ${modules.length} preloaded → ${label(MANIFEST)}`);
