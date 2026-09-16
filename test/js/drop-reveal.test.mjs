/**
 * <drop-reveal> — around the token's field on the page a drop's link opens.
 *
 * The token rides after the link's `#`, which no request carries, so the module fills the field in from
 * the address, hides it, and takes the token out of the address. With nothing after the `#`, or no
 * field to fill, it leaves the page as the server wrote it.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import './dom.mjs';

/**
 * The form the page writes, reduced to the element and its field — or to the element alone.
 *
 * @param {boolean} field
 */
function page(field = true) {
  const form = document.createElement('form');

  form.innerHTML = field
    ? '<drop-reveal><p><label>Token <input type="text" name="token"></label></p></drop-reveal>'
    : '<drop-reveal><p>no field</p></drop-reveal>';

  return form;
}

/** The field's value as the form would send it. */
const token = (/** @type {Element} */ form) => form.querySelector('input')?.getAttribute('value') ?? null;

test('it fills the token in from the link, hides its field, and takes the token out of the address', () => {
  history.replaceState(null, '', '/drop?x=1#aGVsbG8_dGhlcmUtdG9rZW4');

  const form = page();
  document.body.append(form);

  assert.equal(token(form), 'aGVsbG8_dGhlcmUtdG9rZW4');
  assert.equal(form.querySelector('drop-reveal')?.hasAttribute('hidden'), true);
  assert.equal(location.hash, '');
  assert.equal(`${location.pathname}${location.search}`, '/drop?x=1', 'the rest of the address stays');

  form.remove();
});

test('with nothing after the #, it leaves the field to be filled by hand', () => {
  history.replaceState(null, '', '/drop');

  const form = page();
  document.body.append(form);

  assert.equal(token(form), null);
  assert.equal(form.querySelector('drop-reveal')?.hasAttribute('hidden'), false);

  form.remove();
});

test('with no field to fill, it leaves the address alone', () => {
  history.replaceState(null, '', '/drop#kept');

  const form = page(false);
  document.body.append(form);

  assert.equal(location.hash, '#kept');
  assert.equal(form.querySelector('drop-reveal')?.hasAttribute('hidden'), false);

  form.remove();
  history.replaceState(null, '', '/');
});
