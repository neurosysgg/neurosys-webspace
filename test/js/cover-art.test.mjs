/**
 * <cover-art src fallback alt> — the release cover and what happens when the file host doesn't
 * serve it.
 *
 * The fallback used to be an inline onerror= attribute, which a strict script-src forbids. As a
 * listener it survives the policy, and it is attached before src is assigned so a response that
 * fails immediately cannot beat it — the two facts this file is here to keep true.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import './dom.mjs';

const PLACEHOLDER = '/assets/img/cover-placeholder.svg';

function coverArt(attributes = {}) {
  const el = document.createElement('cover-art');

  for (const [name, value] of Object.entries(attributes)) el.setAttribute(name, value);
  document.body.append(el);

  return el;
}

const fail = (img) => { img.dispatchEvent(new Event('error')); };

// ───────────────────────────── what it builds ─────────────────────────────

test('it builds the img the view no longer has to describe', () => {
  const el  = coverArt({ src: '/cover.png', alt: 'ill. cover' });
  const img = el.querySelector('img');

  assert.equal(img.getAttribute('src'), '/cover.png');
  assert.equal(img.alt, 'ill. cover');
});

/** An img with no alt at all is an accessibility failure; an empty one is a decorative image. */
test('a cover with no alt gets an empty one rather than none', () => {
  const img = coverArt({ src: '/cover.png' }).querySelector('img');

  assert.equal(img.alt, '');
  assert.equal(img.hasAttribute('alt'), true);
});

/** Better nothing than <img src="null">, which is a request to the page's own URL. */
test('a cover with no src builds nothing at all', () => {
  assert.equal(coverArt({ alt: 'x' }).querySelector('img'), null);
});

test('an empty src builds nothing either', () => {
  assert.equal(coverArt({ src: '', fallback: PLACEHOLDER }).querySelector('img'), null);
});

// ───────────────────────────── the fallback ─────────────────────────────

test('a cover the file host does not serve falls back to the placeholder', () => {
  const img = coverArt({ src: '/gone.png', fallback: PLACEHOLDER }).querySelector('img');

  fail(img);

  assert.equal(img.getAttribute('src'), PLACEHOLDER);
});

/** once: true — a placeholder that is itself missing fails quietly rather than looping. */
test('the fallback is tried once, not every time', () => {
  const img = coverArt({ src: '/gone.png', fallback: PLACEHOLDER }).querySelector('img');

  fail(img);
  img.src = '/still-gone.png';
  fail(img);

  assert.equal(img.getAttribute('src'), '/still-gone.png');
});

test('with no fallback there is nothing to fall back to', () => {
  const img = coverArt({ src: '/gone.png' }).querySelector('img');

  fail(img);

  assert.equal(img.getAttribute('src'), '/gone.png');
});

test('an empty fallback is no fallback', () => {
  const img = coverArt({ src: '/gone.png', fallback: '' }).querySelector('img');

  fail(img);

  assert.equal(img.getAttribute('src'), '/gone.png');
});

/**
 * The reason it is a listener at all: script-src is strict, and an inline handler would need
 * 'unsafe-inline' — the one allowance the policy is careful not to carry.
 */
test('the fallback is a listener, not an inline handler', () => {
  const el = coverArt({ src: '/cover.png', fallback: PLACEHOLDER });

  assert.equal(el.innerHTML.includes('onerror'), false);
  assert.equal(el.querySelector('img').hasAttribute('onerror'), false);
});

// ───────────────────────────── moving it ─────────────────────────────

/** connectedCallback fires again if the element is ever moved in the DOM. */
test('moving it does not rebuild it', () => {
  const el     = coverArt({ src: '/cover.png', fallback: PLACEHOLDER });
  const before = el.querySelector('img');

  document.body.append(el);

  assert.equal(el.querySelector('img'), before);
});

/** And the listener it already attached survives the move. */
test('a moved cover still falls back', () => {
  const el = coverArt({ src: '/gone.png', fallback: PLACEHOLDER });

  document.body.append(el);
  fail(el.querySelector('img'));

  assert.equal(el.querySelector('img').getAttribute('src'), PLACEHOLDER);
});
