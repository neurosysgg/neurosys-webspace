/**
 * <soundcloud-profile>, the client-side half of NeuroSYS\Model\Embed\SoundCloudProfileEmbed.
 *
 * The whole account rather than one track. What is asserted here is only what differs from
 * <soundcloud-player> — the resource the widget resolves, the single-credit attribution, and the
 * fact that no track fact is needed to build either. Everything they share is SoundCloudWidget's,
 * and soundcloud-player.test.mjs is where it is pinned.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import './dom.mjs';

/** What SoundCloudProfileEmbed sends: a layout, the toggles, and the height to reserve. */
const DEFAULTS = {
  'player-style': 'classic',
  options:        'auto_play show_comments show_user show_teaser',
  height:         '450',
};

/** Builds a gated profile player, consents to it, and returns it with the embed in place. */
function loaded(attrs = {}) {
  const el = gated(attrs);
  el.querySelector('button').click();

  return el;
}

/** Builds a profile player and leaves it at the consent gate. */
function gated(attrs = {}) {
  const el = document.createElement('soundcloud-profile');

  for (const [name, value] of Object.entries({ ...DEFAULTS, ...attrs })) {
    el.setAttribute(name, value);
  }
  document.body.append(el);

  return el;
}

const src = (el) => el.querySelector('iframe').getAttribute('src');

// ───────────────────────────── the consent gate ─────────────────────────────

test('nothing is requested from SoundCloud before the visitor consents', () => {
  const el = gated();

  assert.equal(el.querySelector('iframe'), null);
  assert.equal(el.innerHTML.includes('soundcloud.com'), false);
});

test('the gate names the provider it would connect to', () => {
  const el = gated();

  assert.match(el.textContent, /SoundCloud player/);
  assert.match(el.textContent, /connects you to SoundCloud’s servers/);
});

test('consenting swaps the gate for the player and marks the element loaded', () => {
  const el = loaded();

  assert.ok(el.querySelector('iframe'));
  assert.equal(el.querySelector('button'), null);
  assert.ok(el.hasAttribute('loaded'));
});

// ───────────────────────────── the resource ─────────────────────────────

/**
 * The whole point of the element: the widget resolves the profile URL to the latest tracks, so the
 * handle is the only fact needed. No numeric user id was ever fetched from SoundCloud for this.
 */
test('the widget resolves the profile, built from the handle alone', () => {
  assert.match(src(loaded()), /url=https%3A%2F%2Fsoundcloud\.com%2Fneurosysgg&/);
});

test('no track is named anywhere in the player url', () => {
  const url = src(loaded());

  assert.equal(url.includes('tracks'), false);
  assert.equal(url.includes('secret_token'), false);
});

test('the profile is listed rather than shown one track at a time', () => {
  assert.match(src(loaded()), /visual=false/);
  assert.match(src(loaded({ 'player-style': 'visual' })), /visual=true/);
});

test('the height it is given is the height the iframe renders at', () => {
  assert.equal(loaded().querySelector('iframe').getAttribute('height'), '450');
});

/** The reserved height is what stops the page jumping when the gate becomes the player. */
test('the gate reserves exactly the height it is given', () => {
  assert.equal(gated().style.getPropertyValue('--player-height'), '450px');
});

// ───────────────────────────── the attribution ─────────────────────────────

/**
 * A track credits the artist and then the track. A profile *is* the artist, so it credits once —
 * there is no second thing to name, and a dangling separator would be the visible failure.
 */
test('the attribution credits the artist once, with nothing after it', () => {
  const links = [...loaded().querySelectorAll('a')];

  assert.equal(links.length, 1);
  assert.equal(links[0].getAttribute('href'), 'https://soundcloud.com/neurosysgg');
  assert.equal(links[0].textContent, 'neuro.SYS');
  assert.equal(loaded().textContent.includes(' · '), false);
});

test('the iframe is named for the artist rather than for a track', () => {
  assert.equal(loaded().querySelector('iframe').title, 'neuro.SYS on SoundCloud');
});

// ───────────────────────────── the invariants ─────────────────────────────

test('the markup only ever references SoundCloud hosts', () => {
  const urls = [...loaded().querySelectorAll('[src], [href]')]
    .map((n) => n.getAttribute('src') ?? n.getAttribute('href'));

  assert.notEqual(urls.length, 0);
  for (const url of urls) {
    assert.match(url, /^https:\/\/(w\.|api\.)?soundcloud\.com\//, `${url} is not a SoundCloud host`);
  }
});
