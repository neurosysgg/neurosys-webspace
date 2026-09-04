/**
 * NestedElement — the guard every tag that only means something inside another one inherits.
 *
 * <terminal-key> outside a <terminal-field> is not a smaller mistake than a misspelled tag; it is
 * the same mistake, failing the same silent way — an inert inline box, styled by a selector that
 * no longer matches, with nothing in the console. The guard is what those otherwise behaviourless
 * classes are for, so it is checked for every one of them rather than for the two that happened to
 * have a test.
 *
 * The pairings are read out of the elements, not restated here: each one is asked where it belongs
 * by being put somewhere it does not. Adding a nested element therefore covers it automatically,
 * and the list below is the only thing to update.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { uncaughtErrors } from './dom.mjs';
import { Tag } from '../../public/assets/js/model/Tag.js';

/** Somewhere to build in, so nothing here is left in the page for another file to find. */
const sandbox = document.createElement('div');
document.body.append(sandbox);

/**
 * The tag this one refuses to connect outside, or null if it is a root.
 *
 * Asked by putting it where it does not belong and reading the answer off the error, which is why
 * the message names the parent rather than merely saying no.
 */
function parentOf(tag) {
  const el = document.createElement(tag);
  const [error] = uncaughtErrors(() => sandbox.append(el));

  el.remove();

  return error === undefined ? null : /must be inside <([^>]+)>/.exec(error.message)?.[1] ?? null;
}

const PARENTS = new Map(Object.values(Tag).map((tag) => [tag, parentOf(tag)]));

/** A connected element of this type, with every ancestor it needs built around it. */
function place(tag) {
  const parent = PARENTS.get(tag);
  const el     = document.createElement(tag);

  if (parent === null) {
    sandbox.append(el);
  } else {
    // Into a live parent rather than a detached tree: <terminal-window> replaces its own children
    // when it connects, so anything put inside it beforehand is gone before its guard ever runs.
    place(parent).append(el);
  }

  return el;
}

test('every nested tag names the element it belongs inside', () => {
  const nested = [...PARENTS].filter(([, parent]) => parent !== null);

  assert.deepEqual(nested, [
    ['terminal-command', 'terminal-window'],
    ['terminal-field',   'terminal-window'],
    ['terminal-key',     'terminal-field'],
    ['terminal-value',   'terminal-field'],
    ['terminal-cursor',  'terminal-window'],
    ['download-card',    'download-list'],
    ['download-label',   'download-card'],
    ['download-meta',    'download-card'],
    ['release-card',     'release-list'],
    ['release-title',    'release-card'],
    ['release-meta',     'release-card'],
  ]);
});

/** The other half: inside the element it names, it connects without complaint. */
test('every nested tag is happy where it belongs', () => {
  for (const [tag, parent] of PARENTS) {
    if (parent === null) continue;

    const errors = uncaughtErrors(() => place(tag));

    assert.deepEqual(errors.map((e) => e.message), [], `<${tag}> refused a valid <${parent}>`);
  }
});

/** The roots are the other side of the same list: nothing above them to be inside. */
test('a root element connects anywhere', () => {
  const roots = [...PARENTS].filter(([, parent]) => parent === null).map(([tag]) => tag);

  assert.deepEqual(roots, [
    'soundcloud-player', 'cover-art', 'terminal-window', 'download-list', 'release-list',
  ]);
});

/**
 * "Somewhere inside", not "directly under" — a card's tags sit inside the anchor that has to stay
 * a real link, so <download-card> wraps <a> wraps <download-label>.
 */
test('the guard looks through wrappers, not just at the direct parent', () => {
  const list  = document.createElement('download-list');
  const card  = document.createElement('download-card');
  const link  = document.createElement('a');
  const label = document.createElement('download-label');

  link.append(label);
  card.append(link);
  list.append(card);

  assert.deepEqual(uncaughtErrors(() => sandbox.append(list)), []);
});

test('a wrapper does not excuse the missing parent', () => {
  const link = document.createElement('a');
  link.append(document.createElement('download-label'));

  const [error] = uncaughtErrors(() => sandbox.append(link));

  assert.match(error.message, /<download-label> must be inside <download-card>/);
});

/**
 * The message has to name the tag, not the class: `customElements.getName` is recent enough that
 * NestedElement falls back to the constructor name, and "must be inside <DownloadCard>" is a
 * sentence about source code rather than about the page it is wrong in.
 */
test('the message names tags on both sides', () => {
  const [error] = uncaughtErrors(() => sandbox.append(document.createElement('terminal-key')));

  assert.equal(error.message, '<terminal-key> must be inside <terminal-field>, but is not.');
});

/**
 * customElements.getName is recent, so NestedElement falls back to the constructor's name. The
 * fallback is worse — "<DownloadCard>" is a sentence about source code rather than about the page
 * it is wrong in — but a guard that throws its own TypeError on an older browser is worse still.
 */
test('an engine without customElements.getName still names a parent', () => {
  assert.equal(typeof customElements.getName, 'function', 'nothing to hide; the fallback is now dead code');

  // Shadowed rather than deleted: getName lives on the prototype, so `delete` below removes only
  // this own property and puts the real one back.
  Object.defineProperty(customElements, 'getName', { value: undefined, configurable: true });

  try {
    const [error] = uncaughtErrors(() => sandbox.append(document.createElement('terminal-key')));

    assert.equal(error.message, '<terminal-key> must be inside <TerminalField>, but is not.');
  } finally {
    delete customElements.getName;
  }

  assert.equal(typeof customElements.getName, 'function', 'the real getName was not restored');
});
