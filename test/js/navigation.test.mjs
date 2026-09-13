/**
 * Navigation — the SPA router.
 *
 * The one module with no custom element around it, and the one whose failures are quietest. It
 * intercepts a link, fetches the page as a fragment and swaps it into #content; every way that can
 * go wrong leaves a page that still looks fine. A dropped `data-no-spa` swallows the download 303
 * with nothing in the console, a drifted X-Requested-With gets a whole document written into
 * <main>, and a missing #content switches the whole thing off silently.
 *
 * It also stands in for what a page load does for free — the scroll position back and forward
 * return to, focus, and telling a screen reader the page changed — so a swap that works can still
 * leave a visitor in the wrong place, and this file checks where they are left.
 *
 * The Navigation under test is the one main.js started, not one built here — the wiring is part of
 * what is being tested, and there is only ever one document listener in a real page. What start()
 * does with the entry it is loaded on is in navigation-start.test.mjs.
 */
import { test, beforeEach, afterEach } from 'node:test';
import assert from 'node:assert/strict';

import { dom } from './dom.mjs';
import { Navigation } from '../../public/assets/js/phpanta/Navigation.js';

const content = document.getElementById('content');

// ───────────────────────────── the doubles ─────────────────────────────

/** Every fetch Navigation made, in order. */
let requests = [];

/** Every URL it handed back to the browser rather than fetching itself. */
let handedBack = [];

/** Every URL it handed to location.assign() — which should be none; see the afterEach below. */
let assigned = [];

/** What the next fetch resolves to. */
let respond;

/**
 * The fetch double honours init.signal, because Navigation aborts the request a newer navigation
 * replaces — and a stub that ignored the signal would leave that path unexercised while looking
 * exercised.
 */
globalThis.fetch = (url, init) => {
  requests.push({ url, init });

  const response = respond();

  return new Promise((resolve, reject) => {
    init.signal.addEventListener('abort', () => { reject(new Error('aborted')); });
    response.then(resolve, reject);
  });
};

/** A promise this test resolves by hand, so two navigations can be made to overlap. */
function deferred() {
  let settle;
  const promise = new Promise((resolve, reject) => { settle = { resolve, reject }; });

  return { promise, ...settle };
}

/**
 * A response as fetch resolves it: a page's Content-Type unless a test says otherwise, and none at
 * all for a null one.
 *
 * @param {() => Promise<string>} text
 * @param {{ ok?: boolean, type?: string | null }} [options]
 */
function response(text, { ok = true, type = 'text/html; charset=utf-8' } = {}) {
  return { ok, headers: new Headers(type === null ? {} : { 'Content-Type': type }), text };
}

/**
 * @param {string} body
 * @param {{ ok?: boolean, type?: string | null }} [options]
 */
const fragment = (body, options) => () =>
  Promise.resolve(response(() => Promise.resolve(body), options));

const unreachable = () => () => Promise.reject(new TypeError('failed to fetch'));

/**
 * location, with replace() recorded instead of performed.
 *
 * jsdom's own Location refuses to be stubbed — its methods are read-only and non-configurable — and
 * it cannot navigate anyway. Reads still come from the real one, so pushState and back and forward
 * are observable. assign() is here only to be caught using it: see the afterEach below.
 */
const real = dom.window.location;

// Two getters and two methods, because that is every member Navigation touches or must not. The
// cast says the double is deliberately partial rather than inviting more members nothing reads.
globalThis.location = /** @type {Location} */ (/** @type {unknown} */ ({
  get href() { return real.href; },
  get origin() { return real.origin; },
  replace(url) { handedBack.push(url); },
  assign(url) { assigned.push(url); },
}));

/** Every window.scrollTo(x, y), in order. jsdom prints "Not implemented" for it otherwise. */
let scrolls = [];

dom.window.scrollTo = (...at) => { scrolls.push(at); };

/** Every element scrolled into view, in order. jsdom has no scrollIntoView at all. */
let revealed = [];

/** @this {Element} */
dom.window.Element.prototype.scrollIntoView = function scrollIntoView() { revealed.push(this); };

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

