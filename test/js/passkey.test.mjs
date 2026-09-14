/**
 * Passkey — a form a passkey answers before it is sent: the admin's unlock, its registration, and
 * each write.
 *
 * The framework's module, tested here because it is compiled into this site's tree. The browser's
 * authenticator is a double of `navigator.credentials` that answers as the test says, so what is
 * pinned is this module's half: that a submit is held until the authenticator answers, that nothing
 * is sent when it does not, that what it answered reaches the form under the names the server reads,
 * and that the form is then sent by the button that was pressed — a write's "dry run" and "apply"
 * are two different posts.
 *
 * **Started by main.js, which dom.mjs loads before anything here runs** — so every form below
 * arrives after the start, as the admin's do when a navigation swaps them in, and a test that passes
 * here is one main.js really started.
 */
import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';

import { dom } from './dom.mjs';
import { CeremonyType } from '../../public/assets/js/phpanta/model/CeremonyType.js';
import { PasskeyAttribute } from '../../public/assets/js/phpanta/model/PasskeyAttribute.js';
import { PasskeyFormField } from '../../public/assets/js/phpanta/model/PasskeyFormField.js';

// ───────────────────────────── the doubles ─────────────────────────────

/** What the authenticator does next: answer with a value, or refuse — a cancel, a timeout. */
let next = /** @type {{ answer?: unknown, refuse?: boolean }} */ ({});

/** Every set of options the page asked the authenticator with, and which ceremony. */
let asked = /** @type {[string, any][]} */ ([]);

/** Whether the browser has credentials to ask at all. */
let hasCredentials = true;

/**
 * The authenticator: records what it was asked, then does what the test said.
 *
 * @param {string} ceremony
 * @param {unknown} options
 */
function authenticate(ceremony, options) {
  asked.push([ceremony, options]);

  return next.refuse === true ? Promise.reject(new Error('NotAllowedError')) : Promise.resolve(next.answer ?? null);
}

Object.defineProperty(globalThis, 'navigator', {
  configurable: true,
  get() {
    return {
      credentials: hasCredentials
        ? { get: (options) => authenticate('get', options), create: (options) => authenticate('create', options) }
        : undefined,
    };
  },
});

/** Each form that was really sent, and by which button. */
let sent = /** @type {[HTMLFormElement, Element | null][]} */ ([]);

// A form sent past the module lands here, as the browser would navigate — which jsdom cannot, so it
// is stopped and recorded instead. Added after main.js's own listener, so it sees what that let go.
document.addEventListener('submit', (event) => {
  if (event.defaultPrevented) return;

  event.preventDefault();
  sent.push([/** @type {HTMLFormElement} */ (event.target), event.submitter]);
});

// ───────────────────────────── the helpers ─────────────────────────────

/**
 * `bytes`, as an ArrayBuffer — what an authenticator's response holds.
 *
 * @param {number[]} bytes
 * @returns {ArrayBuffer}
 */
const buffer = (bytes) => new Uint8Array(bytes).buffer;

/**
 * A form marked for `ceremony`, over `challenge`, with a dry-run and an apply button.
 *
 * @param {string | null} ceremony
 * @param {string | null} challenge
 * @returns {HTMLFormElement}
 */
function form(ceremony, challenge = 'AQID') {
  const element = /** @type {HTMLFormElement} */ (document.createElement('form'));

  element.setAttribute('method', 'post');
  element.setAttribute('action', '/admin');

  if (ceremony !== null) element.setAttribute(PasskeyAttribute.Ceremony, ceremony);
  if (challenge !== null) element.setAttribute(PasskeyAttribute.Challenge, challenge);

  for (const value of ['false', 'true']) {
    const button = document.createElement('button');

    button.setAttribute('type', 'submit');
    button.setAttribute('name', 'apply');
    button.setAttribute('value', value);
    element.append(button);
  }

  document.body.append(element);

  return element;
}

/**
 * The button of `element` a visitor presses.
 *
 * @param {HTMLFormElement} element
 * @param {number} index
 * @returns {HTMLButtonElement}
 */
const button = (element, index = 0) => /** @type {HTMLButtonElement} */ (element.querySelectorAll('button')[index]);

/**
 * What the hidden field `field` of `element` holds.
 *
 * @param {HTMLFormElement} element
 * @param {string} field
 */
const field = (element, field) => element.querySelector(`input[name="${field}"]`)?.getAttribute('value') ?? null;

/** Lets the ceremony's promises settle. */
const settled = () => new Promise((resolve) => { setTimeout(resolve, 0); });

