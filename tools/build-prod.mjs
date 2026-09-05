/**
 * Builds the tree that ships, out of the tree that is committed.
 *
 * `public/` is three things at once — what the browser loads, what `test/js/` imports by path, and
 * what the verify script diffs byte-for-byte against a fresh `tsc`. The last two are why it stays
 * readable and mapped: a minified `public/assets/js/` would make the drift check a diff between two
 * things a person cannot compare, and `?v=`-style attribution problems aside, coverage is pinned to
 * those exact paths. So prod is a **second tree**, derived from the first and never committed:
 *
 *     public/                       ← readable, mapped, committed, tested
 *           ↓ npm run build:prod
 *     build/dist/public/            ← minified, no maps; what deploy.sh rsyncs to the webroot
 *     build/dist/src/NeuroSYS/AssetManifest.php
 *
 * Two things change, and both are only worth doing here:
 *
 *   1. **The maps go.** 79,354 bytes across 42 files, three times the JS they describe, and
 *      `tsconfig`'s `inlineSources` puts the whole commented TypeScript inside each one. Static
 *      assets are served straight by Apache and reach neither auth gate (docs/security.md), so on
 *      the live host those are public files. The source is on GitHub, which is a reason not to
 *      worry about it rather than a reason to serve a second copy from Strato.
 *   2. **The JS is minified.** Measured over the 42 separate responses the browser actually makes,
 *      which is the number that matters here because nothing is bundled: 11,807 gzipped bytes
 *      before, 9,555 after — 2,252 saved, 19.1%. `tsconfig` used to argue this was worth ~260
 *      bytes; that is roughly what you measure on a *concatenated* stream, where gzip's window
 *      spans the whole graph and does the work identifier mangling would have done. Per file the
 *      window is one small module and it does not.
 *
 * **`keep_classnames` is load-bearing, not a default left alone.** `NestedElement.tagOf()` falls
 * back to `constructor.name` when `customElements.getName` is missing, and that is the text of the
 * error a misnested tag throws — the whole reason those classes are not empty. Mangling class names
 * would turn `<terminal-key> must be inside <terminal-field>` into `must be inside <e>`. It is also
 * why terser rather than esbuild: esbuild implements the same guarantee by injecting a `__name`
 * helper into every file, which on 42 small modules costs 1,868 of the 2,252 bytes minifying won.
 *
 * `mangle.properties` stays off for the same kind of reason one step further out: `connectedCallback`,
 * `observedAttributes` and `attributeChangedCallback` are contracts with the browser rather than
 * with us, and renaming them would leave elements that register and then never fire.
 *
 * Usage:
 *   node tools/build-prod.mjs                # build/dist/, from the committed public/
 *   node tools/build-prod.mjs --out <dir>    # elsewhere
 *
 * Assumes `public/` is current — `npm run build:prod` runs `npm run build` first rather than
 * trusting that. Exits non-zero with the reason on stderr; it clears the tree before it starts, so
 * a failed build leaves no partial one to be deployed by mistake.
 */

import { minify } from 'terser';
import { cpSync, mkdirSync, readdirSync, readFileSync, rmSync, statSync, unlinkSync, writeFileSync }
  from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';

const ROOT   = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const PUBLIC = join(ROOT, 'public');
const JS     = join(PUBLIC, 'assets/js');

/**
 * Exits with a reason. Declared as returning `never` so it can stand in an expression — the same
 * shape build-css.mjs and build-assets.mjs use, for the same reason.
 *
 * @returns {never}
 */
function fail(message) {
  console.error(`build-prod: ${message}`);
  process.exit(1);
}

/** Repo-relative, forward-slashed — what the messages say. */
function label(file) {
  const path = relative(ROOT, file);

  return path.startsWith('..') ? file : path.split(/[\\/]/).join('/');
}

function flag(name, fallback) {
  const at = process.argv.indexOf(name);

  return at === -1 ? fallback : resolve(process.argv[at + 1] ?? fail(`${name} needs a path`));
}

const DIST     = flag('--out', join(ROOT, 'build/dist'));
const DIST_PUB = join(DIST, 'public');
const DIST_JS  = join(DIST_PUB, 'assets/js');

/** Every file under `dir` whose name ends in `suffix`, as absolute paths, sorted. */
function filesEnding(dir, suffix) {
  const found = [];

  (function descend(at) {
    for (const entry of readdirSync(at).sort()) {
      const path = join(at, entry);

      if (statSync(path).isDirectory()) {
        descend(path);
      } else if (path.endsWith(suffix)) {
        found.push(path);
      }
    }
  })(dir);

  return found;
}

// ── the copy ────────────────────────────────────────────────────────────────────────────────────