/**
 * Says where the page is scrolled. jsdom has no layout, so a test states it — the way sized() in
 * dom.mjs states how big a box is.
 */
function scrolledTo(y) {
  Object.defineProperty(dom.window, 'scrollY', { value: y, configurable: true, writable: true });
}

/**
 * Goes back (-1) or forward (1) the way the browser does — jsdom traverses its own session history
 * and fires popstate — and waits for whatever the arrival started.
 */
async function traverse(delta) {
  const arrived = new Promise((resolve) => {
    window.addEventListener('popstate', resolve, { once: true });
  });

  history.go(delta);
  await arrived;
  await settle();
}

/** A popstate for an entry a test pushed by hand, as the browser fires one arriving on it. */
async function arriveOn(state, url) {
  history.pushState(state, '', url);
  window.dispatchEvent(new dom.window.Event('popstate'));
  await settle();
}

/** The region the new title is announced through. */
const announcer = () =>
  /** @type {HTMLElement} */ (document.querySelector('[aria-live="polite"]'));

beforeEach(() => {
  requests   = [];
  handedBack = [];
  assigned   = [];
  scrolls    = [];
  revealed   = [];
  verdict    = null;
  respond    = fragment('<title>neuro.SYS</title><p>fragment</p>');
  scrolledTo(0);
  content.replaceChildren();
  document.body.querySelectorAll('a').forEach((a) => { a.remove(); });
});

/**
 * Every fallback is location.replace(). The entry for the URL already exists — pushState made it,
 * or back and forward arrived on it — so assign() would put a second one behind it, and back would
 * land the visitor on the URL they had just failed to leave.
 */
