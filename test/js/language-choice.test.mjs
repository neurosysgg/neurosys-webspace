/**
 * LanguageChoice — which language a visitor lands in, on a host that cannot choose for them.
 *
 * The framework's module, tested here because it is compiled into this site's tree, where the
 * coverage gate reads every module whether a page imports it or not. This site negotiates on its
 * server and states no alternates, so on its own pages forDocument() is null and none of this runs;
 * the framework's own site, exported to GitHub Pages, is where it does.
 *
 * Every way it can go wrong is a visitor in the wrong language with nothing in any console — sent
 * away from a page whose address said which language it meant, sent round in a circle, or sent to
 * their browser's language on every visit after they chose another. Each is pinned below.
 */
import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';

import { dom } from './dom.mjs';
import { LanguageChoice } from '../../public/assets/js/phpanta/LanguageChoice.js';

// ───────────────────────────── the doubles ─────────────────────────────

/** Where the page is, as a test says: its path and its fragment. */
let at = { pathname: '/rules', hash: '' };

/** Every URL the visitor was sent to. */
let replaced = [];

// Three members, because that is every one LanguageChoice reads. jsdom's own Location cannot
// navigate and refuses to be stubbed; see navigation.test.mjs.
globalThis.location = /** @type {Location} */ (/** @type {unknown} */ ({
  get pathname() { return at.pathname; },
  get hash() { return at.hash; },
  replace(url) { replaced.push(url); },
}));

/** What storage holds, and whether it refuses every call — a private window, or blocked storage. */
const stored = new Map();
let refusing = false;

Object.defineProperty(globalThis, 'localStorage', {
  configurable: true,
  value: {
    getItem(key) {
      if (refusing) throw new Error('storage is refused');

      return stored.has(key) ? stored.get(key) : null;
    },
    setItem(key, value) {
      if (refusing) throw new Error('storage is refused');

      stored.set(key, String(value));
    },
  },
});

/** The languages the browser asks for, in its order. */
let browser = [];

Object.defineProperty(globalThis, 'navigator', {
  configurable: true,
  value: { get languages() { return browser; } },
});

/** The storage keys, as the module spells them. Private there; written out here, as a test does. */
const CHOSEN = 'phpanta:language';
const FOLLOWED = 'phpanta:language-followed';

// A switch is a real link; clicking one in jsdom would print "Not implemented: navigation".
document.addEventListener('click', (event) => { event.preventDefault(); });

// ───────────────────────────── the helpers ─────────────────────────────

/**
 * States the page's address in each language, as the head of an exported page does.
 *
 * @param {...[string, string]} addresses
 */
function offers(...addresses) {
  for (const [language, href] of addresses) {
    const link = document.createElement('link');

    link.rel = 'alternate';
    link.hreflang = language;
    link.href = href;
    document.head.append(link);
  }
}

/** The rules page, in English and German. */
const RULES = /** @type {[string, string][]} */ ([['en', '/rules.en.html'], ['de', '/rules.de.html']]);

/** Starts a choice for the document as the test has left it. */
function start() {
  const choice = LanguageChoice.forDocument();

  assert.ok(choice !== null, 'a page in two languages has a choice');
  choice.start();
}

/**
 * A link that names a language, clicked.
 *
 * @param {string} language
 */
function choose(language) {
  const a = document.createElement('a');

  a.setAttribute('href', `/rules.${language}.html`);
  a.setAttribute('hreflang', language);
  document.body.append(a);
  a.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
}

beforeEach(() => {
  at       = { pathname: '/rules', hash: '' };
  replaced = [];
  browser  = [];
  refusing = false;
  stored.clear();
  document.head.querySelectorAll('link').forEach((link) => { link.remove(); });
  document.body.querySelectorAll('a, p').forEach((node) => { node.remove(); });
  document.documentElement.lang = 'en';
});

// ───────────────────────────── whether there is a choice ─────────────────────────────

