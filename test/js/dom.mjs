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
  'CSSStyleDeclaration', 'getComputedStyle', 'history', 'location',
]) {
  globalThis[name] = name === 'window' ? dom.window : dom.window[name];
}

/**
 * A 2D context that records instead of rasterising.
 *
 * jsdom's HTMLCanvasElement.getContext() answers **null** unless the native `canvas` package is
 * installed, which is a C++ build against cairo and pango — a real dependency for a project whose
 * whole front end has none. So <demo-waveform> would paint nothing here, and the coverage gate,
 * which is 100% and not a report, would have no way to reach the drawing at all.
 *
 * Recording is the better test anyway, not merely the cheaper one: what is worth asserting about a
 * waveform is where the bars are, how tall, in which colour and at which opacity — decisions, not
 * pixels. A real canvas would answer those questions only by being read back as an image.
 *
 * fillStyle and globalAlpha are captured *per call*, since both are set before each rect and the
 * element's whole played/unplayed distinction is in the alpha.
 */
function recordingContext() {
  const calls = [];
  const state = { fillStyle: '', globalAlpha: 1 };

  return {
    calls,
    get fillStyle() { return state.fillStyle; },
    set fillStyle(value) { state.fillStyle = value; },
    get globalAlpha() { return state.globalAlpha; },
    set globalAlpha(value) { state.globalAlpha = value; },
    setTransform: (...args) => calls.push(['setTransform', ...args]),
    clearRect: (...args) => calls.push(['clearRect', ...args]),
    fillRect: (x, y, width, height) =>
      calls.push(['fillRect', x, y, width, height, state.fillStyle, state.globalAlpha]),
  };
}

const CONTEXT = Symbol('2d');

dom.window.HTMLCanvasElement.prototype.getContext = function getContext(kind) {
  // Anything but '2d' keeps jsdom's own answer, so an element asking for a context it will not get
  // still has to handle the null.
  if (kind !== '2d') return null;

  this[CONTEXT] ??= recordingContext();

  return this[CONTEXT];
};

/** What was drawn on a canvas, in order: ['fillRect', x, y, w, h, fillStyle, globalAlpha]. */
export const drawnOn = (canvas) => canvas.getContext('2d').calls;

/**
 * ResizeObserver, which jsdom does not implement at all.
 *
 * <demo-waveform> watches its own box rather than the window's, because connectedCallback runs
 * before a native <audio> control has been laid out and the canvas would otherwise be sized to a
 * card that does not exist yet. There is no layout here to observe, so the stub keeps the observed
 * elements and `resize()` below fires them — which is the same explicitness the sized() helper has:
 * a test says when the box changed, because nothing else can.
 */
const OBSERVED = new Set();

dom.window.ResizeObserver = class ResizeObserver {
  constructor(callback) { this.callback = callback; }
  observe(element) { OBSERVED.add(this); this.callback([{ target: element }], this); }
  disconnect() { OBSERVED.delete(this); }
};

globalThis.ResizeObserver = dom.window.ResizeObserver;

/** Tells every live observer its box changed — the stub's stand-in for a layout pass. */
export function resize() {
  for (const observer of OBSERVED) observer.callback([], observer);
}

/**
 * Gives an element a size, because jsdom has no layout and every box is 0×0.
 *
 * <demo-waveform> draws nothing into a box with no width, which is a real branch — a card inside a
 * `display: none` parent — but it is not the one most tests want.
 */
export function sized(element, width, height) {
  Object.defineProperty(element, 'clientWidth', { value: width, configurable: true });
  Object.defineProperty(element, 'clientHeight', { value: height, configurable: true });

  return element;
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
