/**
 * <demo-waveform peaks duration> — the waveform behind a demo's player.
 *
 * Three things are worth pinning here and none of them is "it draws something".
 *
 * - **It leaves the card alone.** The children are the server's — a label, a duration and a native
 *   <audio> — and the element only prepends a canvas. That is what keeps the demo page's exception
 *   to CLAUDE.md's no-JS rule intact: the player is native and stays native, and the waveform is
 *   allowed to be absent because it is decoration.
 * - **The byte layout is read the way it was written.** A column is level, low, mid, high, and the
 *   bands stack outward from the centre in that order. Get an offset wrong and the picture is drawn
 *   in the wrong colours, which reads as a design choice rather than as a bug.
 *   The colours come from the stylesheet, so what is asserted is which property fed which bar.
 * - **A waveform it cannot read is no waveform.** Every demo staged before the format existed is in
 *   that state, and so is a mix whose master would not decode, so it has to be ordinary.
 *
 * The canvas is a recording context — see dom.mjs — so these assert what was drawn rather than what
 * it looked like.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { drawnOn, resize, sized } from './dom.mjs';

const WIDTH = 512;
const HEIGHT = 100;

/**
 * How much of the half-height the loudest column reaches — DemoWaveform.REACH.
 *
 * Stated here as well because it is a claim about the card and not only about the drawing: the
 * label row occupies the top quarter of a card at its minimum height, and this is what keeps the
 * brightest tips out from behind it. The test below pins the number; the geometry tests use it.
 */
const REACH = 0.52;

const LOW = '#111111';
const MID = '#222222';
const HIGH = '#333333';

/** base64 of one byte quadruple per column, the way Waveform::base64() writes them. */
const peaks = (columns) => Buffer.from(columns.flat()).toString('base64');

/**
 * A card the way DemoView emits one: the element carries the attributes and wraps the children.
 *
 * Given a size and a bounding box, because jsdom has no layout and every box is otherwise 0×0.
 */
function card({ peaks: encoded, duration = '160', audio = true, styled = true } = {}) {
  const el = document.createElement('demo-waveform');

  if (encoded !== undefined) el.setAttribute('peaks', encoded);
  if (duration !== null) el.setAttribute('duration', duration);

  if (styled) {
    el.style.setProperty('--wave-low', LOW);
    el.style.setProperty('--wave-mid', MID);
    el.style.setProperty('--wave-high', HIGH);
  }

  const label = document.createElement('span');
  label.textContent = 'v4';
  el.append(label);

  if (audio) el.append(document.createElement('audio'));

  sized(el, WIDTH, HEIGHT);
  document.body.append(el);

  const canvas = el.querySelector('canvas');

  if (canvas !== null) {
    canvas.getBoundingClientRect = () => ({ left: 0, top: 0, width: WIDTH, height: HEIGHT });
  }

  return el;
}

/**
 * The rects of the most recent repaint.
 *
 * The recording context keeps every call the canvas ever received, and the element repaints on
 * every timeupdate — so a test that dispatched one is looking at two frames unless it says which.
 * Each paint opens with a clearRect, which is what splits them.
 */
function rects(el) {
  const calls = drawnOn(el.querySelector('canvas'));
  const last = calls.map(([call]) => call).lastIndexOf('clearRect');

  return calls.slice(last + 1).filter(([call]) => call === 'fillRect');
}

/** Canvas coordinates are floats and a stacked edge accumulates a femtopixel of drift. */
const round = (value) => Math.round(value * 1e6) / 1e6;

// ───────────────────────────── what it builds ─────────────────────────────

test('it prepends a canvas and leaves the card the server wrote alone', () => {
  const el = card({ peaks: peaks([[255, 255, 0, 0]]) });

  assert.equal(el.children[0].tagName.toLowerCase(), 'canvas');
  assert.equal(el.querySelector('span').textContent, 'v4');
  assert.equal(el.querySelector('audio') !== null, true);
  assert.equal(el.querySelectorAll('canvas').length, 1);
});

test('the backing store is sized in device pixels and the drawing is in CSS pixels', () => {
  const el = card({ peaks: peaks([[255, 255, 0, 0]]) });
  const canvas = el.querySelector('canvas');

  assert.equal(canvas.width, WIDTH * window.devicePixelRatio);
  assert.deepEqual(
    drawnOn(canvas).find(([call]) => call === 'setTransform'),
    ['setTransform', window.devicePixelRatio, 0, 0, window.devicePixelRatio, 0, 0],
  );
});

