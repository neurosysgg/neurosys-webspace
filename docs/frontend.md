# Front end — TypeScript and CSS

How `assets/ts/` and `assets/css/` are arranged, what a custom element here is allowed to do, and
what to do when you need to add one. The server side has its own doc
([architecture.md](architecture.md)); the facts both sides state have a third
([contracts.md](contracts.md)).

No framework, no bundler, no runtime dependency. TypeScript compiles to browser-native ES modules;
the stylesheet is concatenated from its parts. Both outputs are committed.

---

## The build

```
assets/ts/  ──tsc───────────────────→  public/assets/js/     ← generated, committed, deployed
assets/css/ ──tools/build-css.mjs──→  public/assets/css/style.css
```

| Command | Does |
|---|---|
| `npm run build` | both — `tsc` then the stylesheet |
| `npm run build:css` | the stylesheet only |
| `npm run watch` | `tsc --watch`. **Does not build CSS.** |
| `npm run check` | `tsc --noEmit` — types only |
| `npm test` | `node --test` against the compiled output |
| `npm run coverage` | the same, with 100% thresholds |

**Never hand-edit `public/assets/js/` or `public/assets/css/style.css`.** They are build output and
the next build overwrites them. The generated stylesheet carries a marker comment above each block
naming the part it came from — edit that part.

### Why the output is committed

`deploy.sh` rsyncs `public/` straight from the working tree. Nothing builds on the server, so a
forgotten rebuild would ship stale JS or a stale stylesheet and nothing else would notice. The
verify script therefore rebuilds both and diffs — a drifted output is a failing test.

The CSS check needs only `node`, so it runs on a bare clone. The TypeScript checks need
`node_modules` and are **skipped with a printed NOTE** when `npm install` has never run, so
`composer test` still works without the npm tooling.

### Why the sources sit outside `public/`

They are neither web-served nor deployed. Source maps still work: `inlineSources` embeds the
TypeScript in the map itself, so DevTools shows `Navigation.ts` without `assets/ts/` being served.
That is why `public/.htaccess` lists `map` — Strato 500s any static file it has no `SetHandler` for.

### The compiler settings that are load-bearing

[`tsconfig.json`](../tsconfig.json) runs `strict` plus:

| Setting | Catches |
|---|---|
| `module: nodenext` | an extensionless relative import — a specifier the browser would 404 on cannot ship |
| `noUncheckedIndexedAccess` | an array index assumed to be present |
| `exactOptionalPropertyTypes` | `undefined` smuggled into an optional property |
| `noEmitOnError` | a type error leaving stale or half-written JS in `public/` |
| `removeComments: false` | — the shipped file still reads like the hand-written one it replaced |

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
    ├── embed/              ConsentGatedEmbed → SoundCloudWidget → the two players
    ├── terminal/           TerminalWindow + its five content tags
    ├── download/           DownloadList, DownloadCard, DownloadLabel, DownloadMeta
    └── release/            ReleaseList, ReleaseCard, ReleaseTitle, ReleaseMeta
```

**One class per file, named for the class**, the way `src/NeuroSYS/` is. The directory is the
component, not the file: `elements/terminal/` and `elements/embed/` sit opposite `View/Terminal/`
and `Model/Embed/` on the server.

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
| **Marker** | no, and never will | `<download-list>`, `<release-list>` |

### Self-building elements

A view emits the tag and its attributes; the element builds everything below it. That is why
`ReleaseView::heroSection()` is one tag where it used to be a subtree.

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

`<terminal-key>` loose in a page is the same mistake as a misspelled tag, and fails the same silent
way: an inert inline box, styled by a selector that no longer matches, nothing in the console.

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

### Markers — and why two elements build nothing on purpose

`<download-list>` and `<release-list>` are plain `HTMLElement` subclasses with no body, and that is
the finished implementation rather than a stub. What they wrap is a real server-rendered
`<a data-no-spa>`: downloads have to work without JS, and `data-no-spa` has to land on a real anchor
or the SPA router fetches the 303 and swallows it.

The card tags **wrap** their anchors rather than replacing them — links keep working without JS,
keyboard access is unchanged — and the wrappers are `display: contents`, so the anchor is still the
card to layout.

### What stays native

`<a>`, `<button>`, `<h1>`/`<h2>`, `<img>`, `<p>`, `<section>` — anything that carries meaning or
behaviour the browser already provides.

---

## The embed hierarchy

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
account. That is why the gate is shared client-side but `Embed` is not shared server-side —
`SoundCloudProfileEmbed` deliberately does not implement it, because a profile player assignable to
a release would be nonsense.

### The gate is the point

`buildEmbed()` is called from the click handler and nowhere else, so the iframe does not exist
before the visitor consents. Nothing is requested from SoundCloud until then — and the served page
names no SoundCloud address at all, so there is nothing for a browser to preconnect or prefetch
either.

The consent notice is **written by the element**, not the server. That is sound for the same reason:
the transfer it warns about needs a click, the click needs the script, and the script writes the
notice. The wording is asserted in `test/js/soundcloud-player.test.mjs`, where it is written.

### Two details that look like mistakes

- **The height is an `EmbedAttribute`, not a `SoundCloudPlayerAttribute`.** It is what the gate
  reserves, and the gate is every provider's. It used to live in SoundCloud's enum, which meant the
  provider-agnostic base class imported one provider's enum to size a box.
- **The attribution styling goes through the CSSOM**, not a `style` attribute. Same rendering, but
  `element.style` is not something CSP governs — which is what lets `style-src` stay strict.

---

## SPA navigation

[`Navigation`](../assets/ts/Navigation.ts) intercepts internal link clicks, fetches the page as a
content fragment, and swaps `#content`.

