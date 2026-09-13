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

/** Every element scrolled into view, in order. jsdom has no scrollIntoView at all. */
let revealed = [];

dom.window.Element.prototype.scrollIntoView = function scrollIntoView() { revealed.push(this); };

beforeEach(() => { revealed = []; });

/**
 * Runs `body` with the document on `#anchored`, an element of that id on the page, and the entry
 * holding `state` — then puts the address and the page back as they were.
 */
function onFragment(state, body) {
  const anchored = document.createElement('h2');
  const before = location.pathname + location.search;

  anchored.id = 'anchored';
  document.body.append(anchored);
  history.replaceState(state, '', '#anchored');

  try {
    body(anchored);
  } finally {
    anchored.remove();
    history.replaceState(history.state, '', before);
  }
}

/**
 * A heading's own link, reloaded. Manual restoration tells the browser to leave the scroll alone on
 * a reload, and it then skips the fragment as well — so with no position on the entry, the element
 * the fragment names is this file's to scroll to, as it is after a swap.
 */
test('a document loaded on a fragment, with no position left, lands on the element it names', () => {
  onFragment({ key: 'anchor' }, (anchored) => {
    start();

    assert.deepEqual(revealed, [anchored]);
    assert.deepEqual(scrolls, []);
  });
});

test('where an entry was left wins over the fragment it names, as a reload of any page would', () => {
  onFragment({ key: 'anchor-left', scrollY: 120 }, () => {
    start();

    assert.deepEqual(scrolls, [[0, 120]]);
    assert.deepEqual(revealed, []);
  });
});

test('the browser is told the scroll is restored here', () => {
  start();

  assert.equal(history.scrollRestoration, 'manual');
});
