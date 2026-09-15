/**
 * <machine-filter> — the box over a directory the admin's machine service lists.
 *
 * The server writes it hidden, because without its module it filters nothing; the module shows it,
 * and what is typed hides every entry whose name does not hold it, whatever its case. The way up is
 * no entry, and is never hidden.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import './dom.mjs';

/** A listing the way the service writes one: the filter, then the table beside it. */
function listing() {
  const section = document.createElement('section');

  section.innerHTML = '<machine-filter hidden><input type="search"></machine-filter><table>'
    + '<tr><td>..</td></tr><tr data-entry="readme.txt"><td>readme.txt</td></tr>'
    + '<tr data-entry="photo.png"><td>photo.png</td></tr></table>';
  document.body.append(section);

  return section;
}

/**
 * Types `value` into the box.
 *
 * @param {Element} section
 * @param {string} value
 */
function type(section, value) {
  const input = /** @type {HTMLInputElement} */ (section.querySelector('input'));

  input.value = value;
  input.dispatchEvent(new Event('input', { bubbles: true }));
}

/**
 * Which rows are hidden, by what their first cell says.
 *
 * @param {Element} section
 */
const hidden = (section) => [...section.querySelectorAll('tr')]
  .filter((row) => row.hasAttribute('hidden'))
  .map((row) => row.textContent);

test('it shows itself, and hides the entries whose names do not hold what is typed', () => {
  const section = listing();

  assert.equal(section.querySelector('machine-filter')?.hasAttribute('hidden'), false);

  type(section, ' READ ');
  assert.deepEqual(hidden(section), ['photo.png']);

  type(section, '');
  assert.deepEqual(hidden(section), []);

  section.remove();
  type(section, 'nothing');
  assert.deepEqual(hidden(section), [], 'taken off the page, it listens no more');
});
