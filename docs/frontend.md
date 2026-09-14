# Front end — TypeScript and CSS

How `assets/ts/` and `assets/css/` are arranged, what a custom element here is allowed to do, and
what to do when you need to add one. The server side has its own doc
([architecture.md](architecture.md)); the facts both sides state have a third
([contracts.md](contracts.md)). How any of this got the way it is lives in
[history/frontend.md](history/frontend.md).

No framework and no runtime dependency. TypeScript compiles to browser-native ES modules; the
stylesheet is concatenated from its parts. Both outputs are committed, and both are what a person
develops against — the tree in `public/` is 52 separate modules (the site's 41 and the framework's
11), plain `tsc` output that no bundler has touched. What deploys is a different tree, bundled by
esbuild; see *Debug and prod* below.

---

## The build

The tools and the reasoning behind them are the framework's; see
[phpanta/docs/frontend.md](../phpanta/docs/frontend.md#the-build). This section covers how they are
wired here and what they come to on this site.

| Command | Does |
|---|---|
| `npm run build` | `tsc`, then the stylesheet, then the asset manifest. The manifest comes last, because it hashes both outputs |
| `npm run build:css` | the stylesheet only |
| `npm run build:assets` | the asset manifest only |
| `npm run build:prod` | `npm run build`, then derives `build/dist/`. See *Debug and prod* |
| `npm run watch` | `tsc --watch`. **Does not build the CSS or the manifest.** |
| `npm run dev` | `php -S` with `phpanta/tools/dev-router.php`. The router is not optional. |
| `npm run check` | `tsc` over all three trees: `assets/ts/`, `tools/*.mjs`, `test/js/*.mjs` |
| `npm test` | `node --test` against the compiled output |
| `npm run coverage` | the same, with 100% thresholds |

**Why the output is committed.** `deploy.sh` and `push-update` both ship out of the working tree,
and nothing builds on Strato, so a forgotten rebuild would ship stale JS or a stale stylesheet. The
verify script therefore rebuilds all three outputs and diffs them. The CSS and manifest checks need
only `node`, so they run on a bare clone. The TypeScript checks need `node_modules`, and are
**skipped with a printed NOTE** when `npm install` has never run, so `composer test` still works
without the npm tooling.

### Debug and prod

The debug tree is 52 modules. The prod tree is one bundle of about 6.8 KB gzipped, served in one
response instead of 50 (the sizes are in
[performance.md](performance.md#the-front-end-payload)).

- **The maps would be public files on Strato.** Static assets are served straight by Apache and
  reach neither auth gate. The source is on GitHub, which is a reason not to worry about exposing
  it, not a reason to serve a second copy from Strato.
- **The prod manifest has to reach the server with the prod tree.** A push carries it. `deploy.sh`
  uploads `src/` from the working tree and then overlays the prod manifest; see
  [deployment.md](deployment.md#full-deploy).
- **The six prod checks are the verify script's**, listed in
  [testing.md](testing.md#invariants-worth-keeping-green). The re-run against the shipped bytes
  works because `test/js/dom.mjs` takes its tree from `PHPANTA_JS_DIR`. The nesting guards,
  `TerminalWindow`'s subtree, both embeds and `Navigation` all execute what the server will send.
  The three test files that import modules directly (`enum-parity`, `navigation`, `vocabulary`) are
  hardcoded to the debug tree, and are unaffected by what prod ships.

### The preload list

The graph is **five waves deep**. The browser learns it needs `model/CssClass.js` only after parsing
`ConsentGatedEmbed.js`. It learned about that file from `SoundCloudWidget.js`, which it learned
about from `SoundCloudPlayer.js`, which it learned about from `main.js`. `Layout::modulePreloads()`
renders the manifest's list after the stylesheet, and the five waves become one, for about 420
gzipped bytes per page.

**The counts:** the debug tree has 52 modules.

- `main.js` is the `<script src>` itself.
- 49 more are reachable from it and preloaded: `AssetManifest::MODULES`.
- Two, `model/SectionKind.js` and `model/ArrangementAttribute.js`, are imported by no module at all.
  They are mirrors that only `enum-parity.test.mjs` reads, since the arrangement is server-rendered
  and no element selects on its values.

The verify script rebuilds the manifest and diffs it, and asks the dev server for every hinted URL.
`ViewTest` asks the filesystem the same existence question.

### What this host needs of the build

- **`public/.htaccess` lists `map` in its `SetHandler` allow-list**, because Strato 500s any static
  file it has no handler for. The prod tree ships no maps. The handler stays for the debug tree,
  which is what `npm run dev` and a PHPStorm upload serve.
- **Images are not versioned.** They are reached through `Platform::icon()` and
  `Site::COVER_PLACEHOLDER` as plain constants, and `public/.htaccess` gives them thirty days.

## Layout

```
assets/ts/
├── main.ts                 entry point — the only <script> Layout.php loads
├── Config.ts               the three facts the client reads out of NeuroSYS\Site
├── phpanta/                symlink to phpanta/assets/ts — Navigation, Passkey, the guards, the framework's mirrors
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

The last lines start the SPA router and the admin's passkey forms:

```ts
Navigation.forDocument()?.start();
Passkey.start();
```

`Passkey.start()` runs on every page, unconditionally: a form a passkey answers can arrive with a
navigation after the entry script ran, since the admin's pages are swapped in like any other. See
[phpanta/docs/frontend.md](../phpanta/docs/frontend.md#passkey-forms).

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

The framework's — see [phpanta/docs/frontend.md](../phpanta/docs/frontend.md#guards--nestedelement).

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
the handle is `Site::HANDLE`, which the element already mirrors. Its output is strictly emptier
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

The framework's — see [phpanta/docs/frontend.md](../phpanta/docs/frontend.md#spa-navigation).

## The stylesheet

A component's CSS lives with the component, and `public/assets/css/style.css` is generated from it —
the same arrangement as `assets/ts/` → `public/assets/js/`, for the same reasons.

```
assets/css/
├── main.css          the @import list; the order IS the cascade
├── base/             tokens.css (:root), elements.css (* html body a, and the admin's forms and tables)
├── layout/           shell.css (what Layout.php emits), utilities.css
├── views/            home.css, release.css, demo.css                     (cf. src/NeuroSYS/View/)
└── elements/         card.css, terminal.css, CoverArt.css, embed.css,
                      download.css, arrangement.css, waveform.css           (cf. assets/ts/elements/)
```

[`main.css`](../assets/css/main.css) is the CSS half of what `main.ts` is for the elements: an
explicit list, in order, that nothing derives from a directory walk.

[`phpanta/tools/build-css.mjs`](../phpanta/tools/build-css.mjs) inlines each `@import`, because the source form is
never the served one — left in place, the browser would discover each part only after parsing the
one before it, and a typo'd href would 404 in silence with that component unstyled. Inlining makes
both a build error instead.

The build also refuses:

- a part imported twice
- an import that does not resolve
- **a rule in a manifest** — a file either orders parts or is one, so an ordering decision is never
  made twice

### The build tools' command line

The framework's — see [phpanta/docs/frontend.md](../phpanta/docs/frontend.md#the-build-tools-command-line).

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

## Words — the catalogs

The mechanism is the framework's; see [language.md](../phpanta/docs/language.md). What is this
site's is where its words live and what holds them. The legal pages are written twice rather than
translated; see [architecture.md](architecture.md#language).

- **`Texts` is the index.** It has one constant per section: `Layout`, `Home`, `Terminal`,
  `Releases`, `Demo`, `Errors`, `Profiles`, `Keys`, and `Framework` for the framework's own
  `FrameworkText`. Each constant names a catalog enum, so `Texts::Releases::Downloads` *is*
  `ReleaseText::Downloads`. The constants are not upper case, and `phpcs.xml.dist` exempts
  `Texts.php` and `ReleaseText.php` by name. They are steps of a path a reader skims, not values to
  notice.
- **A release's description** is a `ReleaseDescription` case, reached as
  `Texts::Releases::Descriptions::Ill`, with the slug as its backing value. `data/releases.php`
  names it: `description: Texts::Releases::Descriptions::Ill`. It lives in `src/` so both languages
  sit side by side and ship with a push.
  - German may fall back to English here, and only here. A description is written by whoever
    releases the track, possibly before the German exists.
  - A plain string still works and reads the same in both languages. That is what the staging tool
    writes (`description: ''`).
  - An entry naming a new case needs `./deploy.sh`; see
    [deployment.md](deployment.md#what-it-does-not-do).
- **A demo's description is never a catalog case.** `src/` is public, and a case would name an
  unreleased track. It is written inline in the gitignored `data/demos.php`:
  `description: new Translation(en: '…', de: '…')`.
- **A release's key** is translated on `MusicalKey` itself (`Fis-Dur`, `dis-Moll`, `H` for the
  English B), while its backing value stays the English name the tools match on. Genres and formats
  are proper names and stay as they are.
- **A terminal's rows** cross to the client as JSON, and their captions are translated. So
  `TerminalFields` implements `Translatable`, and is encoded at render rather than when the terminal
  is built.
- **The switch is in the footer.** It names every language in that language itself
  (`english · deutsch`): the page's own as text, the others as links to `/language/{language}`. The
  links carry `data-no-spa`, because the header and footer are outside the fragment Navigation swaps,
  and they have to come back in the new language too. The cookie is described in
  [security.md](security.md#the-language-cookie). The privacy policy names it in both languages, as
  storage strictly necessary for a service the visitor asked for (§ 25 Abs. 2 Nr. 2 TDDDG).

Three checks hold the words:

- **`TranslationTest`** reads `Texts`, and in turn every catalog a catalog names. It asserts that
  every case has a `#[Translation]`, and that every language `Site::languages()` offers parses as an
  ICU message, names the same arguments as the default, and is written — except a release
  description's, which needs only the default. It also walks `src/`
  for every enum that uses `Translated`, and fails on one the index cannot reach. And it reads every
  view's tokens for a word written as a literal: a string with a letter in it, passed straight to
  `containing()` or as an `alt`, `title` or `aria-label`.
- **`HtmlTest`** pins the scope rules:
  - inheritance;
  - a `lang` narrowing the scope, and a foreign `lang` keeping it;
  - a translated attribute;
  - a translated child keeping its element on one line;
  - the refusal.
- **The verify script** asks the running server:
  - German to a German browser and to a German cookie, on the home page, a release, the 404 and a
    fragment;
  - `Vary` and `Content-Language` on every page;
  - the switch's cookie, its way back, and its refusal of a path that is another host.

**The verify script's "no markup from a string" grep reads comments too.** It fails on an
apostrophe followed on the same line by a `<tag`, as in "the page's language off `<html lang>`".
Reword the line.

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
reported at all. That is affordable here and nowhere else — these are 59 small files with one job
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
