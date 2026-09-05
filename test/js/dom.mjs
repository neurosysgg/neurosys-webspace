/**
 * A DOM for the element tests.
 *
 * The elements under test are the compiled output in public/assets/js/, the same files the browser
 * loads — not the TypeScript. That way the test exercises what actually ships, and a build that
 * never ran is a failing test rather than a passing one.
 *
 * What gets loaded is main.js — the whole vocabulary through the same entry point the browser
 * uses, rather than a hand-picked module per test. A tag missing from main.ts's import list is then
 * missing here too, which is what test/js/vocabulary.test.mjs asserts.
 *
 * The globals have to exist before that import runs, because every element module calls
 * customElements.define at import time — hence the dynamic import at the bottom rather than a
 * static one, which the engine would hoist above the assignments below.
 */
import { JSDOM } from 'jsdom';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..');

/**
 * Which compiled tree to load. `public/assets/js/` unless something says otherwise, so `npm test`
 * and `npm run coverage` are exactly what they were — the gate is still measured against those
 * paths, and a stamped or copied one would attribute nowhere.
 *
 * The something is the verify script, which points this at `build/dist/` after `build-prod.mjs`
 * has minified it. Every element test imports from this file and the whole vocabulary arrives
 * through the one `main.js` below, so re-running the suite with this set is the real proof that
 * mangling did not break anything — the nesting guards, TerminalWindow's subtree, both embeds and
 * Navigation, all executing the bytes the server will send. Nothing else about the tests changes.
 */
const JS = process.env.NEUROSYS_JS_DIR ?? `${ROOT}/public/assets/js`;

/**
 * The shell Layout.php emits, reduced to the part the scripts look for.
 *
 * <main id="content"> is here because Navigation.forDocument() returns null without it and
 * main.js's `?.start()` then wires nothing — so a DOM without it silently tests the SPA switched
 * off, which is the one state no real page is ever in.
 */
const dom = new JSDOM(
  '<!doctype html><html><body><main id="content"></main></body></html>',
  { url: 'https://neurosys.gg/' },
);

// Element and HTMLAnchorElement are what Navigation narrows a click target with, and history and
// location are what it navigates through. Node defines none of the four, so leaving them out is
// not a smaller DOM — it is a ReferenceError the moment a link is clicked.
for (const name of [
  'window', 'document', 'HTMLElement', 'HTMLAnchorElement', 'Element',
  'customElements', 'DocumentFragment', 'Node', 'Event', 'MouseEvent',
  'CSSStyleDeclaration', 'history', 'location',
]) {
  globalThis[name] = name === 'window' ? dom.window : dom.window[name];
}

/**
 * Runs `fn` and returns whatever it reported as an uncaught error.
 *
 * A throw inside connectedCallback does not reach whoever inserted the element — the browser
 * catches it and reports it, which is why these have to be captured rather than caught. It is
 * still the loud failure we want: it lands in the console with a stack.
 */
export function uncaughtErrors(fn) {
  const errors = [];
  const capture = (event) => { errors.push(event.error ?? new Error(event.message)); event.preventDefault(); };

  dom.window.addEventListener('error', capture);
  try {
    fn();
  } finally {
    dom.window.removeEventListener('error', capture);
  }

  return errors;
}

export { dom, ROOT };

await import(`${JS}/main.js`);
