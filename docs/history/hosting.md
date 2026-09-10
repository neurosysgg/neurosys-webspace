# History — hosting

Readings of the live host that changed, the extension probes, and the local dev server. What is true
of the host now is in [../deployment.md](../deployment.md).

## The dev server

### 2026-09-05 → 2026-09-09 — the router named before it was a script (`d45f671`, `7e7696b`)

*From CLAUDE.md's "Local dev".*

The script exists so that the router cannot be the part somebody forgets — it was named in this
file and in `tsconfig.json` for a while before it was a script anybody could run.

## What `.htaccess` gets from the host

### 2026-09-05 → 2026-09-06 — compression and caching, off and then on (`7fc6d02`)

*From CLAUDE.md's "What `.htaccess` does to a response".*

**Measured on the live host 2026-09-06, and it is not what the same measurement said the day
before.**

On 2026-09-05 the answer was the opposite: **nothing** was compressed, `main.js` arrived
byte-identical to the file on disk with only an `ETag` and a `Last-Modified`, and no `Cache-Control`
came back at all. Nothing in this repository changed between the two readings. That is the whole
argument for the paragraph below rather than a curiosity — a shared host can gain or lose a module
without telling anybody, and the failure is silent in both directions.

### 2026-09-05 — the cache tiers were once split by a condition (`d45f671`)

*From a comment in `public/.htaccess`, brought to the present tense.*

> This paragraph used to describe an `env=!VERSIONED` condition on the short tier instead…

### 2026-09-07 — the waveform docblock sized on a reading that had changed (`ebf98f0`)

*From `Waveform`'s docblock, which carried the 2026-09-05 reading after the host had moved on.*

> this docblock previously said the opposite — that Strato compressed nothing, so the base64 figure
> was the wire figure. It was written from a reading taken on 2026-09-05, when that was true; the same
> host was serving gzip again on 2026-09-06 with nothing in this repository having changed.

## Dependencies and extensions

### 2026-09-08 → 2026-09-09 — `ext/curl` in `require` for one commit (`791fbc4`, `7e7696b`)

*From CLAUDE.md's "Stack".*

It sat in `require` for one commit, which made `composer.json` claim a dependency the site does not
have and contradicted the paragraph in this file that said it was absent. Two statements of one
fact, disagreeing, which is the failure this whole file is arranged against.

### 2026-09-09 — `ext/dom` checked on the live host by parsing (`17cca79`)

*From CLAUDE.md's "Stack". The procedure it used is current and in deployment.md.*

**Checked on the live host before it was relied on** (2026-09-09: Strato, PHP 8.5.9, `cgi-fcgi`,
`Dom\HTMLDocument` present and parsing), the same way `ext/uri` was — and checked by *parsing*
rather than by asking whether the extension is loaded, because registered and working are two
questions. The probe went up and came down as two `/update` pushes from a detached worktree at
`HEAD`, which is the shape worth reusing: the endpoint mirrors the whole tree, so probing from a
dirty working tree would have shipped the change being checked *for* alongside the check.
