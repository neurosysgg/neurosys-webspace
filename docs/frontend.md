# Front end — TypeScript and CSS

How `assets/ts/` and `assets/css/` are arranged, what a custom element here is allowed to do, and
what to do when you need to add one. The server side has its own doc
([architecture.md](architecture.md)); the facts both sides state have a third
([contracts.md](contracts.md)). How any of this got the way it is lives in
[history/frontend.md](history/frontend.md).

No framework and no runtime dependency. TypeScript compiles to browser-native ES modules; the
stylesheet is concatenated from its parts. Both outputs are committed, and both are what a person
develops against — the tree in `public/` is 49 separate modules, plain `tsc` output that no bundler
has touched. What deploys is a different tree, bundled by esbuild; see *Debug and prod* below.

---

## The build

```
assets/ts/  ──tsc──────────────────────→  public/assets/js/               ← the debug tree: generated, committed
assets/css/ ──tools/build-css.mjs──────→  public/assets/css/style.css     ← generated, committed
both        ──tools/build-assets.mjs───→  src/NeuroSYS/AssetManifest.php  ← generated, committed
public/     ──tools/build-prod.mjs─────→  build/dist/                     ← the prod tree: generated, gitignored, deployed
```

| Command | Does |
|---|---|
| `npm run build` | `tsc`, then the stylesheet, then the asset manifest — last, because it hashes both outputs |
| `npm run build:css` | the stylesheet only |
| `npm run build:assets` | the asset manifest only |
| `npm run build:prod` | `npm run build`, then derives `build/dist/` — see *Debug and prod* |
| `npm run watch` | `tsc --watch`. **Does not build the CSS or the manifest.** |
| `npm run dev` | `php -S` with `tools/dev-router.php`. The router is not optional. |
| `npm run check` | `tsc` over all three trees — `assets/ts/`, `tools/*.mjs`, `test/js/*.mjs` |
| `npm test` | `node --test` against the compiled output |
| `npm run coverage` | the same, with 100% thresholds |

**Never hand-edit `public/assets/js/`, `public/assets/css/style.css` or
`src/NeuroSYS/AssetManifest.php`.** They are build output and the next build overwrites them. The
generated stylesheet carries a marker comment above each block naming the part it came from — edit
that part.

### Why the output is committed

`deploy.sh` builds the shipped tree out of `public/` in the working tree. Nothing builds on the
server, so a forgotten rebuild would ship stale JS or a stale stylesheet and nothing else would
notice. The verify script therefore rebuilds all three outputs and diffs — a drifted output is a
failing test.

The CSS check needs only `node`, so it runs on a bare clone. The TypeScript checks need
`node_modules` and are **skipped with a printed NOTE** when `npm install` has never run, so
`composer test` still works without the npm tooling.

### Debug and prod

