/**
 * Navigation — the SPA router.
 *
 * The one module with no custom element around it, and the one whose failures are quietest. It
 * intercepts a link, fetches the page as a fragment and swaps it into #content; every way that can
 * go wrong leaves a page that still looks fine. A dropped `data-no-spa` swallows the download 303
 * with nothing in the console, a drifted X-Requested-With gets a whole document written into
 * <main>, and a missing #content switches the whole thing off silently.
 *
 * The Navigation under test is the one main.js started, not one built here — the wiring is part of
 * what is being tested, and there is only ever one document listener in a real page.
 */
import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';

import { dom } from './dom.mjs';
import { Navigation } from '../../public/assets/js/Navigation.js';

const content = document.getElementById('content');

// ───────────────────────────── the doubles ─────────────────────────────

/** Every fetch Navigation made, in order. */
let requests = [];

/** Every URL it handed back to the browser rather than fetching itself. */
let handedBack = [];

/** What the next fetch resolves to. */
let respond;

globalThis.fetch = (url, init) => { requests.push({ url, init }); return respond(); };

const fragment = (body, { ok = true } = {}) => () =>
  Promise.resolve({ ok, text: () => Promise.resolve(body) });

const unreachable = () => () => Promise.reject(new TypeError('failed to fetch'));

/**
 * location, with assign() recorded instead of performed.
 *
 * jsdom's own Location refuses to be stubbed — assign is read-only and non-configurable — and it
 * cannot navigate anyway. Reads still come from the real one, so pushState is observable.
 */
const real = dom.window.location;

globalThis.location = {
  get href() { return real.href; },
  get pathname() { return real.pathname; },
  assign(url) { handedBack.push(url); },
};

// jsdom prints "Not implemented" for this on every successful navigation.
dom.window.scrollTo = () => {};

/**
 * What Navigation decided about the last click, read before jsdom acts on it.
 *
 * Registered after main.js's, so it runs second: the verdict is recorded as Navigation left it,
 * and the default is then cancelled so jsdom does not print "Not implemented: navigation" for
 * every link this file leaves alone.
 */
let verdict = null;

document.addEventListener('click', (event) => {
  verdict = { intercepted: event.defaultPrevented };
  event.preventDefault();
});

// ───────────────────────────── the helpers ─────────────────────────────

/** Lets the click handler's fetch and its two awaits resolve. */
const settle = () => new Promise((resolve) => { setTimeout(resolve, 0); });

function link(href, attributes = {}) {
  const a = document.createElement('a');

  a.setAttribute('href', href);
  a.textContent = 'go';
  for (const [name, value] of Object.entries(attributes)) a.setAttribute(name, value);
  document.body.append(a);

  return a;
}

function click(node, init = {}) {
  node.dispatchEvent(
    new dom.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ...init }),
  );
}

/** Clicks and waits for whatever it started to finish. */
async function navigate(node, init = {}) {
  click(node, init);
  await settle();
}

beforeEach(() => {
  requests   = [];
  handedBack = [];
  verdict    = null;
  respond    = fragment('<title>neuro.SYS</title><p>fragment</p>');
  content.replaceChildren();
  document.body.querySelectorAll('a').forEach((a) => { a.remove(); });
});

// ───────────────────────────── interception ─────────────────────────────

test('an internal link is fetched as a fragment and swapped into #content', async () => {
  await navigate(link('/releases'));

  assert.equal(verdict.intercepted, true);
  assert.equal(requests.length, 1);
  assert.equal(requests[0].url, 'https://neurosys.gg/releases');
  assert.match(content.innerHTML, /<p>fragment<\/p>/);
});

/**
 * The header is the entire signal for a fragment response. If it drifts on either side the server
 * answers with a whole document and the line below writes <!DOCTYPE html><html>… into <main> — a
 * page broken in a way nothing reports. The names are mirrored enums for exactly this reason.
 */
test('the fetch asks for a fragment, with credentials', async () => {
  await navigate(link('/releases'));

  assert.equal(requests[0].init.headers['X-Requested-With'], 'XMLHttpRequest');
  assert.equal(requests[0].init.credentials, 'same-origin');
});

test('the address bar is updated before the fetch, so back works', async () => {
  await navigate(link('/releases/ill'));

  assert.equal(real.pathname, '/releases/ill');
});

test('a click on something inside the link still navigates', async () => {
  const a = link('/releases');
  const span = document.createElement('span');
  a.append(span);

  await navigate(span);

  assert.equal(requests.length, 1);
});

// ───────────────────────────── the title ─────────────────────────────

/**
 * ViewResponse escapes the fragment's <title>, so it has to be decoded on the way to document.title
 * or a track called "rock & roll" shows up in the tab as "rock &amp; roll".
 */
test('the fragment title is decoded into document.title', async () => {
  respond = fragment('<title>rock &amp; roll — neuro.SYS</title><p>x</p>');

  await navigate(link('/releases/ill'));

  assert.equal(document.title, 'rock & roll — neuro.SYS');
});