afterEach(() => {
  assert.deepEqual(assigned, [], 'a fallback used assign() rather than replace()');
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

test('a link that opens in this page is intercepted, whatever the case of its target', async () => {
  await navigate(link('/releases', { target: '_SELF' }));

  assert.equal(verdict.intercepted, true);
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

// ───────────────────────────── a whole document ─────────────────────────────

/**
 * What a static host sends. It has no X-Requested-With to read, so a static export's page arrives
 * whole — header, footer and all — and only its #content and its title may be taken, or the
 * chrome is written into <main> a second time.
 */
const whole = (head, body) => `<!DOCTYPE html><html lang="en"><head>${head}</head><body>${body}</body></html>`;

test('a whole document gives up only its #content and its title', async () => {
  respond = fragment(whole(
    '<title>Getting started — Phpanta</title>',
    '<header>chrome</header><main id="content"><p>whole</p></main><footer>chrome</footer>',
  ));

  await navigate(link('/phpanta/getting-started'));

  assert.equal(content.innerHTML, '<p>whole</p>');
  assert.equal(document.title, 'Getting started — Phpanta');
  assert.deepEqual(handedBack, []);
});

test("a whole document's title arrives decoded", async () => {
  respond = fragment(whole('<title>rock &amp; roll</title>', '<main id="content"><p>x</p></main>'));

  await navigate(link('/phpanta/x'));

  assert.equal(document.title, 'rock & roll');
});

test('a whole document with no title says so rather than blanking the tab', async () => {
  const warnings = [];
  const warn = console.warn;
  console.warn = (message) => warnings.push(message);

  try {
    respond = fragment(whole('', '<main id="content"><p>untitled</p></main>'));
    document.title = 'unchanged';

    await navigate(link('/phpanta/x'));
  } finally {
    console.warn = warn;
  }

  assert.deepEqual(warnings, ['No title found in HTML response']);
  assert.equal(document.title, 'unchanged');
  assert.equal(content.innerHTML, '<p>untitled</p>');
});

/** Nothing to swap in is not a reason to strand the visitor: the browser can show the page itself. */
test('a whole document with no #content is handed to the browser', async () => {
  respond = fragment(whole('<title>elsewhere</title>', '<p>no main here</p>'));

  await navigate(link('/phpanta/elsewhere'));

  assert.deepEqual(handedBack, ['https://neurosys.gg/phpanta/elsewhere']);
  assert.equal(content.innerHTML, '');
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

/** `download` asks the browser to save the response — nothing a swap could show. */
test('a link with a download attribute is left to the browser', async () => {
  await navigate(link('/releases/ill/flac', { download: '' }));

  assert.equal(verdict.intercepted, false);
  assert.deepEqual(requests, []);
});

test('a link that opens in another window is left to the browser', async () => {
  await navigate(link('/releases', { target: '_blank' }));

  assert.equal(verdict.intercepted, false);
  assert.deepEqual(requests, []);
});

/**
 * A handler of the page's own ran first and cancelled the click, so it has decided what the click
 * does. The verdict reads "cancelled" either way; what shows Navigation stayed out is that nothing
 * was fetched.
 */
test('a click something else has already cancelled is left alone', async () => {
  const a = link('/releases');
  a.addEventListener('click', (event) => { event.preventDefault(); });

  await navigate(a);

  assert.deepEqual(requests, []);
  assert.deepEqual(handedBack, []);
});

/**
 * A fragment of the page already showing is a scroll. The browser moves to it and makes the
 * history entry itself; a fetch would replace the very content it is scrolling through.
 */
test('a fragment of the page already showing is left to the browser', async () => {
  history.pushState(null, '', '/imprint');

  await navigate(link('/imprint#contact'));

  assert.equal(verdict.intercepted, false);
  assert.deepEqual(requests, []);
});

test('an external link is left to the browser', async () => {
  await navigate(link('https://soundcloud.com/neurosysgg'));

  assert.equal(verdict.intercepted, false);
  assert.deepEqual(requests, []);
});

/**
 * The one an `href^="/"` selector cannot tell from a path.
 *
 * `//evil.example/x` starts with a slash exactly as `/releases` does, so it matches INTERNAL_LINK —
 * but the resolved `link.href` everything downstream uses is a different origin, and go() ends in
 * an innerHTML assignment. Before the origin check the outcome was still not a hole, because
 * pushState throws a SecurityError on a cross-origin URL one line later; it was a link that
 * silently did nothing at all. Neither is what should happen, and "it throws slightly later" is
 * not a guarantee anybody can read off the file.
 */
test('a protocol-relative link is left to the browser, selector or no selector', async () => {
  const a = link('//evil.example/x');

  assert.ok(a.matches(`a[href^="/"]`), 'the selector matches it — that is the whole problem');
  assert.notEqual(new URL(a.href).origin, location.origin);

  await navigate(a);

  assert.equal(verdict.intercepted, false);
  assert.deepEqual(requests, []);
  assert.deepEqual(handedBack, []);
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

/** A route can answer with a file. Its bytes are not a page, and #content is no place for them. */
test('a response that is not a page becomes a real navigation', async () => {
  respond = fragment('%PDF-1.7', { type: 'application/pdf' });

  await navigate(link('/files/sheet.pdf'));

  assert.deepEqual(handedBack, ['https://neurosys.gg/files/sheet.pdf']);
  assert.equal(content.innerHTML, '');
});

test('a response that does not say what it is becomes a real navigation', async () => {
  respond = fragment('<p>who knows</p>', { type: null });

  await navigate(link('/files/unknown'));

  assert.deepEqual(handedBack, ['https://neurosys.gg/files/unknown']);
  assert.equal(content.innerHTML, '');
});

/** The type's case is not significant and its parameters say nothing about what it is. */
test('a page is recognised whatever the case and parameters of its type', async () => {
  respond = fragment('<title>t</title><p>shouting</p>', { type: 'Text/HTML ; Charset=UTF-8' });

  await navigate(link('/releases'));

  assert.deepEqual(handedBack, []);
  assert.match(content.innerHTML, /shouting/);
});

// ───────────────────────── back and forward ─────────────────────────

test('back and forward re-fetch the page they land on, query and fragment included', async () => {
  await arriveOn({}, '/imprint?lang=de#contact');

  assert.equal(requests.length, 1);
  assert.equal(requests[0].url, 'https://neurosys.gg/imprint?lang=de#contact');
});

// ───────────────────────── where the visitor is left ─────────────────────────

test('a new page starts at the top', async () => {
  scrolledTo(900);

  await navigate(link('/scroll/new'));

  assert.deepEqual(scrolls, [[0, 0]]);
});

/**
 * What a page load would have done, and the swap has to do by hand now that scroll restoration is
 * this file's: back returns to where the list was left, forward to where the release was.
 */
test('back returns to where a page was left, and forward to where the next one was', async () => {
  respond = fragment('<title>list</title><p>list</p>');
  await navigate(link('/scroll/list'));
  scrolledTo(480);

  respond = fragment('<title>detail</title><p>detail</p>');
  await navigate(link('/scroll/detail'));
  scrolledTo(120);

  respond = fragment('<title>list</title><p>list</p>');
  await traverse(-1);

  assert.equal(real.pathname, '/scroll/list');
  assert.match(content.innerHTML, /list/);
  assert.deepEqual(scrolls.at(-1), [0, 480]);

  scrolledTo(15);
  respond = fragment('<title>detail</title><p>detail</p>');
  await traverse(1);

  assert.equal(real.pathname, '/scroll/detail');
  assert.deepEqual(scrolls.at(-1), [0, 120]);
});

/** A reload empties the memory; the entry itself still says where it was left. */
test('an entry this document never showed is returned to where its state says', async () => {
  await arriveOn({ key: 'from-before-a-reload', scrollY: 300 }, '/scroll/reloaded');

  assert.equal(requests.length, 1);
  assert.deepEqual(scrolls.at(-1), [0, 300]);
});

test('a document being left writes where it was onto its entry, for a reload to find', () => {
  scrolledTo(360);

  window.dispatchEvent(new dom.window.Event('pagehide'));

  assert.equal(history.state.scrollY, 360);
  assert.equal(typeof history.state.key, 'string');
});

/**
 * Back and forward between two fragments of one page change no content. The browser makes a
 * stateless entry for a fragment link this file left alone and fires popstate on it; nothing may be
 * fetched, and the page moves to the fragment — or, going back, to where it was.
 */
test('moving between fragments of one page scrolls and fetches nothing', async () => {
  respond = fragment('<title>parts</title><h2 id="part">part</h2>');
  await navigate(link('/scroll/parts'));
  scrolledTo(40);
  requests = [];

  await arriveOn(null, '/scroll/parts#part');

  assert.deepEqual(requests, []);
  assert.equal(revealed.at(-1)?.id, 'part');

  scrolledTo(700);
  await traverse(-1);

  assert.equal(real.href, 'https://neurosys.gg/scroll/parts');
  assert.deepEqual(requests, []);
  assert.deepEqual(scrolls.at(-1), [0, 40]);
});

test('a fragment of another page is fetched, and scrolled to once it has arrived', async () => {
  respond = fragment('<title>t</title><h2 id="contact">contact</h2>');

  await navigate(link('/scroll/elsewhere#contact'));

  assert.equal(requests.length, 1);
  assert.equal(revealed.at(-1)?.id, 'contact');
  assert.deepEqual(scrolls, []);
});

/** The URL holds a fragment percent-encoded; the id it names does not. */
test('a fragment is found by its decoded name too', async () => {
  respond = fragment('<title>t</title><h2 id="café">café</h2>');

  await navigate(link('/scroll/accents#café'));

  assert.equal(revealed.at(-1)?.id, 'café');
});

test('a fragment that does not decode names nothing, and the page starts at the top', async () => {
  respond = fragment('<title>t</title><h2 id="x">x</h2>');

  await navigate(link('/scroll/broken#%E0%A4%A'));

  assert.deepEqual(revealed, []);
  assert.deepEqual(scrolls, [[0, 0]]);
});

/**
 * A page load puts focus at the top of the document. After a swap it would otherwise stay on the
 * link that was clicked — which is gone — and the next Tab would start from nowhere in particular.
 */
test('the new content takes focus, without becoming a tab stop', async () => {
  await navigate(link('/releases'));

  assert.equal(document.activeElement, content);
  assert.equal(content.tabIndex, -1);
});

/** A page load is announced by the browser; a swap is silent unless something says it. */
test('the new title is announced, politely', async () => {
  respond = fragment('<title>rock &amp; roll</title><p>x</p>');

  await navigate(link('/releases/ill'));

  assert.equal(announcer().textContent, 'rock & roll');
});

/** Hidden from sight only: `hidden` or `display: none` would hide it from a screen reader too. */
test('the announcer is out of sight, but not hidden from assistive technology', () => {
  const region = announcer();

  assert.equal(region.hidden, false);
  assert.notEqual(region.style.display, 'none');
  assert.equal(region.style.position, 'absolute');
  assert.equal(region.style.clipPath, 'inset(50%)');
  assert.equal(document.querySelectorAll('[aria-live]').length, 1, 'one region, however many swaps');
});

// ───────────────────────── the swap notification ─────────────────────────

/**
 * Custom elements do not need this — the browser upgrades what innerHTML brings in. It exists
 * for anything that is not an element, and the name is private so the two halves cannot drift.
 */
test('subscribers are told once the content has been replaced', async () => {
  const seen = [];
  Navigation.onNavigate(() => { seen.push(content.innerHTML); });

  await navigate(link('/releases'));

  assert.equal(seen.length, 1);
  assert.match(seen[0], /<p>fragment<\/p>/, 'fired before the swap, not after');
});

// ───────────────────────── overlapping navigations ─────────────────────────

/*
 * pushState runs before the fetch, so the address bar already says where the *last* click went.
 * Without a guard, whichever response happens to land last writes #content — and a slow first
 * click beating a fast second one leaves the URL and the page disagreeing, silently. Each of the
 * three tests below parks one navigation at a different await and lets a newer one overtake it.
 */

/**
 * The case the AbortController cannot cover, and the reason the counter exists as well as it.
 * A fetch that has already resolved is past aborting — the response is in hand and only the
 * continuation is pending — so a click landing in that gap leaves a settled stale response with
 * nothing but the counter between it and #content. Staged by resolving the first fetch and
 * clicking again in the same tick, before its continuation gets to run.
 */
test('a response resolved just as a newer click starts is dropped', async () => {
  const first = deferred();

  respond = () => first.promise;
  click(link('/releases'));
  await settle();

  respond = fragment('<title>imprint</title><p>second</p>');

  first.resolve(response(() => Promise.resolve('<title>releases</title><p>first</p>')));

  // One microtask, and exactly one: enough for the fetch promise to settle — so the abort below
  // has nothing left to cancel — but not enough for its continuation to run. That gap is the race.
  await Promise.resolve();
  click(link('/imprint'));

  await settle();

  assert.match(content.innerHTML, /second/, 'the stale response overwrote the newer one');
  assert.equal(document.title, 'imprint');
});

test('a body that arrives after a newer click has started is dropped', async () => {
  const body = deferred();

  // The fetch resolves at once; it is reading the body that outlives the navigation.
  respond = () => Promise.resolve(response(() => body.promise));
  click(link('/releases'));
  await settle();

  respond = fragment('<title>imprint</title><p>second</p>');
  await navigate(link('/imprint'));

  body.resolve('<title>releases</title><p>first</p>');
  await settle();

  assert.match(content.innerHTML, /second/, 'the stale body overwrote the newer one');
  assert.equal(document.title, 'imprint');
});

/**
 * The abort is Navigation cancelling itself. Handing that to location.replace() would turn every
 * double-click into a full page load of the URL the visitor already left.
 */
test('the request a newer click cancels is not handed back to the browser', async () => {
  respond = () => deferred().promise;
  click(link('/releases'));
  await settle();

  respond = fragment('<title>imprint</title><p>second</p>');
  await navigate(link('/imprint'));

  assert.deepEqual(handedBack, []);
  assert.match(content.innerHTML, /second/);
  assert.equal(requests.length, 2, 'both navigations should have started a fetch');
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
