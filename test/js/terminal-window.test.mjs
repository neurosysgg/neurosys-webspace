/**
 * <terminal-window>, which builds every node below itself.
 *
 * The rows it renders are a release's metadata and the 404's error line, so these cases stand in
 * for what ViewTest used to assert about the server's markup.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { uncaughtErrors } from './dom.mjs';

/** Builds a terminal the way Terminal::toElement() writes one. */
function terminal({ label = 'release.log', command = './release --track "ill."', fields = [] } = {}) {
  const el = document.createElement('terminal-window');

  el.setAttribute('label', label);
  el.setAttribute('command', command);
  el.setAttribute('fields', JSON.stringify(fields));
  document.body.append(el);

  return el;
}

const rows = (el) => [...el.querySelectorAll('terminal-field')].map((f) => [
  f.querySelector('terminal-key').textContent,
  f.querySelector('terminal-value').textContent,
  f.getAttribute('tone'),
]);

test('it builds the command line, the rows and the cursor', () => {
  const el = terminal({
    fields: [
      { key: 'artist', value: 'neuro.SYS', tone: 'plain' },
      { key: 'status', value: 'ready', tone: 'ok' },
    ],
  });

  assert.equal(el.querySelector('terminal-command').textContent, './release --track "ill."');
  assert.deepEqual(rows(el), [['artist', 'neuro.SYS', null], ['status', 'ready', 'ok']]);
  assert.ok(el.querySelector('terminal-cursor'));
});

test('a plain row carries no tone attribute at all', () => {
  const el = terminal({ fields: [{ key: 'bpm', value: '140', tone: 'plain' }] });

  assert.equal(el.querySelector('terminal-field').hasAttribute('tone'), false);
});

test('an error row is toned so the stylesheet can colour its key', () => {
  const el = terminal({ fields: [{ key: 'error', value: '404 — not found', tone: 'error' }] });

  assert.deepEqual(rows(el), [['error', '404 — not found', 'error']]);
});

test('a command that looks like markup is text, not markup', () => {
  const el = terminal({ command: 'find /<script>x</script>' });

  assert.equal(el.querySelector('terminal-command').textContent, 'find /<script>x</script>');
  assert.equal(el.querySelector('script'), null);
});

test('a terminal with no rows is still a terminal', () => {
  const el = terminal({ fields: [] });

  assert.equal(el.querySelectorAll('terminal-field').length, 0);
  assert.ok(el.querySelector('terminal-command'));
  assert.ok(el.querySelector('terminal-cursor'));
});

test('a fields attribute that is not a list of rows reports rather than half-rendering', () => {
  const el = document.createElement('terminal-window');
  el.setAttribute('command', 'x');
  el.setAttribute('fields', '[{"key":"a"}]');

  const [error] = uncaughtErrors(() => document.body.append(el));

  assert.match(error.message, /not a list of rows/);
  assert.equal(el.querySelector('terminal-field'), null);
});

test('a fields list with a hole in it reports rather than half-rendering', () => {
  const el = document.createElement('terminal-window');
  el.setAttribute('command', 'x');
  el.setAttribute('fields', '[null]');

  const [error] = uncaughtErrors(() => document.body.append(el));

  assert.match(error.message, /not a list of rows/);
});

/**
 * The server always writes fields, even for a terminal with no rows — but an element that renders
 * nothing at all when it is missing is the difference between an empty window and a thrown error
 * on a page that would otherwise have worked.
 */
test('a terminal with no fields attribute is an empty terminal, not an error', () => {
  const el = document.createElement('terminal-window');
  el.setAttribute('command', './x');

  assert.deepEqual(uncaughtErrors(() => document.body.append(el)), []);
  assert.equal(el.querySelectorAll('terminal-field').length, 0);
  assert.ok(el.querySelector('terminal-cursor'));
});

test('an empty fields attribute is no rows rather than a parse error', () => {
  const el = document.createElement('terminal-window');
  el.setAttribute('fields', '');

  assert.deepEqual(uncaughtErrors(() => document.body.append(el)), []);
  assert.equal(el.querySelectorAll('terminal-field').length, 0);
});

test('a terminal with no command line still builds the rest', () => {
  const el = document.createElement('terminal-window');
  document.body.append(el);

  assert.equal(el.querySelector('terminal-command').textContent, '');
  assert.ok(el.querySelector('terminal-cursor'));
});

/** connectedCallback fires again if the element is ever moved in the DOM. */
test('moving a terminal does not rebuild it', () => {
  const el     = terminal({ fields: [{ key: 'bpm', value: '140', tone: 'plain' }] });
  const before = el.querySelector('terminal-field');

  document.body.append(el);

  assert.equal(el.querySelectorAll('terminal-field').length, 1);
  assert.equal(el.querySelector('terminal-field'), before);
});