/** A signed answer, as an authenticator gives one. */
const assertion = () => ({
  id: 'Y3JlZA',
  response: {
    clientDataJSON: buffer([0x7b, 0x7d]),
    authenticatorData: buffer([0xfb, 0xff]),
    signature: buffer([1, 2, 3]),
  },
});

/**
 * A new key, as an authenticator makes one.
 *
 * @param {ArrayBuffer | null} key
 */
const attestation = (key = buffer([0x30, 0x59])) => ({
  id: 'bmV3',
  response: {
    clientDataJSON: buffer([0x7b, 0x7d]),
    getAuthenticatorData: () => buffer([0x45]),
    getPublicKey: () => key,
  },
});

beforeEach(() => {
  next           = {};
  asked          = [];
  sent           = [];
  hasCredentials = true;
  document.body.querySelectorAll('form').forEach((element) => { element.remove(); });
});

// ───────────────────────────── whether it asks ─────────────────────────────

/** Nothing to ask with is no reason to keep a form from going; the server refuses it without an answer. */
test('a browser with no credentials to ask sends the form as it is', async () => {
  const unlock = form(CeremonyType.Get);

  hasCredentials = false;
  unlock.requestSubmit(button(unlock));
  await settled();

  assert.deepEqual(asked, []);
  assert.equal(sent.length, 1);
});

// ───────────────────────────── an unlock ─────────────────────────────

/**
 * The submit is held; the authenticator is asked to sign the form's challenge, with the person
 * verified; its answer lands in the form as base64url; and the form is then sent — once.
 */
test('an unlock is sent once its signature is written into the form', async () => {
  const unlock = form(CeremonyType.Get, 'AQI');

  next = { answer: assertion() };
  unlock.requestSubmit(button(unlock));

  assert.deepEqual(sent, [], 'the form went before the authenticator answered');

  await settled();

  assert.equal(asked.length, 1);
  assert.equal(asked[0][0], 'get');
  assert.deepEqual([...asked[0][1].publicKey.challenge], [1, 2]);
  assert.equal(asked[0][1].publicKey.userVerification, 'required');

  assert.equal(field(unlock, PasskeyFormField.Credential), 'Y3JlZA');
  assert.equal(field(unlock, PasskeyFormField.ClientData), 'e30');
  assert.equal(field(unlock, PasskeyFormField.AuthenticatorData), '-_8', 'not base64url');
  assert.equal(field(unlock, PasskeyFormField.Signature), 'AQID');
  assert.equal(unlock.querySelector(`input[name="${PasskeyFormField.Key}"]`), null);

  assert.equal(sent.length, 1);
  assert.equal(sent[0][0], unlock);
});

/** A write's two buttons are two posts, so the one pressed is the one that sends the form. */
test('the form is sent by the button that was pressed', async () => {
  const write = form(CeremonyType.Get);

  next = { answer: assertion() };
  write.requestSubmit(button(write, 1));
  await settled();

  assert.equal(sent.length, 1);
  assert.equal(sent[0][1], button(write, 1));
});

/** A field the form already holds is written over, not written twice. */
test('an answer replaces what the form already holds', async () => {
  const write = form(CeremonyType.Get);
  const stale = document.createElement('input');

  stale.setAttribute('type', 'hidden');
  stale.setAttribute('name', PasskeyFormField.Signature);
  stale.setAttribute('value', 'stale');
  write.append(stale);

  next = { answer: assertion() };
  write.requestSubmit(button(write));
  await settled();

  assert.equal(write.querySelectorAll(`input[name="${PasskeyFormField.Signature}"]`).length, 1);
  assert.equal(field(write, PasskeyFormField.Signature), 'AQID');
});

/** A form that names no challenge asks over none; the server refuses it, and says so. */
test('a form with no challenge asks over an empty one', async () => {
  const unlock = form(CeremonyType.Get, null);

  next = { answer: assertion() };
  unlock.requestSubmit(button(unlock));
  await settled();

  assert.deepEqual([...asked[0][1].publicKey.challenge], []);
  assert.equal(sent.length, 1);
});

// ───────────────────────────── a registration ─────────────────────────────

/**
 * A new key over the challenge: ES256 only, kept on the device, the person verified, the relying
 * party named for the page's host — and its public half written into the form.
 */
