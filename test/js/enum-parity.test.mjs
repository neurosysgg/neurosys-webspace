/**
 * The mirrored enums against their PHP originals.
 *
 * assets/ts/model/ is a second copy of facts that live in src/NeuroSYS/Model/, which is exactly the
 * kind of duplication the rest of this codebase refuses to leave unguarded. These compare the two
 * case by case — name, backing value and the accessors the client mirrors — so a case added, removed,
 * renamed or re-valued on one side fails here rather than in a browser.
 *
 * Order is compared, not just membership: SoundCloudEmbed and SoundCloudPlayer both build the widget
 * query string by iterating the cases, so the declaration order is the rendered order.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

import { Platform, displayName } from '../../public/assets/js/model/Platform.js';
import { SoundCloudOption } from '../../public/assets/js/model/SoundCloudOption.js';
import { SoundCloudPlayerStyle, isVisual } from '../../public/assets/js/model/SoundCloudPlayerStyle.js';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..');

/** Runs a PHP snippet against the real autoloader and parses what it echoes as JSON. */
function php(code) {
  return JSON.parse(execFileSync(
    'php',
    ['-r', `require '${ROOT}/autoload.php'; ${code}`],
    { encoding: 'utf8' },
  ));
}

/** A TS string enum compiles to a plain object, so its entries are its cases in declaration order. */
const cases = (mirror) => Object.entries(mirror);

test('Platform mirrors NeuroSYS\\Model\\Platform, including displayName()', () => {
  assert.deepEqual(
    cases(Platform).map(([name, value]) => [name, value, displayName(value)]),
    php(`echo json_encode(array_map(
        fn ($c) => [$c->name, $c->value, $c->displayName()],
        NeuroSYS\\Model\\Platform::cases(),
    ));`),
  );
});

test('SoundCloudOption mirrors its PHP enum, in the order the query string is built', () => {
  assert.deepEqual(
    cases(SoundCloudOption),
    php(`echo json_encode(array_map(
        fn ($c) => [$c->name, $c->value],
        NeuroSYS\\Model\\Embed\\SoundCloudOption::cases(),
    ));`),
  );
});

test('SoundCloudPlayerStyle mirrors its PHP enum, including isVisual()', () => {
  assert.deepEqual(
    cases(SoundCloudPlayerStyle).map(([name, value]) => [name, value, isVisual(value)]),
    php(`echo json_encode(array_map(
        fn ($c) => [$c->name, $c->value, $c->isVisual()],
        NeuroSYS\\Model\\Embed\\SoundCloudPlayerStyle::cases(),
    ));`),
  );
});
