# History — the front end

The build, cache versioning, the embed attributes, the stylesheet's pins and the build tools'
command line. What is true now is in [../frontend.md](../frontend.md) and
[../contracts.md](../contracts.md); the payload figures these changes moved are in
[performance.md](performance.md).

## Cache versioning

### 2026-09-05 — a `?v=` query cost the coverage gate (`d45f671`)

The first form of cache versioning stamped each import specifier rather than the path.

`?v=` on each import specifier was written, worked in a browser, and cost the front end's **100%
coverage gate**: V8 attributes a module reached through a stamped specifier to
`…/CoverArt.js?v=48f0b166`, which `--test-coverage-include` does not match, so everything the tests
reach through `main.js` reported zero and the gate fell to 68%.

### 2026-09-05 — the two `php -S` invocations disagreed (`d45f671`)

The verify script pins that `.htaccess` and `dev-router.php` strip the same pattern, and that *both*
`php -S` invocations in it load the router. That second check exists because they had diverged:
`composer test` was green and `composer coverage` was not, since only one of the two had been given
the router.

## The prod build

### 2026-09-05 — `build-assets.mjs` grew a `--graph-dir` (`0e0d0e6`)

**Why `build-assets.mjs` grew a `--graph-dir`.** Its import scanner is anchored to whole lines, and
that anchoring is load-bearing (a looser version once walked out of `export class Config {` into the
string below it). terser puts an entire module on one line, so walking the minified tree finds no
imports at all and the tool reports `main.js` as reaching nothing.

### 2026-09-08 — bundling, and the paragraph that had argued against it (`e49afef`)

Until this commit the prod tree was the debug tree minified file by file, and CLAUDE.md's *Cache
versioning* section argued that bundling would cost the tests. The commit replaced that argument
with this one:

**This paragraph used to argue the other way, and the argument was wrong rather than merely
outdated** — which is worth recording, because it was wrong in a checkable way for a long time. It
said bundling "would mean the element tests could no longer import individual modules", and that
the property it would cost is "that the shipped files *are* the tested files". Neither was true of
this suite as written: `test/js/dom.mjs` loads the whole vocabulary through a single
`await import(${JS}/main.js)` and every element test then works through the DOM, so **not one of
them names a module path**. The three files that do import modules directly — `enum-parity`,
`navigation`, `vocabulary` — are hardcoded to `../../public/assets/js/…`, the debug tree, and are
unaffected by what prod ships. So `NEUROSYS_JS_DIR` pointed at a bundle still runs the whole suite
against the exact bytes the server sends; the property survives, it is just one file now.

The lesson is narrower than "measure things". The claim was about *the tests*, it was written in
the file the tests live under, and checking it was ten minutes of reading. A cost stated once and
then cited tends to stop being re-derived — so the price was carried for as long as it took someone
to ask, and it was **6,997 gzipped bytes, 54.7% of the JS, plus 48 requests and ~385 bytes of every
document**. See `docs/performance.md`.

What bundling changed, as the build section described it at the time:

- **The graph is bundled**, which is the change that pays for the rest. 12,798 gzipped bytes across
  49 responses → **5,801 in one**: 6,997 saved, 54.7%, and 48 fewer requests. It also empties the
  preload list, taking another ~385 gzipped bytes off *every document*. On disk: 484K → 16K.
- **The JS is minified.** Worth much less than it was and still worth doing. The old figure was
  2,252 gzipped bytes measured *per file*; most of that is now won by the compression above, which
  is the same distinction `tsconfig`'s superseded ~260-byte figure was drawing from the other side.

### 2026-09-08 — the first bundled build mangled a class name (`e49afef`)

**`keep_classnames` is load-bearing, and on its own it is no longer enough.**
`NestedElement.tagOf()` falls back to `constructor.name`, and that is the text of the error a
misnested tag throws — the whole reason those classes are not empty. Bundling rewrites some
`class X extends Y {}` declarations into `var X = class extends Y {}`, whose name is *inferred from
the binding* rather than declared, and `keep_classnames` protects only a declared one — so terser
mangles the binding and `<terminal-key> must be inside <terminal-field>` becomes `must be inside
<P>`. **That is not hypothetical: it is what the first bundled build actually did, and the verify
script's re-run against the shipped bytes is what caught it.** esbuild's `keepNames` emits an
explicit name assignment that survives any mangling, and it is exactly the option this file used to
give as the reason for *terser rather than esbuild* — a `__name` helper in every one of 42 modules,
costing 1,868 of the 2,252 bytes minifying won. In one bundle the helper is emitted once: **256
gzipped bytes**. The objection was real, and bundling is what answered it.

