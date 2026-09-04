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

export { dom };