// ───────────────────────────── the byte layout ─────────────────────────────

/**
 * One column, one band: a slice that is nothing but sub-bass fills the whole of its own height in
 * the low colour, half above the middle and half below.
 */
test('a column of pure low draws one mirrored pair in the low colour', () => {
  const el = card({ peaks: peaks([[255, 255, 0, 0]]) });

  const reach = (HEIGHT / 2) * REACH;

  assert.deepEqual(rects(el), [
    ['fillRect', 0, HEIGHT / 2 - reach, WIDTH, reach, LOW, 0.3],
    ['fillRect', 0, HEIGHT / 2, WIDTH, reach, LOW, 0.3],
  ]);
});

/**
 * The headroom itself, which is a claim about the card rather than about the drawing.
 *
 * A card at its minimum height puts the label row in the top quarter of it and the native control
 * in the bottom half. A waveform that used the whole half-height would put its brightest tips
 * behind that text — measured at 3.26:1 against it once a track had been played through, under
 * AA's 4.5. Half of the half-height clears the row exactly, which is why the number is what it is.
 */
test('the loudest column leaves the top and bottom of the card clear', () => {
  const drawn = rects(card({ peaks: peaks([[255, 255, 0, 0]]) }));
  const top = Math.min(...drawn.map(([, , y]) => y));
  const bottom = Math.max(...drawn.map(([, , y, , height]) => y + height));

  assert.equal(round(top / HEIGHT), round(0.5 - REACH / 2));
  assert.equal(round(bottom / HEIGHT), round(0.5 + REACH / 2));
});

/** The level is the height and the bands are only shares of it, so a quiet column is short. */
test('the level byte is the height and nothing else', () => {
  const half = rects(card({ peaks: peaks([[128, 255, 0, 0]]) }));

  assert.equal(half[0][4], (128 / 255) * (HEIGHT / 2) * REACH);
});

/**
 * The order is the format: low nearest the middle, high at the tips. Equal bands split the height
 * three ways, and the colour of each third says which byte it was read from.
 */
test('the bands stack outward from the centre, low first', () => {
  const upper = rects(card({ peaks: peaks([[255, 90, 90, 90]]) })).filter(([, , y]) => y < HEIGHT / 2);
  const third = ((HEIGHT / 2) * REACH) / 3;

  assert.deepEqual(upper.map(([, , y, , height, colour]) => [round(y), round(height), colour]), [
    [round(HEIGHT / 2 - third), round(third), LOW],
    [round(HEIGHT / 2 - third * 2), round(third), MID],
    [round(HEIGHT / 2 - third * 3), round(third), HIGH],
  ]);
});

test('a silent column draws nothing rather than dividing by a total of zero', () => {
  assert.deepEqual(rects(card({ peaks: peaks([[200, 0, 0, 0]]) })), []);
});

// ───────────────────────────── played and unplayed ─────────────────────────────

test('with nothing played every column is dimmed and there is no playhead', () => {
  const drawn = rects(card({ peaks: peaks([[255, 255, 0, 0], [255, 255, 0, 0]]) }));

  assert.deepEqual([...new Set(drawn.map(([, , , , , , alpha]) => alpha))], [0.3]);
  assert.equal(drawn.some(([, , , width]) => width === 1), false);
});

test('playing lights the columns behind the playhead and draws the line', () => {
  const el = card({ peaks: peaks([[255, 255, 0, 0], [255, 255, 0, 0]]) });
  const audio = el.querySelector('audio');

  audio.currentTime = 80;
  audio.dispatchEvent(new Event('timeupdate'));

  const drawn = rects(el);

  assert.deepEqual(drawn.slice(0, 2).map(([, , , , , , alpha]) => alpha), [1, 1]);
  assert.deepEqual(drawn.slice(2, 4).map(([, , , , , , alpha]) => alpha), [0.3, 0.3]);
  assert.deepEqual(drawn.at(-1), ['fillRect', WIDTH / 2, 0, 1, HEIGHT, HIGH, 1]);
});

/**
 * preload="none" is why the duration is an attribute at all: the player's own is NaN until somebody
 * presses play, and the progress line has to be right before that.
 */
