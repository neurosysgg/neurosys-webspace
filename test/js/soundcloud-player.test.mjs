/**
 * <soundcloud-player>, the client-side half of NeuroSYS\Model\Embed\SoundCloudEmbed.
 *
 * These cases were EmbedTest's until the widget URL and the attribution moved into the element.
 * The invariants did not move with them by accident — they are the reason this file exists.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

import './dom.mjs';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..');

const DEFAULTS = {
  'track-id':     '2394077313',
  permalink:      'ill',
  'player-style': 'visual',
  options:        'auto_play show_comments show_user show_teaser',
  'track-title':  'ill.',
  height:         '300',
};

/** Builds a gated player, consents to it, and returns the element with the embed in place. */
function loaded(attrs = {}) {
  const el = gated(attrs);
  el.querySelector('button').click();

  return el;
}

/** Builds a player and leaves it at the consent gate. */
function gated(attrs = {}) {
  const el = document.createElement('soundcloud-player');

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

// ───────────────────────────── the widget URL ─────────────────────────────

test('renders an iframe for the given track', () => {
  assert.match(src(loaded()), /soundcloud%3Atracks%3A2394077313/);
});

test('the secret token is carried into the player url', () => {
  const url = src(loaded({ 'secret-token': 's-dIMAqki109G' }));

  assert.match(url, /secret_token/);
  assert.match(url, /s-dIMAqki109G/);
});

test('a public track has no secret token', () => {
  assert.equal(src(loaded()).includes('secret_token'), false);
});

test('every option is emitted explicitly, on or off', () => {
  const url = src(loaded({ options: 'auto_play' }));

  assert.match(url, /auto_play=true/);
  for (const off of ['hide_related', 'show_comments', 'show_user', 'show_reposts', 'show_teaser']) {
    assert.match(url, new RegExp(`${off}=false`));
  }
});

test('an empty option list turns everything off', () => {
  const url = src(loaded({ options: '' }));

  for (const option of ['auto_play', 'hide_related', 'show_comments', 'show_user', 'show_reposts', 'show_teaser']) {
    assert.match(url, new RegExp(`${option}=false`));
  }
});

test('the player style fixes the visual flag', () => {
  assert.match(src(loaded({ 'player-style': 'visual' })), /visual=true/);
  assert.match(src(loaded({ 'player-style': 'classic' })), /visual=false/);
});

test('the height it is given is the height the iframe renders at', () => {
  for (const height of ['300', '166']) {
    assert.equal(loaded({ height }).querySelector('iframe').getAttribute('height'), height);
  }
});

// ───────────────────────────── the attribution ─────────────────────────────

test('the attribution credits the title it is given', () => {
  assert.match(loaded({ 'track-title': 'my track!' }).textContent, /my track!/);
});

test('a title that looks like markup is text, not markup', () => {
  const el = loaded({ 'track-title': 'rock & <roll>' });

  assert.match(el.textContent, /rock & <roll>/);
  assert.equal(el.querySelector('roll'), null);
  assert.match(el.innerHTML, /&lt;roll&gt;/);
});

test('a title with quotes cannot break out of the iframe title attribute', () => {
  const el = loaded({ 'track-title': 'a "quoted" title' });

  assert.equal(el.querySelector('iframe').title, 'a "quoted" title on SoundCloud');
});

test('the attribution links to the artist and the track', () => {
  const hrefs = [...loaded().querySelectorAll('a')].map((a) => a.getAttribute('href'));

  assert.ok(hrefs.includes('https://soundcloud.com/neurosysgg'));
  assert.ok(hrefs.includes('https://soundcloud.com/neurosysgg/ill'));
});

test('a private track permalink carries the token', () => {
  const hrefs = [...loaded({ 'secret-token': 's-dIMAqki109G' }).querySelectorAll('a')]
    .map((a) => a.getAttribute('href'));

  assert.ok(hrefs.includes('https://soundcloud.com/neurosysgg/ill/s-dIMAqki109G'));
});

// ───────────────────────────── the invariants ─────────────────────────────

test('the markup only ever references SoundCloud hosts', () => {
  const el   = loaded({ 'secret-token': 's-dIMAqki109G' });
  const urls = [...el.querySelectorAll('[src], [href]')]
    .map((n) => n.getAttribute('src') ?? n.getAttribute('href'));

  assert.notEqual(urls.length, 0);
  for (const url of urls) {
    assert.match(url, /^https:\/\/(w\.|api\.)?soundcloud\.com\//, `${url} is not a SoundCloud host`);
  }
});

/**
 * The player asks for autoplay and encrypted-media. Permissions-Policy is built with denyAll(), so
 * adding a case to PermissionsPolicyFeature would switch the player off with no error anywhere.
 * SecurityTest asserted this against the server-rendered iframe; the iframe is built here now, so
 * the check reaches across to the policy rather than being dropped.
 */
test('the Permissions-Policy denies nothing the player asks for', () => {
  const allow = loaded().querySelector('iframe').getAttribute('allow');
  assert.ok(allow, 'no allow= on the iframe');

  // Read the header the app actually sends, rather than a copy of it kept here.
  const policy = execFileSync('php', ['-r', `require '${ROOT}/autoload.php';
      echo NeuroSYS\\Http\\SecurityHeaders::headers()['Permissions-Policy'];`], { encoding: 'utf8' });

  for (const feature of allow.split(';').map((f) => f.trim())) {
    assert.equal(
      policy.includes(`${feature}=()`),
      false,
      `Permissions-Policy denies '${feature}', which the SoundCloud player requires`,
    );
  }
});