Of the re-run itself: that last check is the one worth the most, and it has earned it —
`test/js/dom.mjs` takes its tree from `NEUROSYS_JS_DIR`, so the nesting guards, `TerminalWindow`'s
subtree, both embeds and `Navigation` all execute what the server will send, and **it is what caught
the mangled class name the first bundled build shipped**, which nothing else here could have seen.

### 2026-09-08 — two checks the bundle replaced (`e49afef`)

The manifests are no longer diffed against each other — they now differ on purpose, since one lists
46 preloads and the other lists none, so a diff would assert away the thing the build exists to do.

`build-prod.mjs` refuses a bundle that still names a relative import. The check it replaced refused
a tree where any module was byte-identical to its readable original — the copy is what would deploy,
under a stamp saying otherwise, and a page that is quietly bigger than it claims has no other
symptom.

## Embed attributes

### 2026-09-05 — the height left SoundCloud's enum (`052181c`)

`EmbedAttribute` is what any gated embed carries; `SoundCloudPlayerAttribute` is SoundCloud's own.
The height used to live in the second one, which meant `ConsentGatedEmbed` — the provider-agnostic
base class — imported one provider's enum to find out how much space to reserve, and a second
provider would have had to emit an attribute named after the first. Nothing about the wire format
changed: it is still `height="300"`.

### 2026-09-05 — `loaded` gained a PHP side (`052181c`)

Two names have no PHP side and so no parity test — `tone` and `--player-height`. `loaded` was a
third until `EmbedAttribute` gained a PHP side: it is still written only by the client, but it now
has a case on the other end for the parity test to compare against.

## The stylesheet

### 2026-09-08 — the attribute values it selects on (`2405b8f`)

Pinning every `Tag` case to exactly one part closed the first unchecked mirror — a tag name in the
CSS had nothing on the other end of it, so renaming a case left the stylesheet quietly not matching,
which on a dark page reads as a layout bug rather than a typo.

**The attribute *values* it selects on were the other one, and they are a third copy.**
`arrangement.css` selects `[kind="drop"]` and `terminal.css` selects `[tone="error"]`, which are
`SectionKind` and `TerminalTone` backing values — pinned PHP↔TS case for case by
`enum-parity.test.mjs`, and in the stylesheet pinned by nothing at all. So a rename made on both
typed sides together passes every parity test there was and stops the CSS matching, and a drop that
draws in the default accent reads as a design decision. `HtmlTest` now asserts it both ways.

## The build tools' command line

### 2026-09-06 — `--ou scratch.css` (`15397cd`)

`build-css.mjs`, `build-assets.mjs` and `build-prod.mjs` share `tools/build-cli.mjs`. Each of the
three used to hold its own `process.argv.indexOf('--out')`, so
`node tools/build-css.mjs --ou scratch.css` overwrote the committed stylesheet and reported success:
the exact failure `Command::options()`'s docblock describes, still live in the tools nobody came
back for.

### 2026-09-09 — `read` arrived with the type checker (`7e7696b`)

`read` is the fourth and arrived with the type checker. It is `Support\File::read()` in the other
language and collapsed for the same reason — a file that is absent and a file that is present and
unreadable are two causes every caller was already turning into one message — but it is also what
makes the narrowing work: `read(file) ?? fail(…)` is a `string`, where the `let source; try { … }
catch { fail(…) }` it replaced is not, because control flow does not follow a `never` return
through a destructured binding. Two of the four tools had the try/catch shape and two already had
the expression shape, in the same file, which is the state `build-cli.mjs` exists to end.

## Smaller notes

### 2026-09-05 — the hero became one tag (`d1bc423`)

From frontend.md's section on self-building elements: that is why `ReleaseView::heroSection()` is
one tag where it used to be a subtree.

### 2026-09-05 — `Vary: X-Requested-With` became load-bearing (`8e931d3`)

From contracts.md, on the fragment request: it was harmless while nothing cached; it stopped being
harmless the moment `ViewResponse` started sending an `ETag`.