test('the attribute is what the progress is measured against until the player has its own', () => {
  const el = card({ peaks: peaks([[255, 255, 0, 0]]), duration: '200' });
  const audio = el.querySelector('audio');

  assert.equal(Number.isNaN(audio.duration), true);

  audio.currentTime = 50;
  audio.dispatchEvent(new Event('timeupdate'));

  assert.deepEqual(rects(el).at(-1), ['fillRect', WIDTH / 4, 0, 1, HEIGHT, HIGH, 1]);
});

test('a real duration takes over from the attribute once the metadata lands', () => {
  const el = card({ peaks: peaks([[255, 255, 0, 0]]), duration: '200' });
  const audio = el.querySelector('audio');

  Object.defineProperty(audio, 'duration', { value: 100, configurable: true });
  audio.currentTime = 50;
  audio.dispatchEvent(new Event('loadedmetadata'));

  assert.deepEqual(rects(el).at(-1), ['fillRect', WIDTH / 2, 0, 1, HEIGHT, HIGH, 1]);
});

// ───────────────────────────── needle-drop ─────────────────────────────

test('clicking the waveform seeks to that point in the mix', () => {
  const el = card({ peaks: peaks([[255, 255, 0, 0]]), duration: '160' });

  el.querySelector('canvas').dispatchEvent(new MouseEvent('click', { clientX: WIDTH / 4 }));

  assert.equal(el.querySelector('audio').currentTime, 40);
});

/** Clamped, because a click's coordinate can land a pixel outside the box it was measured in. */
test('a click past either edge lands at the end it is past', () => {
  const el = card({ peaks: peaks([[255, 255, 0, 0]]), duration: '160' });
  const canvas = el.querySelector('canvas');

  canvas.dispatchEvent(new MouseEvent('click', { clientX: -40 }));
  assert.equal(el.querySelector('audio').currentTime, 0);

  canvas.dispatchEvent(new MouseEvent('click', { clientX: WIDTH + 40 }));
  assert.equal(el.querySelector('audio').currentTime, 160);
});

/** DemoTrack uses 0 for a duration nothing measured, and DemoView leaves the attribute off. */
test('a mix nothing measured cannot be seeked into', () => {
  const el = card({ peaks: peaks([[255, 255, 0, 0]]), duration: null });

  el.querySelector('canvas').dispatchEvent(new MouseEvent('click', { clientX: WIDTH / 2 }));

  assert.equal(el.querySelector('audio').currentTime, 0);
});

test('a card with no player is drawn but does not seek', () => {
  const el = card({ peaks: peaks([[255, 255, 0, 0]]), audio: false });

  el.querySelector('canvas').dispatchEvent(new MouseEvent('click', { clientX: WIDTH / 2 }));

  assert.equal(rects(el).length, 2);
});

test('a canvas with no width to click in seeks nowhere', () => {
  const el = card({ peaks: peaks([[255, 255, 0, 0]]) });
  const canvas = el.querySelector('canvas');

  canvas.getBoundingClientRect = () => ({ left: 0, top: 0, width: 0, height: 0 });
  canvas.dispatchEvent(new MouseEvent('click', { clientX: 100 }));

  assert.equal(el.querySelector('audio').currentTime, 0);
});

// ───────────────────────────── nothing to draw ─────────────────────────────

test('a card with no peaks attribute is the card it always was', () => {
  const el = card({ peaks: undefined });

  assert.equal(el.querySelector('canvas'), null);
  assert.equal(el.querySelector('audio') !== null, true);
});

for (const [name, value] of [
  ['an empty attribute', ''],
  ['something that is not base64', '!!!!'],
  ['a truncated column', Buffer.from([255, 255, 0]).toString('base64')],
  ['a partial second column', Buffer.from([255, 255, 0, 0, 128]).toString('base64')],
]) {
  test(`${name} is no waveform rather than a broken one`, () => {
    assert.equal(card({ peaks: value }).querySelector('canvas'), null);
  });
}

// ───────────────────────────── the stylesheet's half ─────────────────────────────

/**
 * waveform.css declares the three properties and the element reads them, so a page whose stylesheet
 * never arrived still draws in something visible rather than in nothing.
 */
