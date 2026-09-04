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

const dom = new JSDOM('<!doctype html><html><body></body></html>', { url: 'https://neurosys.gg/' });

for (const name of [
  'window', 'document', 'HTMLElement', 'customElements', 'DocumentFragment',
  'Node', 'Event', 'MouseEvent', 'CSSStyleDeclaration',
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

await import(`${ROOT}/public/assets/js/main.js`);
