/**
 * Walks the module graph from public/assets/js/main.js and writes the list of everything it
 * reaches to src/NeuroSYS/ModuleGraph.php, which Layout turns into <link rel="modulepreload">.
 *
 * The problem it solves is latency, not bytes. An ES module graph is discovered a wave at a time:
 * the browser cannot know it needs model/CssClass.js until it has parsed ConsentGatedEmbed.js,
 * which it learns about from SoundCloudWidget.js, from SoundCloudPlayer.js, from main.js. That is
 * five sequential round trips before the last module starts downloading — half a second on a
 * 100ms link, and a number no amount of minifying or compressing moves, because it is not bytes.
 * Declared in <head>, the preload scanner sees all of them at once and the five waves become one.
 *
 * modulepreload rather than preload: it fetches, parses, compiles and inserts into the module map,
 * so the module is already instantiated when main.js finally asks for it. `preload as="script"`
 * fetches the bytes and does none of the rest.
 *
 * Every module is listed rather than just the first wave. The spec permits a browser to follow a
 * preloaded module's own imports and Chrome does, but it is not required to and Safari has been
 * uneven about it — so relying on that would make the fix silently partial on some browsers.
 *
 * It walks the compiled output rather than assets/ts/, because the list is a list of URLs and the
 * compiled tree is what actually sits at them. That also means it needs only node and the
 * committed JS, so — like tools/build-css.mjs — it runs on a clone that has never seen
 * `npm install`, and the verify script's drift check can be a failure rather than a skip.
 *
 * Usage:
 *   node tools/build-preload.mjs                 # writes src/NeuroSYS/ModuleGraph.php
 *   node tools/build-preload.mjs --out <path>    # writes elsewhere, for the drift check
 *
 * No dependencies. Exits non-zero with the reason on stderr; it never writes a partial file.
 */

import { readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT    = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const JS_DIR  = join(ROOT, 'public/assets/js');
const ENTRY   = join(JS_DIR, 'main.js');
const DEFAULT = join(ROOT, 'src/NeuroSYS/ModuleGraph.php');

/** The URL prefix public/assets/js/ is served under. Config::SCRIPT is this plus main.js. */
const URL_BASE = '/assets/js';

/** A line comment, and a block comment, non-greedy — stripped before scanning for imports. */
const COMMENT = /\/\/[^\n]*|\/\*[\s\S]*?\*\//g;

/**
 * `import x from 'y'`, `import 'y'` and `export … from 'y'` — the three forms that name a
 * dependency the browser must fetch. Bare `import()` is deliberately not matched: a dynamic import
 * is a decision to fetch later, and preloading it would undo the reason it was written that way.
 *
 * Anchored to a whole line, and `[^'"\n]` rather than `[^'"]`, because the loose version walked
 * out of `export class Config {` and into the string on the line below it, then reported
 * `neuro.SYS` as an unresolvable import. tsc emits one statement per line and terminates each
 * with a semicolon, so requiring both costs nothing and makes that false match impossible.
 */
const SPECIFIER = /^\s*(?:import|export)\b[^'"\n]*?['"]([^'"\n]+)['"]\s*;\s*$/gm;

function fail(message) {
  console.error(`build-preload: ${message}`);
  process.exit(1);
}

/** Repo-relative, forward-slashed — what the error messages say. */
function label(file) {
  return relative(ROOT, file).split(/[\\/]/).join('/');
}

/** The URL the browser asks for, which is the path below public/ with a leading slash. */
function url(file) {
  return `${URL_BASE}/${relative(JS_DIR, file).split(/[\\/]/).join('/')}`;
}

const reached = new Set();

function walk(file, importedBy) {
  if (reached.has(file)) {
    return;
  }

  reached.add(file);

  let source;

  try {
    source = readFileSync(file, 'utf8');
  } catch {
    fail(`${importedBy} imports ${label(file)}, which does not exist.\n`
       + '                Run `npm run build` — the committed JS is behind assets/ts/.');
  }

  for (const [, specifier] of source.replace(COMMENT, '').matchAll(SPECIFIER)) {
    if (!specifier.startsWith('.')) {
      fail(`${label(file)} imports "${specifier}", which is not a relative path.\n`
         + '                Nothing here is bundled, so a bare specifier is a URL the browser 404s on.');
    }

    if (!specifier.endsWith('.js')) {
      fail(`${label(file)} imports "${specifier}" without a .js extension.\n`
         + '                tsconfig\'s nodenext should have made that a compile error — rebuild.');
    }

    walk(resolve(dirname(file), specifier), label(file));
  }
}

walk(ENTRY, 'the build');

// main.js is the <script src> itself; hinting the browser to preload the file it is already
// fetching is noise, so the list is everything the entry reaches and not the entry.
const modules = [...reached].filter((file) => file !== ENTRY).map(url).sort();

if (modules.length === 0) {
  fail('main.js reaches no other module. That is either a broken build or a bundler appearing,\n'
     + '                and either way this file should not be generated from it.');
}

const out = process.argv.indexOf('--out') === -1
  ? DEFAULT
  : resolve(process.argv[process.argv.indexOf('--out') + 1] ?? fail('--out needs a path'));

const php = `<?php

declare(strict_types=1);

namespace NeuroSYS;

/**
 * Generated by tools/build-preload.mjs from public/assets/js/main.js — do not edit.
 *
 * Every module reachable from the entry point, as the URL the browser asks for. {@link Layout}
 * renders one \`<link rel="modulepreload">\` per entry so the graph is discovered in one round trip
 * instead of the wave-at-a-time walk an ES module tree is otherwise discovered by.
 *
 * Regenerate with \`npm run build\`. test/basic_test.sh rebuilds this file and diffs, so an edit
 * made here is lost at the next build and fails the verify script in the meantime — the same
 * arrangement as public/assets/css/style.css and public/assets/js/.
 *
 * The list is every module rather than the first wave, and main.js is deliberately absent: it is
 * the \`<script src>\` already being fetched. See the tool for both reasons.
 */
final class ModuleGraph
{
    /** @var list<string> Served URLs, sorted, main.js excluded. */
    public const array MODULES = [
${modules.map((module) => `        '${module}',`).join('\n')}
    ];
}
`;

mkdirSync(dirname(out), { recursive: true });
writeFileSync(out, php, 'utf8');

console.log(`build-preload: ${modules.length} modules → ${label(out)}`);