```
click on a[href^="/"]
  → not modified/middle-click, no data-no-spa, resolved origin === location.origin
  → preventDefault, pushState
  → fetch with X-Requested-With: XMLHttpRequest
  → ViewResponse sends <title> + the fragment
  → read and decode the title, strip it, assign the rest to #content.innerHTML
  → dispatch neurosys:navigate, scroll to top
```

### The three things to understand before touching it

**1. The selector matches the href *attribute*; the code uses the resolved `link.href`.**
`//evil.example/x` starts with a slash exactly as `/releases` does — a protocol-relative URL is a
different origin wearing a path's clothes. So `onClick` reconciles the two readings and hands
anything cross-origin back to the browser. This is the client's half of the same rule `Element`
enforces on the server.

**2. `go()` ends in an `innerHTML` assignment.** That is safe only because the fragment is
same-origin and was built by the server's markup tree, where every value is escaped by `Text` and
every URL attribute is scheme-checked. The guarantee is *inherited*, not enforced here — anything
that ever puts markup into `#content` from another source reopens DOM XSS, and nothing in that file
would notice.

**3. Nothing re-runs after a swap.** The browser upgrades any custom element it parses, including
markup assigned through `innerHTML`, so the gate and the cover wire themselves on arrival. The
`neurosys:navigate` event stays for anything that is *not* an element — subscribe with
`Navigation.onNavigate()` rather than the string.

### Failure is always "hand it back to the browser"

A non-`ok` response or a thrown fetch calls `location.assign(url)`. `pushState` has already run by
then, so leaving the visitor there would strand them on a page they never got. Likewise
`forDocument()` returns `null` when there is no `#content`, which switches the whole router off with
every link still working.

---

## The stylesheet

A component's CSS lives with the component.

```
assets/css/
├── main.css          the @import list; the order IS the cascade
├── base/             tokens.css (:root), elements.css (* html body a)
├── layout/           shell.css (what Layout.php emits), utilities.css
├── views/            home.css, release.css, stats.css      (cf. src/NeuroSYS/View/)
└── elements/         card.css, terminal.css, CoverArt.css,
                      embed.css, download.css               (cf. assets/ts/elements/)
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

### The invariants

- **Every `Tag` case is styled by exactly one part**, asserted in both directions by `HtmlTest`.
- **Every `CssClass` case has a rule, and every rule has a case** — also `HtmlTest`, which parses
  the generated stylesheet.
- `card.css` is the one part named for a concept rather than a component, because the catalogue
  entry and the download entry genuinely share a look. It is meant to be conspicuous the way
  `RawHtml` is: the list is pinned to that one file, so a second has to be argued for.

### Two things that deliberately did not move to a runtime

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
| Home | hero reads in full; `latest tracks` is a reserved empty box |
| Release | no cover image, empty player frame, **no terminal** — so no bpm, key or genre |
| 404 | no error line |
| Everything | links, navigation, downloads, titles, taglines, imprint and privacy are unaffected |

The CSS reserves every box, so nothing reflows when the script lands. `PageTest` pins both halves of
the home-page case. A `<noscript>` inside `<terminal-window>` and `<cover-art>` carrying the same
content would buy the rest back, for the price of rendering it twice.

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

Three checks cover this from three directions: `ViewTest` pins the set a view may emit, the verify
script checks every custom tag in a real response is a `Tag` case, and `vocabulary.test.mjs` checks
every `Tag` case is registered once `main.js` has run — the direction that catches a forgotten
import.

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
reported at all. That is affordable here and nowhere else — these are a few dozen small files with one job
each.

See [testing.md](testing.md) for the full picture, including the PHP suites.

---

## Further reading

- [architecture.md](architecture.md) — the PHP side
- [contracts.md](contracts.md) — the PHP↔TypeScript seam and what guards it
- [testing.md](testing.md) — both suites and the invariants
- [branding.md](branding.md) — why brand assets are vendored, and the consent reasoning behind the gate
- `CLAUDE.md` — the long-form rationale
