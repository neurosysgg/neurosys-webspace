/**
 * A DOM for the element tests.
 *
 * The elements under test are the compiled output in public/assets/js/, the same files the browser
 * loads — not the TypeScript. That way the test exercises what actually ships, and a build that
 * never ran is a failing test rather than a passing one.
 *
 * The globals have to exist before the element modules are imported, because each one calls
 * customElements.define at import time.
 */
import { JSDOM } from 'jsdom';

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

export { dom };