test('a registration is sent with the key the authenticator made', async () => {
  const register = form(CeremonyType.Create);

  next = { answer: attestation() };
  register.requestSubmit(button(register));
  await settled();

  const { publicKey } = asked[0][1];

  assert.equal(asked[0][0], 'create');
  assert.deepEqual(publicKey.pubKeyCredParams, [{ type: 'public-key', alg: -7 }]);
  assert.deepEqual(publicKey.authenticatorSelection, { residentKey: 'required', userVerification: 'required' });
  assert.equal(publicKey.rp.name, 'neurosys.gg');
  assert.equal(publicKey.user.id.length, 16);

  assert.equal(field(register, PasskeyFormField.Credential), 'bmV3');
  assert.equal(field(register, PasskeyFormField.AuthenticatorData), 'RQ');
  assert.equal(field(register, PasskeyFormField.Key), 'MFk');
  assert.equal(register.querySelector(`input[name="${PasskeyFormField.Signature}"]`), null);
  assert.equal(sent.length, 1);
});

// ───────────────────────────── nothing answered ─────────────────────────────

/** A cancel, a timeout, no credential, or no key: nothing is sent, and the button works again. */
test('nothing is sent that the authenticator did not answer', async () => {
  const unlock = form(CeremonyType.Get);

  for (const [ceremony, refusal] of /** @type {[string, typeof next][]} */ ([
    [CeremonyType.Get, { refuse: true }],
    [CeremonyType.Get, { answer: null }],
    [CeremonyType.Create, { answer: attestation(null) }],
  ])) {
    unlock.setAttribute(PasskeyAttribute.Ceremony, ceremony);
    next = refusal;
    unlock.requestSubmit(button(unlock));
    await settled();
  }

  assert.equal(asked.length, 3);
  assert.deepEqual(sent, []);
  assert.equal(field(unlock, PasskeyFormField.Credential), null);

  unlock.setAttribute(PasskeyAttribute.Ceremony, CeremonyType.Get);
  next = { answer: assertion() };
  unlock.requestSubmit(button(unlock));
  await settled();

  assert.equal(sent.length, 1, 'the button did not work again');
});

// ───────────────────────────── not its business ─────────────────────────────

/** A form no passkey answers, and a submit that is no form's, go by untouched. */
test('any other submit goes by untouched', async () => {
  const plain = form(null, null);

  plain.requestSubmit(button(plain));
  document.dispatchEvent(new dom.window.Event('submit', { cancelable: true }));
  await settled();

  assert.deepEqual(asked, []);
  assert.equal(sent.length, 2);
  assert.equal(sent[0][0], plain);
});

// ───────────────────────────── while it asks, and after ─────────────────────────────

/**
 * What the server wrote into a passkey form to say nobody answered, hidden as it arrives.
 *
 * @param {HTMLFormElement} element
 * @returns {HTMLElement}
 */
function status(element) {
  const paragraph = document.createElement('p');

  paragraph.setAttribute(PasskeyAttribute.Status, '');
  paragraph.setAttribute('hidden', '');
  element.append(paragraph);

  return paragraph;
}

/** A challenge that is not base64url is a ceremony that did not happen: nothing asked, nothing sent. */
test('a challenge that does not decode sends nothing, and says so', async () => {
  const unlock = form(CeremonyType.Get, '!!!');
  const said   = status(unlock);

  unlock.requestSubmit(button(unlock));
  await settled();

  assert.deepEqual(asked, []);
  assert.deepEqual(sent, []);
  assert.equal(said.hasAttribute('hidden'), false, 'the form did not say the passkey did not answer');
});

/** Unanswered, the form says so; answered the next time, it stops saying it. */
test('a form says nobody answered until somebody does', async () => {
  const unlock = form(CeremonyType.Get);
  const said   = status(unlock);

  next = { refuse: true };
  unlock.requestSubmit(button(unlock));
  await settled();

  assert.equal(said.hasAttribute('hidden'), false);

  next = { answer: assertion() };
  unlock.requestSubmit(button(unlock));
  await settled();

  assert.equal(said.hasAttribute('hidden'), true);
  assert.equal(sent.length, 1);
});

/** While the authenticator is asked the buttons wait, and a second submit asks it nothing. */
test('a second submit while the authenticator is asked starts no second ceremony', async () => {
  const unlock = form(CeremonyType.Get);

  /** @type {(value: unknown) => void} */
  let answer = () => {};

  next = { answer: new Promise((resolve) => { answer = resolve; }) };
  unlock.requestSubmit(button(unlock));
  unlock.requestSubmit();
  await settled();

  assert.equal(asked.length, 1);
  assert.equal(button(unlock).hasAttribute('disabled'), true, 'the buttons did not wait');

  answer(assertion());
  await settled();

  assert.equal(sent.length, 1);
  assert.equal(button(unlock).hasAttribute('disabled'), false, 'the buttons did not work again');
});