`public/` is the **debug** tree and is not what ships. It is committed, readable and unbundled
because three things read it by path: `test/js/` imports the modules, `npm run coverage` pins its
100% gate to `public/assets/js/**`, and the verify script diffs it byte-for-byte against a fresh
`tsc`. All three want output a person can read — which is also why the cache version is a path
segment and not a rewritten specifier (see [Cache versioning](#cache-versioning)).

`npm run build:prod` derives the **prod** tree from it. `tools/build-prod.mjs` copies `public/`
wholesale, bundles the whole module graph into one file with esbuild, minifies that with terser,
deletes every source map, and writes a manifest of its own:

```
build/dist/public/                        ← byte-for-byte what lands in the webroot
build/dist/src/NeuroSYS/AssetManifest.php ← the same two URLs under a different stamp, and no
                                            preloads: one file has no graph left to hint at
```

Three things change, and all three are only worth doing at the edge:

- **The maps go.** They are three times the JS they describe, and `inlineSources` puts the whole
  commented TypeScript inside each one. Static assets are served straight by Apache and reach
  neither auth gate, so on the live host those would be public files. The source is on GitHub — a
  reason not to worry about it, not a reason to serve a second copy from Strato.
- **The graph is bundled**, which is the change that pays for the rest: one ~5.8 KB gzipped response
  instead of 49, because gzip's window then spans the whole graph, and no preload list in any
  document. Sizes are in [performance.md](performance.md#the-front-end-payload).
- **The JS is minified.** It earns a little on top of the bundle; gzip over one stream already
  captures most of what identifier mangling would.

**Class names have to survive minification, and that takes both tools.** `NestedElement.tagOf()`
falls back to `constructor.name`, and that is the text of the error a misnested tag throws — the
whole reason those classes are not empty. terser's `keep_classnames` protects only a *declared*
class name, and bundling rewrites some `class X extends Y {}` declarations into
`var X = class extends Y {}`, whose name is inferred from the binding — so without esbuild's
`keepNames` as well, `<terminal-key> must be inside <terminal-field>` reads `must be inside <P>`.
`keepNames`' `__name` helper is emitted once in the bundle, about 256 gzipped bytes.
`mangle.properties` stays off, because `connectedCallback` and `observedAttributes` are contracts
with the browser rather than with us. (History: [history/frontend.md](history/frontend.md).)

**The manifest's stamp differs between the two trees, and that is correct** — a stamp is a claim
about content, and those are different bytes at the same URLs. `deploy.sh` uploads `src/` from the
working tree and then overlays the prod manifest over the one file that differs.

**Six checks, because every failure here is invisible in a browser until it is live.** The verify
script builds the prod tree; asserts it ships no map and names none; asserts the prod manifest
points at the same entry and stylesheet as the committed one, that it preloads nothing, and that the
URL it names has bytes behind it; and then re-runs the **whole client-side suite against the shipped
bytes**. The two manifests are deliberately *not* diffed against each other: the debug one lists
every preload and the prod one none, so a diff would assert away the thing the build exists to do.

The re-run is the check worth the most. `test/js/dom.mjs` takes its tree from `NEUROSYS_JS_DIR`, so
the nesting guards, `TerminalWindow`'s subtree, both embeds and `Navigation` all execute what the
server will send. It works across a bundle because `dom.mjs` reaches the elements through one
`import main.js` and the DOM, never by module path; the three files that do import modules directly
— `enum-parity`, `navigation`, `vocabulary` — are hardcoded to the debug tree and unaffected by what
prod ships. `npm test` and `npm run coverage` use the debug tree by default.

`build-prod.mjs` also refuses a shipped tree holding anything but the one bundle, and refuses a
bundle that still names a relative import — which would mean esbuild resolved nothing and every
module the entry asks for was deleted with the rest of the copy. Both refusals exist because a prod
tree that silently is not bundled still works, just bigger, and has no other symptom.

**`build-assets.mjs` takes a `--graph-dir`.** Its import scanner is anchored to whole lines on
purpose — a looser match walks out of a line and into string literals. terser puts an entire module
on one line, so walking the minified tree would find no imports at all. The graph is a property of
the sources rather than of the formatting, so the readable tree is walked for *which files import
which* and the shipped tree is read for *what is in them*.

### Preloading the module graph

This applies to the debug tree; the prod tree has no graph left to preload.

An ES module graph is discovered a wave at a time, and this one is **five waves deep**: the browser
learns it needs `model/CssClass.js` only after parsing `ConsentGatedEmbed.js`, which it learned about
from `SoundCloudWidget.js`, from `SoundCloudPlayer.js`, from `main.js`. Five sequential round trips
before the last module starts downloading, and none of it is bytes — compressing and stripping
comments leave the number exactly where it was.

`tools/build-assets.mjs` walks the compiled graph and generates `src/NeuroSYS/AssetManifest.php`;
`Layout::modulePreloads()` renders one `<link rel="modulepreload">` per entry, after the stylesheet
because that one blocks rendering and these do not. The preload scanner then sees all of them at
once and the five waves become one, for ~385 gzipped bytes per page.

**The counts:** the debug tree has 49 modules. `main.js` is the `<script src>` itself, 46 more are
reachable from it and preloaded, and two — `model/SectionKind.js` and `model/ArrangementAttribute.js`
— are imported by no module at all: they are mirrors that only `enum-parity.test.mjs` reads, since
the arrangement is server-rendered and no element selects on its values.

`modulepreload` rather than `preload as="script"`: it fetches, parses, compiles *and* inserts into
the module map, so the module is instantiated by the time `main.js` asks. The list is every module
rather than the first wave — the spec lets a browser follow a preloaded module's own imports and
Chrome does, but it is not obliged to and Safari has been uneven, so leaning on it would make the
fix silently partial. `main.js` is deliberately absent: it is the `<script src>` already in flight.

Three checks, because the two failure modes are different. The verify script **rebuilds the manifest
and diffs** it (a module missing from a stale list brings its whole subtree's waterfall back), and
**asks the server for every hinted URL** (the list can be perfectly in step with the graph and still
point at nothing, since the URL base is written by hand in the tool). `ViewTest` asserts the same
existence question against the filesystem, so it fails in the fast suite without a server running.
**Neither failure is visible in a browser** — the page works, it is just slower — which is why all
three exist.

### Cache versioning

Every built asset is served under a path segment naming a hash of the build:
`/assets/js/v-a1b2c3d4/main.js`. That is what lets `public/.htaccess` mark them
`immutable, max-age=31536000` — a URL that names its own content cannot come to mean something else,
so a returning visitor fetches none of it. The segment is not a directory; the server strips it,
Apache by a `RewriteRule` and the dev server by `tools/dev-router.php`.

**Why a path segment and not a filename or a query.** `Tag.a1b2c3d4.js` would break every test that
imports `public/assets/js/model/Tag.js` by name. A `?v=` query on each import specifier is ruled out
because V8 attributes a module loaded through a stamped specifier to a URL
`--test-coverage-include` does not match, which zeroes everything the tests reach through `main.js`
and fails the 100% gate. A path segment costs neither, because a relative specifier resolves against
the URL it was loaded from — so `/assets/js/v-a1b2c3d4/main.js` importing `./model/Tag.js` asks for
`/assets/js/v-a1b2c3d4/model/Tag.js` with **no file rewritten at all**. The compiled JS stays
byte-identical to tsc's output, which is what keeps the drift check a straight diff.

The price is one stamp per build rather than one per file, so any change busts the whole tree — all
49 modules in the debug tree, and the single bundle in the one that ships. At ~6 KB gzipped that is
not worth a second thought.

**`.htaccess` and `dev-router.php` are a mirror** — one rule, two languages — so the verify script
pins that they strip the same pattern, and that *both* `php -S` invocations in it load the router.

**Images are deliberately not versioned.** They are vendored and hand-placed, and reached through
`Platform::icon()` and `Config::COVER_PLACEHOLDER` as plain constants — teaching a Model enum to
consult a build artefact costs more than a calendar TTL on files that change about never. The line
is: assets the build generates get a content hash, assets a person drops in keep a date.

### Why the sources sit outside `public/`

They are neither web-served nor deployed. Source maps still work in the debug tree: `inlineSources`
embeds the TypeScript in the map itself, so DevTools shows `Navigation.ts` without `assets/ts/` being
served. That is why `public/.htaccess` lists `map` — Strato 500s any static file it has no
`SetHandler` for. The prod tree ships no maps; the handler stays for the debug tree, which is what
`npm run dev` serves.

### The compiler settings that are load-bearing

[`tsconfig.json`](../tsconfig.json) runs `strict` plus:

| Setting | Catches |
|---|---|
| `module: nodenext` | an extensionless relative import — a specifier the browser would 404 on cannot ship |
| `noUncheckedIndexedAccess` | an array index assumed to be present |
| `exactOptionalPropertyTypes` | `undefined` smuggled into an optional property |
| `noEmitOnError` | a type error leaving stale or half-written JS in `public/` |

`removeComments` is **on**: the comments already travel inside each map's `inlineSources`, so
DevTools shows the commented source either way, and keeping them in the `.js` as well would be a
copy only the network pays for. The debug tree is still readable — formatted `tsc` output, one
module per source file — it is just uncommented.

---

## Layout

```
assets/ts/
├── main.ts                 entry point — the only <script> Layout.php loads
├── Config.ts               the three facts the client reads out of NeuroSYS\Config
├── Navigation.ts           SPA navigation
├── model/                  the mirrored enums — see contracts.md
└── elements/               one class per file, named for the class
    ├── NestedElement.ts    abstract — the parent guard
    ├── CoverArt.ts
    ├── embed/              ConsentGatedEmbed → SoundCloudWidget → the two players   (cf. Model/Embed/)
    ├── terminal/           TerminalWindow + its five content tags                   (cf. View/Terminal/)
    ├── download/           DownloadList, DownloadCard, DownloadLabel, DownloadMeta
    ├── arrangement/        ReleaseArrangement, ArrangementSection                   (cf. Model/Production/)
    ├── waveform/           DemoWaveform                                             (cf. Model/Waveform)
    └── release/            ReleaseList, ReleaseCard, ReleaseTitle, ReleaseMeta
```

**One class per file, named for the class**, the way `src/NeuroSYS/` is — `<terminal-cursor>` is
`TerminalCursor` in `terminal/TerminalCursor.ts`. The directory is the component, not the file:
`elements/terminal/` and `elements/embed/` sit opposite `View/Terminal/` and `Model/Embed/` on the
server.

Nothing is a loose exported function. It is `Navigation.onNavigate()` or a method on an element, so
a call site says where it came from.

### `main.ts` is the vocabulary

A module registers its tag as a side effect of being imported, so `main.ts` imports every one of
them. That list *is* the site's whole tag vocabulary, and `test/js/vocabulary.test.mjs` pins it —
which matters most for the tags an element builds itself, since those appear in no server response
for the verify script to catch.

The last two lines start the SPA router:

```ts
Navigation.forDocument()?.start();
```

---

## The element model

Every custom element here is one of three kinds. Knowing which you are writing decides everything
else about it.

| Kind | Builds its own subtree | Example |
|---|---|---|
| **Self-building** | yes — the server sends attributes and nothing else | `<terminal-window>`, `<cover-art>`, the two players |
| **Guard** | no — refuses to connect outside its parent | `<terminal-key>`, `<release-card>` |
| **Marker** | no, and never will | `<download-list>`, `<release-list>`, `<release-arrangement>` |

`<demo-waveform>` is the one element that is none of the three: it prepends a canvas and leaves the
server's children where they were, so it neither builds its subtree nor builds nothing.

### Every tag

Every tag a view may emit is registered, and so is every tag an element builds. The ones with no
behaviour of their own are `NestedElement` subclasses — CSS does their styling, the guard is what
they add — but they are declared all the same, so the vocabulary has one place to look rather than
existing only as a CSS selector.

| Module | Tag | Does |
|---|---|---|
| `NestedElement.ts` | — (abstract) | refuses to connect outside the element it belongs inside |
| `embed/ConsentGatedEmbed.ts` | — (abstract) | the gate: its wording, the reserved height, the click, the swap. Every provider's, whatever it plays |
| `embed/SoundCloudWidget.ts` | — (abstract) | SoundCloud's furniture: the widget URL, the attribution, the accent, the iframe. A subclass answers only which resource it plays |
| `embed/SoundCloudPlayer.ts` | `<soundcloud-player track-id permalink secret-token player-style options track-title height>` | one track. Every attribute but `height` is a `SoundCloudPlayerAttribute`; `height` is an `EmbedAttribute`, because the gate that reserves it is every provider's |
| `embed/SoundCloudProfile.ts` | `<soundcloud-profile player-style options height>` | the whole account's latest tracks. Carries no id, handle or title — there is no release to take them from, and the handle is `Config.HANDLE`, which this side already mirrors |
| `CoverArt.ts` | `<cover-art src fallback alt>` | builds its `<img>`, falls back to the placeholder when the file host 404s |
| `terminal/TerminalWindow.ts` | `<terminal-window label command fields [narrow]>` | builds its whole subtree from a declared `Terminal` — the command, every row, the cursor |
| `terminal/TerminalCommand.ts` | `<terminal-command>` | guard; CSS draws the `$` |
| `terminal/TerminalField.ts` | `<terminal-field tone>` | guard; `tone` decides which half the stylesheet accents |
| `terminal/TerminalKey.ts` `TerminalValue.ts` | `<terminal-key>` `<terminal-value>` | guards, inside a row |
| `terminal/TerminalCursor.ts` | `<terminal-cursor>` | guard; CSS draws the `$` and the blink |
| `download/DownloadList.ts` | `<download-list>` | nothing, deliberately — see *Markers* |
| `download/DownloadCard.ts` … | `<download-card format>` `<download-label>` `<download-meta>` | guards only |
| `waveform/DemoWaveform.ts` | `<demo-waveform peaks duration>` | a demo's whole shape behind its player: prepends a canvas, leaves the server's children alone |
| `arrangement/ReleaseArrangement.ts` | `<release-arrangement>` | nothing, deliberately — the sections are server-rendered |
| `arrangement/ArrangementSection.ts` | `<arrangement-section kind>` | guard; `kind` decides which accent the stylesheet gives it |
| `release/ReleaseList.ts` | `<release-list>` | nothing, deliberately — see *Markers* |
| `release/ReleaseCard.ts` … | `<release-card slug>` `<release-title>` `<release-meta>` | guards only |

### Self-building elements

A view emits the tag and its attributes; the element builds everything below it. That is why
`ReleaseView::heroSection()` emits one tag.

```ts
export class CoverArt extends HTMLElement {
  private wired = false;

  connectedCallback(): void {
    if (this.wired) return;   // connectedCallback fires again on a move
    this.wired = true;
    // …read attributes, build children, replaceChildren()
  }
}
```

The `wired` / `built` flag is not optional: `connectedCallback` fires again every time the element
is moved in the DOM.

### Guards — `NestedElement`

`<terminal-key>` loose in a page is the same mistake as a misspelled tag, and would fail the same
silent way: an inert inline box, styled by a selector that does not match, nothing in the console.

[`NestedElement`](../assets/ts/elements/NestedElement.ts) walks up from itself looking for an
instance of the element it belongs inside, and throws if it does not find one:

```ts
export class TerminalKey extends NestedElement {
  protected parent(): CustomElementConstructor { return TerminalField; }
}
```

The check is **"somewhere inside", not "directly under"** — a card's tags sit inside the anchor that
has to stay a real link: `<download-card>` wraps `<a>` wraps `<download-label>`.

Note that a throw in `connectedCallback` does not reach whoever inserted the element. The browser
reports it as an uncaught error, which is loud enough to notice and is how the tests capture it.

### Markers — and why three elements build nothing on purpose

`<download-list>` and `<release-list>` are plain `HTMLElement` subclasses with no body, and that is
the finished implementation rather than a stub. What they wrap is a real server-rendered
`<a data-no-spa>`: downloads have to work without JS, and `data-no-spa` has to land on a real anchor
or the SPA router fetches the 303 and swallows it.

The card tags **wrap** their anchors rather than replacing them — links keep working without JS,
keyboard access is unchanged — and the wrappers are `display: contents`, so the anchor is still the
card to layout.

`<release-arrangement>` is a marker for a different reason. A JSON attribute and a built subtree —
what `<terminal-window>` does — was the obvious shape for it, and it is server-rendered instead,
because its sections are text and a no-JS release page already loses the cover and the terminal.
It carries a name, a guard over its `<arrangement-section>`s, and nothing built.

### What stays native

`<a>`, `<button>`, `<h1>`/`<h2>`, `<img>`, `<p>`, `<section>` — anything that carries meaning or
behaviour the browser already provides.

**And `<audio>`, which is the strongest case of the rule.** A demo's player could have been an
element like `<soundcloud-player>`; it is not, because the browser's own control seeks, takes a
keyboard, is announced by a screen reader, and works with JavaScript off. A release page's empty box
with JS off is a cost this site accepts, since the page is still a page. **A demo is the audio**, so
the same cost there would be the whole thing missing. See [demos.md](demos.md).

---

## The terminal

`ReleaseView::heroSection()` declares a `Terminal` — a label, a `TerminalCommand` and typed
`TerminalField` rows — and emits one tag. `<terminal-window>` builds the command, every row and the
cursor.

**The command line is an object rather than a string**, because both views that build one
interpolate something they cannot quote: a release title, and — on the 404 — the request path, which
is the one string on this site a visitor writes in full. `new TerminalCommand('find', $path)` quotes
the value and leaves a leading-dash flag bare, so `find "/some odd path"` reads as the shell
transcript it is dressed as. It is **not** a security boundary and must not be read as one: the
result is assigned to `textContent` by `<terminal-window>`, so it is never at risk of being anything
but text. What quoting buys is legibility, including when what it is quoting is hostile.

The rows cross as JSON in an attribute, which is the only shape that stays generic across a
release's five metadata rows and a 404's single error line — see
[contracts.md](contracts.md#2-json-in-an-attribute--the-terminal-rows).

`TerminalTone` decides how a row reads, and the stylesheet decides which half of it that colours:
`ok` accents the value, `error` accents the key. The tone is on the row rather than on one half of
it, so that stays a styling decision.

---

## The embed hierarchy

`SoundCloudEmbed` builds no markup of its own. It renders `<soundcloud-player>` with the release's
facts as attributes, and the element builds the widget URL and the attribution from them: the
**server sends the release's facts**, and the **element owns the provider's furniture** — the accent
colour, the artist handle, the attribution styling and the iframe attributes all live in
`SoundCloudWidget.ts`. Adding a provider is an `Embed` implementation and a `ConsentGatedEmbed`
subclass, and nothing else.

Three layers, split by **what varies**:

```
ConsentGatedEmbed            every provider's gate
  │                          wording · reserved height · the click · the swap
  └─ SoundCloudWidget        SoundCloud's furniture
       │                     accent · attribution · iframe · widget URL
       ├─ SoundCloudPlayer   one track
       └─ SoundCloudProfile  the whole account
```

A subclass of `SoundCloudWidget` answers exactly three questions:

| Method | `<soundcloud-player>` | `<soundcloud-profile>` |
|---|---|---|
| `resourceUrl()` | the track's API URN | the profile URL |
| `subject()` | the track title | `Config.NAME` |
| `attributionTarget()` | the track's page | `null` — a profile *is* the artist |

### There are two axes here, and only one is the provider

A **provider** is SoundCloud versus somebody else. A **resource** is one track versus the whole
account. The home page carries the second kind — `SoundCloudProfileEmbed` → `<soundcloud-profile>`
— which is the same player pointed at the profile URL, and SoundCloud resolves that to the latest
tracks.

That is why the gate is shared client-side but `Embed` is not shared server-side:
`SoundCloudProfileEmbed` deliberately does not implement it. That interface is what a `Release`
holds, and `Release::$embed` is typed for it — a profile player assignable to a release would be
nonsense. It also carries **no id, handle or title**: there is no release to take them from, and
the handle is `Config::HANDLE`, which the element already mirrors. Its output is strictly emptier
than the track player's — both suites assert the served page names no SoundCloud address at all,
and for the profile, no artist either.

### The gate is the point

`buildEmbed()` is called from the click handler and nowhere else, so the iframe does not exist
before the visitor consents. Nothing is requested from SoundCloud until then — and the served page
names no SoundCloud address at all, so there is nothing for a browser to preconnect or prefetch
either.

The consent notice is **written by the element**, not the server. That is sound for the same reason:
the transfer it warns about needs a click, the click needs the script, and the script writes the
notice. The provider is the element — `<soundcloud-player>` knows it is SoundCloud — and the wording
is asserted in `test/js/soundcloud-player.test.mjs`, where it is written.

### Two details that look like mistakes

- **The height is an `EmbedAttribute`, not a `SoundCloudPlayerAttribute`.** It is what the gate
  reserves, and the gate is every provider's; in a provider's enum it would make the
  provider-agnostic `ConsentGatedEmbed` import one provider's vocabulary to size a box, and a second
  provider emit an attribute named after the first. The wire format is `height="300"` either way.
- **The attribution styling goes through the CSSOM**, not a `style` attribute. Same rendering, but
  `element.style` is not something CSP governs — which is what lets `style-src` stay strict.

---

## SPA navigation

[`Navigation`](../assets/ts/Navigation.ts) intercepts internal link clicks, fetches the page as a
content fragment, and swaps `#content`. Download links carry `data-no-spa` to bypass it and trigger
a real navigation — otherwise the 303 would be consumed silently by the fetch.

```
click on a[href^="/"]
  → not modified/middle-click, no data-no-spa, resolved origin === location.origin
  → preventDefault, pushState
  → fetch with X-Requested-With: XMLHttpRequest
  → ViewResponse sends <title> + the fragment
  → read and decode the title, strip it, assign the rest to #content.innerHTML
  → dispatch neurosys:navigate, scroll to top
```

### The four things to understand before touching it

**1. The selector matches the href *attribute*; the code uses the resolved `link.href`.**
`//evil.example/x` starts with a slash exactly as `/releases` does — a protocol-relative URL is a
different origin wearing a path's clothes. So `onClick` reconciles the two readings and hands
anything cross-origin back to the browser. Nothing the server emits is protocol-relative —
`Element` refuses to write one — so this is the client's half of the same rule.

**2. `go()` ends in an `innerHTML` assignment.** That is safe only because the fragment is
same-origin and was built by the server's markup tree, where every value is escaped by `Text` and
every URL attribute is scheme-checked. The guarantee is *inherited*, not enforced here — anything
that ever puts markup into `#content` from another source reopens DOM XSS, and nothing in that file
would notice.

**3. Only the most recent navigation may write to the page.** `pushState` runs before the fetch, so
the address bar already says where the *last* click went — and without a guard whichever response
lands last wins `#content`, so a slow first click beating a fast second one leaves the URL and the
page disagreeing with nothing reporting it. `go()` takes a number on the way in and checks it is
still the current one after each `await`. There is an `AbortController` as well, but the counter is
what makes the guarantee: a fetch can resolve in the instant before an abort is observed, and then
only the number stands between a stale response and the page. `navigation.test.mjs` stages that gap
deliberately, one microtask wide.

**4. Nothing re-runs after a swap.** The browser upgrades any custom element it parses, including
markup assigned through `innerHTML`, so the gate and the cover wire themselves on arrival. The
`neurosys:navigate` event stays for anything that is *not* an element — subscribe with
`Navigation.onNavigate()` rather than the string.

### Failure is always "hand it back to the browser"

A non-`ok` response or a thrown fetch calls `location.assign(url)` — except an abort, which is the
router cancelling itself rather than a failure, and handing the browser a URL the visitor has
already left would undo the navigation that replaced it. `pushState` has already run by
then, so leaving the visitor there would strand them on a page they never got. Likewise
`forDocument()` returns `null` when there is no `#content`, which switches the whole router off with
every link still working.

One known limit, left as is because nothing here can reach it: the `popstate` handler re-fetches
`location.pathname`, so a query string or fragment on the entry being returned to would be dropped.
No view on this site emits either — `Element` refuses an `href` that is not a path of ours, and
nothing writes a `?` or a `#` — so there is currently nothing to lose. `onClick` already does the
right thing, handing `go()` the whole resolved href.

---

## The stylesheet

A component's CSS lives with the component, and `public/assets/css/style.css` is generated from it —
the same arrangement as `assets/ts/` → `public/assets/js/`, for the same reasons.

```
assets/css/
├── main.css          the @import list; the order IS the cascade
├── base/             tokens.css (:root), elements.css (* html body a)
├── layout/           shell.css (what Layout.php emits), utilities.css
├── views/            home.css, release.css, demo.css, stats.css           (cf. src/NeuroSYS/View/)
└── elements/         card.css, terminal.css, CoverArt.css, embed.css,
                      download.css, arrangement.css, waveform.css           (cf. assets/ts/elements/)
```

[`main.css`](../assets/css/main.css) is the CSS half of what `main.ts` is for the elements: an
explicit list, in order, that nothing derives from a directory walk.

[`tools/build-css.mjs`](../tools/build-css.mjs) inlines each `@import`, because the source form is
never the served one — left in place, the browser would discover each part only after parsing the
one before it, and a typo'd href would 404 in silence with that component unstyled. Inlining makes
both a build error instead.

The build also refuses:

- a part imported twice
- an import that does not resolve
- **a rule in a manifest** — a file either orders parts or is one, so an ordering decision is never
  made twice

### The build tools' command line

`build-css.mjs`, `build-assets.mjs` and `build-prod.mjs` share
[`tools/build-cli.mjs`](../tools/build-cli.mjs) — a `fail`, a `label`, a `read`, and an argv parsed
against the flags a tool declares. It is `NeuroSYS\Tool\Cli` on the other side of the language
boundary, and there for the reason that layer exists: an undeclared flag, a flag with no path and
`--out=` are all refused by name, because a misspelled `--out` that is silently ignored overwrites
the committed stylesheet and reports success. Every flag there takes a path, which is not a
simplification but the whole vocabulary — there is no `takesValue()` because nothing on that side
stands alone. No dependencies and nothing runs on import, so the stylesheet rebuilds on a clone that
has never seen `npm install`.

`read(file) ?? fail(…)` is the idiom for reading a file. `read` is `Support\File::read()` in the
other language — absent and unreadable are one answer, because every caller turns them into one
message — and the expression narrows to a `string`, where a `try`/`catch` around a `never`-returning
`fail` does not. (History: [history/frontend.md](history/frontend.md).)

### The invariants

- **Every `Tag` case is styled by exactly one part**, asserted in both directions by `HtmlTest`.
  `elements/` mirrors `assets/ts/elements/` at the component level, because there the directory is
  the component: `terminal.css` styles `<terminal-window>` and the five tags it builds. Without the
  check, renaming a `Tag` case leaves the stylesheet quietly not matching.
- **Every `CssClass` case has a rule, and every rule has a case** — also `HtmlTest`, which parses
  the generated stylesheet.
- **Attribute-value selectors are pinned to the enums they come from.** `arrangement.css` selects
  `[kind="drop"]` and `terminal.css` selects `[tone="error"]` — `SectionKind` and `TerminalTone`
  backing values, which `enum-parity.test.mjs` compares PHP↔TS but cannot see in CSS. `HtmlTest`
  asserts them both ways, with the vocabularies declared in one constant so a *new* attribute-value
  selector fails until somebody says where its values come from. `TerminalTone::Plain` deliberately
  has no rule: the plain row is the absence of an accent rather than a rule saying so.
- `card.css` is the one part named for a concept rather than a component, because the catalogue
  entry and the download entry genuinely share a look. It is meant to be conspicuous the way
  `Element::containingHtml()`'s one call site is: the list is pinned to that one file, so a second
  has to be argued for. So there is no `elements/release.css` — the `release-*` tags are card, apart
  from `<release-arrangement>`, which is its own component in `arrangement.css`.

### What deliberately did not move to a runtime

Shadow DOM or `adoptedStyleSheets` would be the literal way to bundle a stylesheet with its element.
Both would cost a flash of unstyled content on every load and leave a no-JS visitor an unstyled page
— spending exactly the reserved-box guarantee below. Colocation is a property of the *sources*; the
browser still gets one static file under a strict `style-src`.

---

## The no-JS cost

Self-contained elements mean a no-JS visitor loses real content, not just polish. Worth re-reading
whenever another fragment moves client-side.

| Page | Without JS |
|---|---|
| Home | hero reads in full — wordmark, tagline, `releases →`; `latest tracks` is a reserved empty box |
| Release | no cover image, empty player frame, **no terminal** — so no bpm, key or genre |
| 404 | no error line |
| Demo | nothing lost: the player is a native `<audio>`; only the waveform behind the card is absent |
| Everything | links, navigation, downloads, titles, taglines, imprint and privacy are unaffected |

The home page's loss is a convenience rather than a route, because the footer's plain link to the
profile is on every page; `PageTest` pins both halves. The CSS reserves every box, so nothing
reflows when the script lands. A `<noscript>` inside `<terminal-window>` and `<cover-art>` carrying
the same content would buy the rest back, for the price of rendering it twice.

**The demo page is the distinction worth keeping hold of.** Its waveform *is* drawn by an element
and is absent with JS off, and that costs nothing: an element that builds what a page is about spends
the guarantee; one that draws behind a card the server already wrote whole does not.

---

## Recipes

### Add an element

1. A `Tag` case — **on both sides**. See [contracts.md](contracts.md).
2. A module under `assets/ts/elements/`, named for the class, in the directory for its component.
3. `customElements.define(Tag.Thing, Thing)` at the bottom of the file.
4. An import in `main.ts`.
5. A rule in the matching `assets/css/elements/` part — every `Tag` case must be styled by exactly
   one, and `HtmlTest` checks it.
6. If it is not a root: extend `NestedElement` and implement `parent()`.
7. `npm run build`, then commit the output.

Three checks cover this from three directions: `ViewTest` pins the set a view may emit *and* names
the five tags no view emits because `<terminal-window>` builds them, the verify script checks every
custom tag in a real response is a `Tag` case, and `vocabulary.test.mjs` checks every `Tag` case is
registered once `main.js` has run — the direction that catches a forgotten import.

### Add an embed provider

Client half — see [architecture.md](architecture.md#add-an-embed-provider) for the server half.

```ts
export class ThingPlayer extends ConsentGatedEmbed {
  protected platform(): Platform { return Platform.Thing; }
  protected buildEmbed(): DocumentFragment { /* only called after consent */ }
}

customElements.define(Tag.ThingPlayer, ThingPlayer);
```

The gate — its wording, the reserved height, the click, the swap — is inherited and must not be
reimplemented. If the new provider has two resource kinds the way SoundCloud does, add a middle
layer beside `SoundCloudWidget` rather than branching inside one class.

### Add a CSS part

1. The file, under the directory for its layer.
2. An `@import` in `main.css`, in the right position — the order *is* the cascade.
3. `npm run build:css`, then commit `style.css`.

A part declares rules and never imports; a manifest imports and never declares. The build enforces
both.

### Add a mirrored value

Read [contracts.md](contracts.md) first. Short version: the PHP enum, the TS mirror, and the parity
test will compare them by name, backing value and declaration order.

---

## Testing

`npm test` runs `node --test` against the **compiled output** in `public/assets/js/` — the same
files the browser loads, so a build that never ran is a failing test rather than a passing one.
`jsdom` provides the DOM; both it and TypeScript are dev-only.

`npm run coverage` is a gate rather than a report: 100% for lines, branches and functions, with
`--test-coverage-include-all` so a module nothing imports is reported as uncovered rather than not
reported at all. That is affordable here and nowhere else — these are 49 small files with one job
each.

See [testing.md](testing.md) for the full picture, including the PHP suites.

---

## Further reading

- [architecture.md](architecture.md) — the PHP side
- [contracts.md](contracts.md) — the PHP↔TypeScript seam and what guards it
- [testing.md](testing.md) — both suites and the invariants
- [performance.md](performance.md) — the measurements behind the build decisions here
- [branding.md](branding.md) — why brand assets are vendored, and the consent reasoning behind the gate
- [history/frontend.md](history/frontend.md) — how the build, the embed attributes and the build
  tools got the way they are