/** A page in one language, or a site that negotiates on its server, has nothing to choose between. */
test('a page that states fewer than two languages has no choice', () => {
  assert.equal(LanguageChoice.forDocument(), null);

  offers(['en', '/rules.en.html']);

  assert.equal(LanguageChoice.forDocument(), null);
});

// ───────────────────────────── where the visitor lands ─────────────────────────────

/**
 * An address that names its language was a link to that language, and whoever shared it meant it.
 * It is also what keeps this from sending anybody in a circle: every address it sends a visitor to
 * names its language.
 */
test('an address that names its language is never redirected', () => {
  offers(...RULES);
  at = { pathname: '/rules.de.html', hash: '' };
  document.documentElement.lang = 'de';
  stored.set(CHOSEN, 'en');
  browser = ['en-GB'];

  start();

  assert.deepEqual(replaced, []);
  assert.equal(stored.has(FOLLOWED), false, 'the browser was asked on a page that needed no answer');
});

test('a plain address goes to the language the visitor chose', () => {
  offers(...RULES);
  stored.set(CHOSEN, 'de');
  browser = ['en'];

  start();

  assert.deepEqual(replaced, ['https://neurosys.gg/rules.de.html']);
});

/**
 * What Accept-Language would have done — once. A visitor who goes back to the default language
 * afterwards must be left there, not sent back on every plain address they open.
 */
test('the browser language is followed once, and a choice the page does not offer is none', () => {
  offers(...RULES);
  stored.set(CHOSEN, 'nl');
  browser = ['de-DE', 'en'];

  start();

  assert.deepEqual(replaced, ['https://neurosys.gg/rules.de.html']);
  assert.equal(stored.get(FOLLOWED), '1');

  start();

  assert.deepEqual(replaced, ['https://neurosys.gg/rules.de.html'], 'the browser was followed twice');
});

test('a browser language is matched by its primary subtag, whatever its case', () => {
  offers(...RULES);
  browser = ['fr-FR', 'DE-at'];

  start();

  assert.deepEqual(replaced, ['https://neurosys.gg/rules.de.html']);
});

test('a browser that asks for nothing the page offers leaves the visitor where they are', () => {
  offers(...RULES);
  browser = ['ja-JP'];

  start();

  assert.deepEqual(replaced, []);
  assert.equal(stored.get(FOLLOWED), '1');
});

test('the language already showing is not a reason to go anywhere', () => {
  offers(...RULES);
  browser = ['en-GB'];

  start();

  assert.deepEqual(replaced, []);
});

test('the fragment the visitor asked for comes along', () => {
  offers(...RULES);
  at = { pathname: '/rules', hash: '#five-habits' };
  stored.set(CHOSEN, 'de');

  start();

  assert.deepEqual(replaced, ['https://neurosys.gg/rules.de.html#five-habits']);
});

// ───────────────────────────── the switch ─────────────────────────────

/** A click on the switch is a choice, kept for the next plain address the visitor opens. */
test('a click on the switch is remembered', () => {
  offers(...RULES);
  at = { pathname: '/rules.en.html', hash: '' };
  start();

  choose('de');

  assert.equal(stored.get(CHOSEN), 'de');
});

test('a click that is not on the switch, or names a language the page is not in, is no choice', () => {
  offers(...RULES);
  at = { pathname: '/rules.en.html', hash: '' };
  start();

  const p = document.createElement('p');
  document.body.append(p);
  p.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
  document.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
  choose('nl');

  assert.equal(stored.has(CHOSEN), false);
});

// ───────────────────────────── storage that refuses ─────────────────────────────

/**
 * A private window, or a site whose storage is blocked. Nothing breaks: the switch still works as a
 * link, and a plain address still follows the browser — every time, since there is nowhere to
 * remember that it did.
 */
test('storage that refuses breaks nothing', () => {
  offers(...RULES);
  refusing = true;
  browser = ['de'];

  start();
  choose('de');

  assert.deepEqual(replaced, ['https://neurosys.gg/rules.de.html']);
  assert.equal(stored.size, 0);
});