test('with no properties declared the bands fall back to one visible colour', () => {
  const drawn = rects(card({ peaks: peaks([[255, 90, 90, 90]]), styled: false }));

  assert.deepEqual([...new Set(drawn.map(([, , , , , colour]) => colour))], ['#e8e8f0']);
});

// ───────────────────────────── laid out, or not ─────────────────────────────

/**
 * A browser that answers no 2D context — an old one, or a page where canvas is switched off. The
 * card keeps everything the server wrote; only the picture is missing.
 */
test('a canvas that gives out no context is a card without a waveform', () => {
  const el = card({ peaks: peaks([[255, 255, 0, 0]]) });
  const canvas = el.querySelector('canvas');
  const calls = drawnOn(canvas);
  const before = calls.length;

  canvas.getContext = () => null;
  resize();

  assert.equal(calls.length, before);
  assert.equal(el.querySelector('audio') !== null, true);
});

/** A display that does not report one. The backing store is then the CSS size, not zero. */
test('a device that claims no pixel ratio is drawn at one to one', () => {
  const ratio = window.devicePixelRatio;

  Object.defineProperty(window, 'devicePixelRatio', { value: 0, configurable: true });

  try {
    assert.equal(card({ peaks: peaks([[255, 255, 0, 0]]) }).querySelector('canvas').width, WIDTH);
  } finally {
    Object.defineProperty(window, 'devicePixelRatio', { value: ratio, configurable: true });
  }
});

test('a card with no width draws nothing and does not throw', () => {
  const el = document.createElement('demo-waveform');

  el.setAttribute('peaks', peaks([[255, 255, 0, 0]]));
  document.body.append(el);

  assert.equal(drawnOn(el.querySelector('canvas')).length, 0);
});

/**
 * The card growing, not the window. connectedCallback runs before the native control is laid out,
 * so the first box an element sees is not the box it ends up with.
 */
test('the card changing size repaints it at the new one', () => {
  const el = card({ peaks: peaks([[255, 255, 0, 0]]) });

  sized(el, 256, HEIGHT);
  resize();

  assert.equal(el.querySelector('canvas').width, 256 * window.devicePixelRatio);
});

/**
 * **A repaint is not a resize**, and the difference is most of the cost of playing a mix.
 *
 * Assigning canvas.width or .height reallocates the backing store and resets the context, whether
 * or not the value changed — and paint() runs on every timeupdate, four times a second, per card.
 * jsdom does not model that reset, which is exactly why this counts the assignment rather than
 * looking for cleared pixels: the property is the thing with the cost behind it.
 */
test('a repaint at an unchanged size does not reallocate the backing store', () => {
  const el = card({ peaks: peaks([[255, 255, 0, 0]]) });
  const canvas = el.querySelector('canvas');

  let resized = 0;
  const store = { width: canvas.width, height: canvas.height };

  for (const side of ['width', 'height']) {
    Object.defineProperty(canvas, side, {
      configurable: true,
      get: () => store[side],
      set: (value) => { resized++; store[side] = value; },
    });
  }

  el.querySelector('audio').dispatchEvent(new window.Event('timeupdate'));

  assert.equal(resized, 0);
  // And it still drew, so this is not a repaint that was skipped altogether.
  assert.equal(rects(el).length > 0, true);

  // The box genuinely changing is what the assignment is for, and it still happens.
  sized(el, 256, HEIGHT);
  resize();

  assert.equal(resized > 0, true);
  assert.equal(canvas.width, 256 * window.devicePixelRatio);
});

/** connectedCallback fires again when an element is moved, and the SPA swap is what moves one. */
test('moving it repaints without building a second canvas', () => {
  const el = card({ peaks: peaks([[255, 255, 0, 0]]) });
  const before = el.querySelector('canvas');

  el.remove();
  document.body.append(el);

  assert.equal(el.querySelector('canvas'), before);
  assert.equal(el.querySelectorAll('canvas').length, 1);
});

/** Removed from the page, it stops observing — otherwise every swapped-out card keeps repainting. */
test('a card taken out of the page stops answering resizes', () => {
  const el = card({ peaks: peaks([[255, 255, 0, 0]]) });
  const canvas = el.querySelector('canvas');

  el.remove();
  sized(el, 256, HEIGHT);
  resize();

  assert.equal(canvas.width, WIDTH * window.devicePixelRatio);
});
