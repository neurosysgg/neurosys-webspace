/**
 * The command-line plumbing tools/build-*.mjs share: a way to fail, a way to name a file, and an
 * argv parsed against the flags a tool actually declares.
 *
 * **This is `NeuroSYS\Tool\Cli` on the other side of the language boundary**, and it is here for the
 * reason that layer exists. `Cli\Command::options()`'s docblock names the failure: both hand-rolled
 * parsers the PHP tooling had grown *"dropped an unrecognised flag in silence, which for
 * merge-coverage meant a mistyped `--clover` reported success and wrote no report"*. The three
 * builders were the parsers nobody came back for — each one its own
 * `process.argv.indexOf('--out')`, so `node tools/build-css.mjs --ou scratch.css` overwrote the
 * committed stylesheet and said it had done what was asked.
 *
 * `fail` and `label` were copied into all three files besides. Neither is interesting; both being
 * in three places is.
 *
 * **Every flag here takes a path**, which is not a simplification of a general parser — it is the
 * whole vocabulary these tools have. `--out`, `--css`, `--js-dir` and `--graph-dir` are the four
 * that exist, and a flag that stood alone would be a different kind of tool. The PHP side has
 * `Option::takesValue()` because `--check` and `--upload` are real there; nothing here needs it,
 * and inventing it would be a case with nothing on the other end of it.
 *
 * No dependencies, and nothing runs on import: these tools have to work on a clone that has never
 * seen `npm install`, which is why `test/basic_test.sh` can rebuild the stylesheet on a bare
 * checkout while it skips everything that needs `tsc`.
 */

import { dirname, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

/** The repository root, one level up from tools/. */
export const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');

/**
 * The three things a build tool needs from its command line.
 *
 * Parsing happens here rather than at the first `path()` call, so an unknown flag is refused before
 * the tool has read a file or deleted a tree — `build-prod.mjs` clears build/dist/ as its first
 * real act, and doing that on the way to reporting a typo would be the wrong order.
 *
 * @param {string} command The tool's name, which prefixes every message it writes.
 * @param {string[]} declared Every flag it accepts, without the dashes. Anything else is refused.
 * @returns {{fail: (message: string) => never, label: (file: string) => string,
 *            path: (name: string, fallback: string) => string}}
 */
export function cli(command, declared) {
  /**
   * Exits with a reason. Declared as returning `never` so it can stand in an expression — it is
   * `return`ed out of `build-assets.mjs`'s `shipped()`, where the alternative is a `catch` block
   * that falls off the end and hands `undefined` back as if it were a module's bytes.
   *
   * @returns {never}
   */
  const fail = (message) => {
    console.error(`${command}: ${message}`);
    process.exit(1);
  };

  /** Repo-relative, forward-slashed — what the markers and the error messages say. */
  const label = (file) => {
    const path = relative(ROOT, file);

    return path.startsWith('..') ? file : path.split(/[\\/]/).join('/');
  };

  const given = new Map();
  const argv = process.argv.slice(2);

  for (let i = 0; i < argv.length; i++) {
    const argument = argv[i];

    if (!argument.startsWith('--')) {
      fail(`'${argument}' is not an option. This tool takes flags only: ${list(declared)}.`);
    }

    const split = argument.indexOf('=');
    const name = split === -1 ? argument.slice(2) : argument.slice(2, split);

    if (!declared.includes(name)) {
      fail(`unknown option '--${name}'. This tool takes: ${list(declared)}.`);
    }

    // `--out=` and a bare `--out` at the end of the line are the same mistake typed two ways, so
    // they are refused together — the same rule `Cli\Input::parse()` states on the PHP side.
    const value = split === -1 ? argv[++i] : argument.slice(split + 1);

    if (value === undefined || value === '') {
      fail(`option '--${name}' needs a path.`);
    }

    given.set(name, value);
  }

  /**
   * A flag's value, resolved to an absolute path.
   *
   * **The fallback is required, so there is no such thing as a required flag here.** Every one of
   * the four these tools declare has a default — `--out` is where the committed artefact already
   * lives, `--js-dir` and `--css` are what a normal build reads — because the whole point of the
   * defaults is that `npm run build` takes no arguments at all. A branch for a flag with no default
   * would be a case with nothing on the other end of it.
   *
   * @param {string} name
   * @param {string} fallback Where the tool reads or writes when the flag is absent.
   * @returns {string}
   */
  const path = (name, fallback) => {
    const value = given.get(name);

    return value !== undefined ? resolve(value) : fallback;
  };

  return { fail, label, path };
}

/**
 * The declared flags, as a sentence names them.
 *
 * @param {string[]} declared
 * @returns {string}
 */
function list(declared) {
  return declared.map((name) => `--${name}`).join(', ');
}
