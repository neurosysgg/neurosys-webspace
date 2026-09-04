/**
 * Every element the Tag enum names is one main.ts imports.
 *
 * Registration is a side effect of importing a module, so an element left out of main.ts's list is
 * an element the browser never hears about — and an unregistered tag renders as an inert inline box
 * with nothing in the console. The verify script catches that for tags a view writes out, by
 * checking the served markup. It cannot catch it for the tags an element builds itself:
 * <terminal-cursor> appears in no server response, so a missing import for it would be invisible.
 *
 * This closes that half. Tag is the list — it is not restated here — so adding an element means a
 * case, a module and an import, and forgetting the import fails below.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';

import { ROOT } from './dom.mjs';
import { Tag } from '../../public/assets/js/model/Tag.js';

const ELEMENTS = `${ROOT}/assets/ts/elements`;

/** Every element module, as [file, source] — the abstracts included; they register nothing. */
function modules() {
  return readdirSync(ELEMENTS, { recursive: true })
    .filter((file) => file.endsWith('.ts'))
    .map((file) => [file, readFileSync(`${ELEMENTS}/${file}`, 'utf8')]);
}

test('every tag in the Tag enum is registered after main.js loads', () => {
  const missing = Object.entries(Tag)
    .filter(([, tag]) => customElements.get(tag) === undefined)
    .map(([name, tag]) => `<${tag}> (Tag.${name})`);

  assert.deepEqual(missing, [], 'missing from main.ts\'s import list');
});

test('nothing registers a tag the Tag enum does not name', () => {
  const known = new Set(Object.keys(Tag));
  const wrong = [];

  for (const [file, source] of modules()) {
    for (const [, name] of source.matchAll(/customElements\.define\(Tag\.(\w+)/g)) {
      if (!known.has(name)) wrong.push(`${file} registers Tag.${name}`);
    }
    // A literal here would sidestep the enum entirely, which is the mistake worth naming.
    if (/customElements\.define\(['"`]/.test(source)) wrong.push(`${file} registers a bare string`);
  }

  assert.deepEqual(wrong, []);
});

test('one class per file: no module registers two tags', () => {
  const files = modules()
    .map(([file, source]) => [file, [...source.matchAll(/customElements\.define\(/g)].length])
    .filter(([, count]) => count > 1)
    .map(([file, count]) => `${file} registers ${count}`);

  assert.deepEqual(files, []);
});

test('a module is named for the class it defines', () => {
  const wrong = modules()
    .filter(([file, source]) => {
      const name = file.split('/').pop().replace(/\.ts$/, '');

      return !new RegExp(`export (abstract )?class ${name}\\b`).test(source);
    })
    .map(([file]) => file);

  assert.deepEqual(wrong, []);
});
