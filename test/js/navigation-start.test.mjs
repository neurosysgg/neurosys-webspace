/**
 * Navigation.start() — what a document does with the history entry it was loaded on.
 *
 * In a file of its own because it has to start a second router: the one main.js started has already
 * read its entry, and a second one in navigation.test.mjs would intercept every click there twice.
 * Nothing here clicks, so the two listening side by side changes nothing this file asserts.
 */
import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';

import { dom } from './dom.mjs';
import { Navigation } from '../../public/assets/js/phpanta/Navigation.js';

/** Every window.scrollTo(x, y), in order. */
let scrolls = [];

dom.window.scrollTo = (...at) => { scrolls.push(at); };

beforeEach(() => { scrolls = []; });

/** Starts a router on the entry as the test left it. */
function start() {
  const navigation = Navigation.forDocument();

  assert.ok(navigation !== null);
  navigation.start();
}

/**
 * A reload, or a return from another site, lands on the entry pagehide wrote on the way out. The
 * browser would have restored that itself; scroll restoration is this file's now, so it has to.
 */
test('a document loaded on an entry it left lands where it was left', () => {
  history.replaceState({ key: 'left', scrollY: 420 }, '');

  start();

  assert.deepEqual(scrolls, [[0, 420]]);
  assert.equal(history.state.key, 'left', 'the entry keeps the key it had');
});

test('an entry left before it was ever scrolled keeps its key and moves nothing', () => {
  history.replaceState({ key: 'unscrolled' }, '');

  start();

  assert.deepEqual(scrolls, []);
  assert.equal(history.state.key, 'unscrolled');
});

/**
 * State some other script wrote is not a position, whatever it holds.
 *
 * @type {Array<[string, unknown]>}
 */
const FOREIGN = [['a string', 'not ours'], ['no key', { scrollY: 99 }]];

test('an entry this file did not write is given a key of its own, and nothing moves', async (t) => {
  for (const [label, state] of FOREIGN) {
    await t.test(label, () => {
      scrolls = [];
      history.replaceState(state, '');

      start();

      assert.deepEqual(scrolls, []);
      assert.equal(typeof history.state.key, 'string');
      assert.equal(history.state.scrollY, undefined);
    });
  }
});

test('the browser is told the scroll is restored here', () => {
  start();

  assert.equal(history.scrollRestoration, 'manual');
});
