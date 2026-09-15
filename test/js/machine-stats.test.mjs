/**
 * <machine-stats data-source> — the admin's machine readings, kept live.
 *
 * What is worth pinning is what a person reads: the first answer shows the moment — memory as a
 * share, the network's totals — and every answer after it shows rates worked out against the one
 * before; a meter follows its reading; and an answer that is not the admin's data stops the asking
 * rather than asking again forever. The server writes the table; these tests write it the way the
 * server does.
 *
 * The timer is node's mock, so a test says when two seconds have passed, and the fetch is a double
 * that answers from a queue.
 */
import { test, mock } from 'node:test';
import assert from 'node:assert/strict';

import './dom.mjs';

/** Every fetch the element made, in order. */
const asked = [];

/** What each next fetch answers with, in order: data, or an Error to reject with. */
const answers = [];

// The cast says the double is deliberately partial: a response here is its body and nothing else,
// since that is all the element reads.
globalThis.fetch = /** @type {typeof fetch} */ (/** @type {unknown} */ ((
  /** @type {string} */ url,
  /** @type {RequestInit} */ init,
) => {
  asked.push({ url, init });

  const answer = answers.shift();

  return answer instanceof Error ? Promise.reject(answer) : Promise.resolve({ json: () => Promise.resolve(answer) });
}));

mock.timers.enable({ apis: ['setInterval'] });

const READINGS = ['cpu', 'memory', 'swap', 'received', 'sent', 'load'];

/**
 * An admin answer carrying counters, every one zero unless `values` says otherwise.
 *
 * @param {Record<string, number>} values
 */
const reading = (values) => ({
  status: 200,
  sections: [
    { caption: 'host', facts: [] },
    {
      caption: 'live',
      facts: [],
      counters: {
        'cpu-busy': 0,
        'cpu-total': 0,
        'memory-used': 0,
        'memory-total': 0,
        'swap-used': 0,
        'swap-total': 0,
        received: 0,
        sent: 0,
        load: 0,
        time: 0,
        ...values,
      },
    },
  ],
});

/**
 * The element as the server writes it: a meter and a value for each reading.
 *
 * @param {string | null} [source]
 * @returns {HTMLElement}
 */
function stats(source = '/admin/machine/v1/system') {
  const element = document.createElement('machine-stats');

  if (source !== null) element.setAttribute('data-source', source);

  element.innerHTML = `<table>${READINGS.map((name) =>
    `<tr><td>${name}</td><td><meter data-reading="${name}" min="0" max="100" value="0"></meter></td>`
    + `<td data-reading="${name}"></td></tr>`).join('')}</table>`;

  document.body.append(element);

  return element;
}

/** Two seconds, and the fetch and its reading resolved. */
async function tick() {
  mock.timers.tick(2000);
  await new Promise((resolve) => { setImmediate(resolve); });
}

/**
 * What a reading's value says.
 *
 * @param {HTMLElement} element
 * @param {string} name
 */
const said = (element, name) => element.querySelector(`td[data-reading="${name}"]`)?.textContent;

/**
 * How full a reading's meter is.
 *
 * @param {HTMLElement} element
 * @param {string} name
 */
const meter = (element, name) => element.querySelector(`meter[data-reading="${name}"]`)?.getAttribute('value');

test('the first answer shows the moment, and every one after it the rates since', async () => {
  const element = stats();

  answers.push(
    reading({ 'memory-used': 512, 'memory-total': 1024, received: 1024, sent: 2 ** 70, load: 150, time: 1000 }),
    reading({
      'cpu-busy': 50,
      'cpu-total': 100,
      'memory-used': 512,
      'memory-total': 1024,
      'swap-used': 1,
      'swap-total': 4,
      received: 2048,
      sent: 2 ** 70,
      load: 150,
      time: 3000,
    }),
  );

  await tick();

  assert.equal(asked.at(-1)?.url, '/admin/machine/v1/system');
  assert.deepEqual(asked.at(-1)?.init, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
  assert.equal(said(element, 'cpu'), '', 'one moment says nothing of how busy');
  assert.equal(said(element, 'memory'), '512 B of 1.0 KiB (50%)');
  assert.equal(meter(element, 'memory'), '50');
  assert.equal(said(element, 'swap'), '');
  assert.equal(said(element, 'received'), '1.0 KiB');
  assert.equal(said(element, 'sent'), '1024.0 EiB', 'past the largest unit, the number grows');
  assert.equal(said(element, 'load'), '1.50');

  await tick();

  assert.equal(said(element, 'cpu'), '50%');
  assert.equal(meter(element, 'cpu'), '50');
  assert.equal(said(element, 'received'), '512 B/s');
  assert.equal(said(element, 'sent'), '0 B/s');
  assert.equal(said(element, 'swap'), '1 B of 4 B (25%)');
  assert.equal(meter(element, 'swap'), '25');

  answers.push(reading({ time: 3000 }));
  await tick();

  assert.equal(said(element, 'cpu'), '0%', 'no time passing is no work done');

  element.remove();
});

test('an answer that is not the admin\'s data stops the asking', async () => {
  const cases = [null, 'text', { sections: 'none' }, { sections: [null, { caption: 'host' }] }, new TypeError('offline')];

  for (const answer of cases) {
    const element = stats();

    answers.push(answer);
    await tick();

    const count = asked.length;

    await tick();

    assert.equal(asked.length, count, `${JSON.stringify(answer)} was asked again`);
    element.remove();
  }
});

test('with no source it asks its own address', async () => {
  const element = stats(null);

  answers.push(reading({}));
  await tick();

  assert.equal(asked.at(-1)?.url, '');
  element.remove();
});

test('a hidden page is not asked, and a page shown again is', async () => {
  const element = stats();

  Object.defineProperty(document, 'visibilityState', { value: 'hidden', configurable: true });
  document.dispatchEvent(new Event('visibilitychange'));

  const count = asked.length;

  await tick();
  assert.equal(asked.length, count);

  Object.defineProperty(document, 'visibilityState', { value: 'visible', configurable: true });
  document.dispatchEvent(new Event('visibilitychange'));
  answers.push(reading({}));
  await tick();

  assert.equal(asked.length, count + 1);
  element.remove();
});

test('taken off the page, it stops', async () => {
  const element = stats();
  const count   = asked.length;

  element.remove();
  await tick();

  assert.equal(asked.length, count);
});
