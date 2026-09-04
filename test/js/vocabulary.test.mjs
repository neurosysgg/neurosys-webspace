/**
 * Every element the sources define is one main.ts imports.
 *
 * Registration is a side effect of importing a module, so an element left out of main.ts's list is
 * an element the browser never hears about — and an unregistered tag renders as an inert inline box
 * with nothing in the console. The verify script catches that for tags a view writes out, by
 * checking the served markup. It cannot catch it for the tags an element builds itself:
 * <terminal-cursor> appears in no server response, so a missing import for it would be invisible.
 *
 * This closes that half. The tag list is read from assets/ts/elements/ rather than restated here,
 * so adding an element needs no edit to this file — only an import in main.ts, which is the point.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';

import { ROOT } from './dom.mjs';

const ELEMENTS = `${ROOT}/assets/ts/elements`;

/** Every tag registered anywhere under assets/ts/elements/, with the file that registers it. */
function declaredTags() {
  const tags = new Map();

  for (const file of readdirSync(ELEMENTS, { recursive: true })) {
    if (!file.endsWith('.ts')) continue;

    const source = readFileSync(`${ELEMENTS}/${file}`, 'utf8');

    for (const [, tag] of source.matchAll(/customElements\.define\('([a-z][a-z0-9-]*)'/g)) {
      tags.set(tag, file);
    }
  }

  return tags;
}

test('every tag the sources register is registered after main.js loads', () => {
  const tags = declaredTags();

  assert.ok(tags.size > 0, 'found no customElements.define() at all — the scan is broken');

  const missing = [...tags]
    .filter(([tag]) => customElements.get(tag) === undefined)
    .map(([tag, file]) => `<${tag}> (${file})`);

  assert.deepEqual(missing, [], 'missing from main.ts\'s import list');
});

test('one class per file: no module registers two tags', () => {
  const files = [];

  for (const file of readdirSync(ELEMENTS, { recursive: true })) {
    if (!file.endsWith('.ts')) continue;

    const source = readFileSync(`${ELEMENTS}/${file}`, 'utf8');
    const count  = [...source.matchAll(/customElements\.define\(/g)].length;

    if (count > 1) files.push(`${file} registers ${count}`);
  }

  assert.deepEqual(files, []);
});

test('a module is named for the class it defines', () => {
  const wrong = [];

  for (const file of readdirSync(ELEMENTS, { recursive: true })) {
    if (!file.endsWith('.ts')) continue;

    const source = readFileSync(`${ELEMENTS}/${file}`, 'utf8');
    const name   = file.split('/').pop().replace(/\.ts$/, '');

    if (!new RegExp(`export (abstract )?class ${name}\\b`).test(source)) {
      wrong.push(file);
    }
  }

  assert.deepEqual(wrong, []);
});