// Cleared rather than merged into: rsync ships this tree wholesale, so a file left behind from a
// build two commits ago would be deployed as though it were current.
rmSync(DIST, { recursive: true, force: true });
mkdirSync(DIST, { recursive: true });

// All of public/, not just the JS. That is what makes build/dist/public/ exactly what lands in the
// webroot — one directory that can be listed, measured and diffed, rather than a deploy script
// assembling the answer out of two trees and getting the precedence right every time.
cpSync(PUBLIC, DIST_PUB, { recursive: true });

// ── the minify ──────────────────────────────────────────────────────────────────────────────────

const sources = filesEnding(JS, '.js');

if (sources.length === 0) {
  fail(`${label(JS)} holds no modules. Run \`npm run build\` — there is nothing here to ship.`);
}

let before = 0;
let after  = 0;

for (const source of sources) {
  const target = join(DIST_JS, relative(JS, source));
  const code   = readFileSync(source, 'utf8');

  const result = await minify(code, {
    ecma: 2022,

    // Without this terser parses the file as a script, where a top-level `import` is a syntax
    // error. It also lets it drop unreferenced top-level bindings, which a script cannot promise.
    module: true,

    compress: { passes: 2 },

    // Property names are the browser's vocabulary here, not ours — see the note at the top.
    mangle: { properties: false },

    // NestedElement.tagOf()'s fallback reads constructor.name. See the note at the top.
    keep_classnames: true,

    // Drops tsc's `//# sourceMappingURL=` line along with everything else, which is the half of
    // "no maps" that matters: an unstripped comment is a 404 in every DevTools that opens the page.
    format: { comments: false },
  });

  if (result.code === undefined) {
    fail(`${label(source)} did not minify: ${result.error ?? 'terser returned no code'}`);
  }

  before += Buffer.byteLength(code);
  after  += Buffer.byteLength(result.code);

  writeFileSync(target, result.code, 'utf8');
}

// ── everything was actually minified ────────────────────────────────────────────────────────────

// The copy above put a readable module at every path this tree serves, and the loop then overwrote
// each one. So a write that did not happen does not leave a hole — it leaves the *original*, which
// works perfectly and ships as though it had been minified, under a stamp claiming otherwise. The
// only visible symptom is a page that is quietly bigger than it says it is.
//
// All 42 change under terser today, so "none identical" is a real property rather than a hopeful
// one. It is the cheapest statement that distinguishes a minified tree from a copied one.
const untouched = sources
  .filter((file) => readFileSync(file, 'utf8') === readFileSync(join(DIST_JS, relative(JS, file)), 'utf8'))
  .map((file) => relative(JS, file));

if (untouched.length > 0) {
  fail(`${untouched.length} shipped module(s) are byte-identical to the readable tree, so the\n`
     + '              copy is what would deploy rather than the minified output:\n'
     + untouched.map((path) => `              ${path}`).join('\n'));
}

// ── the maps ────────────────────────────────────────────────────────────────────────────────────

const maps = filesEnding(DIST_JS, '.map');

for (const map of maps) {
  unlinkSync(map);
}

// Belt over braces on `format.comments`, because the failure it guards is invisible from here: a
// surviving reference costs nothing until somebody opens DevTools on the live site, and then it is
// a 404 per module with no other symptom.
const stragglers = filesEnding(DIST_JS, '.js')
  .filter((file) => readFileSync(file, 'utf8').includes('sourceMappingURL'))
  .map(label);

if (stragglers.length > 0) {
  fail(`${stragglers.length} shipped module(s) still name a source map that is not there:\n`
     + stragglers.map((file) => `              ${file}`).join('\n'));
}

// ── the manifest ────────────────────────────────────────────────────────────────────────────────

// The same URLs as the committed manifest under a different stamp, which is exactly right: the
// bytes at those URLs are different bytes, and a stamp is a claim about content.
const MANIFEST = join(DIST, 'src/NeuroSYS/AssetManifest.php');

try {
  execFileSync(process.execPath, [
    join(ROOT, 'tools/build-assets.mjs'),
    '--graph-dir', JS,
    '--js-dir', DIST_JS,
    '--css', join(DIST_PUB, 'assets/css/style.css'),
    '--out', MANIFEST,
  ], { stdio: ['ignore', 'ignore', 'inherit'] });
} catch {
  fail('the prod manifest could not be generated — see build-assets above.');
}

const percent = (100 * (1 - after / before)).toFixed(1);

console.log(`build-prod: ${sources.length} modules ${before} → ${after} bytes (${percent}% off), `
          + `${maps.length} maps dropped → ${label(DIST)}`);