test('the title is taken out of what lands in #content', async () => {
  respond = fragment('<title>neuro.SYS</title><p>body</p>');

  await navigate(link('/releases'));

  assert.equal(content.innerHTML.includes('<title>'), false);
  assert.equal(content.innerHTML.trim(), '<p>body</p>');
});

/** The regex is deliberately not /g: it reads the title and then strips it, two calls. */
test('a fragment whose body mentions a title tag still strips only the leading one', async () => {
  respond = fragment('<title>neuro.SYS</title><p>&lt;title&gt;</p>');

  await navigate(link('/releases'));

  assert.equal(content.innerHTML.trim(), '<p>&lt;title&gt;</p>');
});

test('a fragment with no title says so rather than blanking the tab', async () => {
  const warnings = [];
  const warn = console.warn;
  console.warn = (message) => warnings.push(message);

  try {
    respond = fragment('<p>no title here</p>');
    document.title = 'unchanged';

    await navigate(link('/releases'));
  } finally {
    console.warn = warn;
  }

  assert.deepEqual(warnings, ['No title found in HTML response']);
  assert.equal(document.title, 'unchanged');
  assert.match(content.innerHTML, /no title here/);
});

// ───────────────────────── what it must not intercept ─────────────────────────

/**
 * The one that breaks downloads. Without data-no-spa the 303 is consumed by fetch and nothing
 * reaches the file host, while every page still looks exactly right.
 */
test('a download link is left to the browser', async () => {
  await navigate(link('/releases/ill/flac', { 'data-no-spa': '' }));

  assert.equal(verdict.intercepted, false);
  assert.deepEqual(requests, []);
});

test('an external link is left to the browser', async () => {
  await navigate(link('https://soundcloud.com/neurosysgg'));

  assert.equal(verdict.intercepted, false);
  assert.deepEqual(requests, []);
});

/** Open-in-new-tab and open-in-new-window are the browser's, not ours. */
test('a modified click is left to the browser', async (t) => {
  for (const modifier of ['metaKey', 'ctrlKey', 'shiftKey', 'altKey']) {
    await t.test(modifier, async () => {
      requests = [];
      await navigate(link('/releases'), { [modifier]: true });

      assert.equal(verdict.intercepted, false);
      assert.deepEqual(requests, []);
    });
  }
});

test('a middle click is left to the browser', async () => {
  await navigate(link('/releases'), { button: 1 });

  assert.equal(verdict.intercepted, false);
  assert.deepEqual(requests, []);
});

/** A click can land on the document itself, and EventTarget has no closest(). */
test('a click on nothing in particular does not throw', async () => {
  document.dispatchEvent(
    new dom.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }),
  );
  await settle();

  assert.deepEqual(requests, []);
});

test('a click that hits no link at all is left alone', async () => {
  const p = document.createElement('p');
  document.body.append(p);

  await navigate(p);

  assert.deepEqual(requests, []);
  p.remove();
});

// ───────────────────────── handing it back ─────────────────────────

/**
 * pushState has already run by the time the response arrives, so the address bar points at a page
 * the visitor never got. Handing the navigation to the browser is what gets them there.
 */
test('a response that is not ok becomes a real navigation', async () => {
  respond = fragment('<h1>404</h1>', { ok: false });

  await navigate(link('/nope'));

  assert.deepEqual(handedBack, ['https://neurosys.gg/nope']);
  assert.equal(content.innerHTML, '');
});

test('a fetch that never arrives becomes a real navigation', async () => {
  respond = unreachable();

  await navigate(link('/releases'));

  assert.deepEqual(handedBack, ['https://neurosys.gg/releases']);
  assert.equal(content.innerHTML, '');
});

// ───────────────────────── back and forward ─────────────────────────

test('back and forward re-fetch the path they land on', async () => {
  history.pushState({}, '', '/imprint');

  window.dispatchEvent(new dom.window.Event('popstate'));
  await settle();

  assert.equal(requests.length, 1);
  assert.equal(requests[0].url, '/imprint');
});

// ───────────────────────── the swap notification ─────────────────────────

/**
 * Custom elements no longer need this — the browser upgrades what innerHTML brings in. It stays
 * for anything that is not an element, and the name is private so the two halves cannot drift.
 */
test('subscribers are told once the content has been replaced', async () => {
  const seen = [];
  Navigation.onNavigate(() => { seen.push(content.innerHTML); });

  await navigate(link('/releases'));

  assert.equal(seen.length, 1);
  assert.match(seen[0], /<p>fragment<\/p>/, 'fired before the swap, not after');
});

// ───────────────────────── switching itself off ─────────────────────────

/**
 * No #content means no swap target, and returning null rather than throwing is what makes that
 * safe: with nothing registered every link stays a plain href and the browser does the navigating.
 */
test('there is no router on a page with no #content', () => {
  content.remove();

  try {
    assert.equal(Navigation.forDocument(), null);
  } finally {
    document.body.append(content);
  }
});

test('there is one on a page that has it', () => {
  assert.ok(Navigation.forDocument() instanceof Navigation);
});
