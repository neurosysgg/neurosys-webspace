# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Stack

Plain PHP 8.5 / HTML / CSS, no framework, **no runtime dependencies**. The pipe operator (`|>`) is
used in `autoload.php` and requires PHP ≥ 8.5.

Nothing on the PHP side is built or transpiled. The front end is TypeScript and does compile — see
[Front end](#front-end) — but the output is committed, so what lands on the server is still plain
files served statically.

Composer and npm are dev tooling only (PHPUnit, phpcs, php-cs-fixer; TypeScript). `vendor/` and
`node_modules/` are both gitignored and `deploy.sh` uploads neither — what runs on the server is
still plain PHP with a hand-rolled autoloader.

## Local dev

```bash
php -S localhost:8080 -t public
```

`composer install` if you want to run the tests or linters, `npm install` if you are going to touch
the TypeScript. Neither is needed just to serve the site.

## Tests

```bash
composer test      # unit tests, then the end-to-end verify script
composer unit      # PHPUnit only
composer verify    # bash test/basic_test.sh only
composer lint      # phpcs + php-cs-fixer, read-only
```

Two suites that cover different things — `test/unit/` for logic and edge cases, `test/basic_test.sh`
for the real autoloader, real HTTP, `exit`-ing auth code and repo hygiene. See `docs/testing.md` for
the split and for the invariants that exist to stop specific mistakes recurring.

```bash
npm test           # node --test — the elements and the enum mirrors
npm run check      # tsc --noEmit
```

The verify script runs the client-side tests too, type-checks `assets/ts/`, and asserts the committed
JS is current with it. All three are skipped with a printed NOTE when `npm install` has never been run,
so `composer test` still works on a bare clone.

The client-side tests run against the **compiled output** in `public/assets/js/`, the same files the
browser loads — so a build that never ran is a failing test rather than a passing one. They use
`node --test` (built in) with `jsdom` for a DOM; both are dev-only, like everything else here.

## Architecture

MVC, all plain PHP classes — no template files, no `extract()`, no inline echo syntax.

```
src/NeuroSYS/
├── Controller/     ← one class per route group; fetches its own data, returns a Response
├── Http/           ← Request, Response interface, ViewResponse, RedirectResponse, PlainTextResponse, HttpStatusCode
│   └── Security/   ← ContentSecurityPolicy, PermissionsPolicy + the enums they compose
├── Model/          ← Release, Format, MusicalKey, Genre, ReleaseFormat, Platform (typed value objects + enums)
│   ├── Embed/      ← Embed interface + SoundCloudEmbed; generates player markup from typed params
│   └── Link/       ← FileLink interface + HiDriveLink; generates share URLs from a share id
├── Service/        ← Auth, DownloadLogger, DownloadLogEntry, ReleaseRepository, ProfileRepository
├── Support/        ← Collection<T>, SearchableCollection<T>, Route, RouteInitialization, JsonDeserializable
├── View/           ← View abstract base + one concrete per page; HTML via heredoc, no template files
├── Layout.php      ← static wrap(View): string — the full HTML shell
└── Router.php      ← pure URL→Controller mapper; zero data dependencies
```

`public/index.php` is five statements: security headers → parse request → site auth check →
`Router::dispatch()` → send.

`SecurityHeaders::send()` runs before anything else, so the CSP and `Referrer-Policy` cover every response
including the 401 `Auth` exits with and the 303 a download redirects with. Every value is a typed object —
`CspDirective`, `CspKeyword`/`CspScheme`/`CspHost` behind a `CspSource` interface, `ReferrerPolicy`,
`PermissionsPolicyFeature` — so a misspelled directive or an unquoted `'self'` is a parse error, not a
header the browser silently drops. `CspHost` validates it got a bare origin, the way `HiDriveLink`
validates a share id. The CSP allows images only from
HiDrive and frames only from SoundCloud; `script-src` is strict, and no view emits an inline style or event
handler (a test enforces that). `style-src` is strict too: it carried `'unsafe-inline'` only for
SoundCloud's attribution markup, and `<soundcloud-player>` sets those properties through the CSSOM
instead — same styling, nothing for the allowance to cover.

The site is read-only: `Router::dispatch()` answers anything but GET/HEAD with a 405 and `Allow: GET, HEAD`.

## How the router works

All requests hit `public/index.php` via `.htaccess` rewrite. It:

1. Builds a `Request` from `$_SERVER`
2. `Router::dispatch()` maps URL segments to a `Controller`
3. The controller fetches its own data (via `ReleaseRepository` or log file), builds a `View`, returns a `Response`
4. `Response::send()` handles headers/output — `ViewResponse` wraps in `Layout::wrap()` on full-page loads, emits a fragment on AJAX

Download routes (`/releases/{slug}/{format}`) call `DownloadLogger` and issue a 303 redirect to the HiDrive direct-download link.

**Download logging is deliberately off, for legal reasons.** `DownloadLogger::ENABLED` is `false`, and `log()` returns on it
before the `DownloadLogEntry` is built — so the referrer is never read and nothing is written. `StatsController` skips reading the
log entirely and `/admin/stats` says logging is switched off rather than showing an empty table. Both suites assert the switch
stays off, and the unit test additionally asserts the referrer is never read.

To turn it on later: flip `ENABLED` to `true`. That is a privacy-policy decision before a code one — `data/privacy.html` currently
makes no download-tracking claim, so amend it first. Note the old failure mode is still latent underneath: `fopen(..., 'ab')`
creates the log file but not its directory, and `data/logs/` is excluded from `deploy.sh`, so a freshly enabled logger writes
nothing on the server until that directory exists.

## Front end

TypeScript, compiled to browser-native ES modules. No bundler, no framework.

```
assets/ts/                    ← sources; outside public/, neither web-served nor deployed
├── main.ts                   ← entry point, the only <script> Layout.php loads; imports every element
├── Navigation.ts             ← class Navigation — SPA navigation
├── model/                    ← the mirrored enums — Platform, SoundCloudOption, …
└── elements/                 ← one class per file, named for the class, grouped like src/NeuroSYS/
    ├── NestedElement.ts      ← abstract — the parent guard every content tag inherits
    ├── CoverArt.ts
    ├── embed/                ← ConsentGatedEmbed, SoundCloudPlayer      (cf. Model/Embed/)
    ├── terminal/             ← TerminalWindow + its five content tags   (cf. View/Terminal/)
    ├── download/             ← DownloadList, DownloadCard, …
    └── release/              ← ReleaseList, ReleaseCard, …
      ↓ npm run build
public/assets/js/             ← generated, committed, deployed
```

**Never hand-edit `public/assets/js/`** — it is build output and the next `npm run build` overwrites it.
`npm run watch` rebuilds on save; `npm run check` type-checks without emitting. The verify script fails
if the committed output has drifted from the sources: `deploy.sh` rsyncs `public/` straight from the
working tree, so a forgotten rebuild would ship stale JS and nothing else would notice.

Source maps sit next to the JS with the TypeScript embedded (`inlineSources`), so DevTools shows
`Navigation.ts` without `assets/ts/` having to be served. That is why `public/.htaccess` lists `map` — Strato
500s any static file it has no `SetHandler` for.

**One class per file, named for the class**, the way `src/NeuroSYS/` is — `<terminal-cursor>` is
`TerminalCursor` in `terminal/TerminalCursor.ts`, and the directory it sits in is the component, not
the file. That mirrors the PHP side twice over: the split is the same, and `elements/terminal/` and
`elements/embed/` sit opposite `View/Terminal/` and `Model/Embed/`. Nothing is a loose exported
function: it is `Navigation.onNavigate()` or a method on an element, so a call site says where it
came from.

Because a module registers its tag as a side effect of being imported, `main.ts` imports every one
of them, and that list is the whole vocabulary. `test/js/vocabulary.test.mjs` pins it — a tag the
sources register but `main.ts` never imports fails there, which matters most for the tags an element
builds itself, since those appear in no server response for the verify script to check.

`tsconfig.json` runs `strict` plus `noUncheckedIndexedAccess` and `exactOptionalPropertyTypes`, and
`module: nodenext` makes an extensionless relative import a compile error — a specifier the browser
would 404 on cannot ship. Same instinct as `CspHost` refusing anything but a bare origin.

### Custom elements

A view's output is its own vocabulary. The tags that carry behaviour are self-contained — they build
everything they show, so a view emits the tag and its attributes and nothing else:

Every tag a view may emit is registered, and so is every tag an element builds. The ones with no
behaviour of their own are `NestedElement` subclasses — CSS does their styling, the guard is what
they add — but they are declared all the same, so the vocabulary has one place to look rather than
existing only as a CSS selector.

| Module | Tag | Does |
|---|---|---|
| `NestedElement.ts` | — (abstract) | refuses to connect outside the element it belongs inside |
| `embed/ConsentGatedEmbed.ts` | — (abstract) | the gate: its wording, the reserved height, the click, the swap. Mirrors the `Embed` interface |
| `embed/SoundCloudPlayer.ts` | `<soundcloud-player track-id permalink secret-token player-style options track-title height>` | builds the widget URL and the attribution — SoundCloud's furniture, on the client |
| `CoverArt.ts` | `<cover-art src fallback alt>` | builds its `<img>`, falls back to the placeholder when the file host 404s |
| `terminal/TerminalWindow.ts` | `<terminal-window label command fields [narrow]>` | builds its whole subtree from a declared `Terminal` — the command, every row, the cursor |
| `terminal/TerminalCommand.ts` | `<terminal-command>` | guard; CSS draws the `$` |
| `terminal/TerminalField.ts` | `<terminal-field tone>` | guard; `tone` decides which half the stylesheet accents |
| `terminal/TerminalKey.ts` `TerminalValue.ts` | `<terminal-key>` `<terminal-value>` | guards, inside a row |
| `terminal/TerminalCursor.ts` | `<terminal-cursor>` | guard; CSS draws the `$` and the blink |
| `download/DownloadList.ts` | `<download-list>` | nothing, deliberately — see below |
| `download/DownloadCard.ts` … | `<download-card format>` `<download-label>` `<download-meta>` | guards only |
| `release/ReleaseList.ts` | `<release-list>` | nothing, deliberately — see below |
| `release/ReleaseCard.ts` … | `<release-card slug>` `<release-title>` `<release-meta>` | guards only |

What stays native is what carries meaning or behaviour the browser provides: `<a>`, `<button>`,
`<h1>`/`<h2>`, `<img>`, `<p>`, `<section>`. The card tags wrap their anchors rather than replacing
them, so links keep working without JS, keyboard access is unchanged, and `data-no-spa` still lands on
a real `<a>`; the wrappers are `display: contents`, so the anchor is still the card to layout. That is
also why `DownloadList` and `ReleaseList` build nothing and never will — what they wrap has to be
server-rendered, so they carry a name and a guard, and no more.

**Every tag that isn't a root extends `NestedElement`**, which walks up from itself looking for an
instance of the element it belongs inside and refuses to connect if it doesn't find one. That is the
implementation those classes have instead of being empty: `<terminal-key>` loose in a page is the same
silent failure as a misspelled tag, and now it says so. The check is "somewhere inside" rather than
"directly under", because a card's tags sit inside the anchor. Note that a throw in
`connectedCallback` does not reach whoever inserted the element — the browser reports it as an
uncaught error instead, which is loud enough to notice and is how the tests capture it.

Two consequences of self-containment worth knowing:

- **With JS off, the self-building elements are empty — and that now includes real content.** A no-JS
  visitor gets no cover image, an empty player frame, and no terminal: no bpm, key or genre on a
  release, and no error line on a 404. Links, navigation, downloads, titles, taglines and the privacy
  and imprint pages are all unaffected, and the CSS still reserves every box so nothing reflows when
  the script lands. This is the accumulated cost of building markup client-side, and it is worth
  re-reading whenever another fragment moves. A `<noscript>` inside `<terminal-window>` and
  `<cover-art>`, carrying the same content, buys it back for the price of rendering it twice.
- **The consent notice is written by the element**, not the server. That is still sound: the transfer
  it warns about can only be triggered by a click, a click needs the script, and the script writes the
  notice. The provider is the element — `<soundcloud-player>` knows it is SoundCloud — and the wording
  is asserted in `test/js/soundcloud-player.test.mjs`, where it is written.

### The terminal

`ReleaseView::heroSection()` declares a `Terminal` — a label, a command line and typed
`TerminalField` rows — and emits one tag. `<terminal-window>` builds the command, every row and the
cursor. The rows cross as JSON in an attribute, which is the only shape that stays generic across a
release's five metadata rows and a 404's single error line.

`TerminalTone` decides how a row reads, and the stylesheet decides which half of it that colours:
`ok` accents the value, `error` accents the key. The tone is on the row rather than on one half of
it, so that stays a styling decision.

### The embed, and the mirrored enums

`SoundCloudEmbed` no longer builds any markup. It renders `<soundcloud-player>` with the release's
facts as attributes, and the element builds the widget URL and the attribution from them. The split is
that the **server sends the release's facts** and the **element owns the provider's furniture** — the
accent colour, the artist handle, the attribution styling and the iframe attributes all live in
`SoundCloudPlayer.ts` now. Adding a provider is an `Embed` implementation and a `ConsentGatedEmbed`
subclass, and nothing else.

That means the server's output carries no SoundCloud address at all, which is a stronger version of
the old guarantee: there is nothing for a browser to preconnect or prefetch before the visitor agrees.

Building the URL client-side needs the query keys client-side, so `assets/ts/model/` mirrors four
enums — `Platform` (with `displayName()`), `SoundCloudOption`, `SoundCloudPlayerStyle` (with
`isVisual()`) and `TerminalTone`. Only what is read here: nothing client-side touches `Genre`,
`MusicalKey` or `ReleaseFormat`, and a mirror with no reader is just something to keep in sync.

**A mirror is a second copy of a fact, so it is tested.** `test/js/enum-parity.test.mjs` compares each
one against its PHP original — name, backing value, and the accessors the client mirrors — in
declaration order, because `SoundCloudEmbed` and `SoundCloudPlayer` both build the query string by
iterating the cases. Add a case on one side only, rename one, retype a backing value or reorder two,
and it fails.

Adding an element means adding it to `ViewTest::testTheViewsEmitOnlyKnownCustomElements`, which pins
the set a view may emit: a misspelled tag renders as an inert inline box with no error otherwise. The
verify script checks the served direction, that every custom tag in a real response is one
`assets/ts/elements/` defines, and `vocabulary.test.mjs` checks the registration direction. Between
the three they carry the whole vocabulary.

### SPA navigation

`Navigation` intercepts internal link clicks, fetches the content fragment via XHR (`X-Requested-With:
XMLHttpRequest`), and swaps `#content`. Download links carry `data-no-spa` to bypass this and trigger
real navigation (otherwise the 303 is consumed silently by fetch).

Nothing re-runs after a swap. The browser upgrades any custom element it parses, including markup
assigned through `innerHTML`, so the gate and the cover wire themselves on arrival. `Navigation`
still fires a `neurosys:navigate` event on `document` — subscribe with `Navigation.onNavigate()`
rather than the string — for anything that is not an element and does need to know.

## Adding a release

Edit `data/releases.php` — that's the only file. Each entry is a typed `Release` object:

```php
'your-slug' => new Release(
    title:       'track title',
    bpm:         140,
    key:         MusicalKey::FSharpMajor,   // see MusicalKey enum for all 24 keys
    genre:       Genre::Dubstep,            // see Genre enum
    description: 'debut single',
    cover:       new HiDriveLink('J2FXbB70A'),   // id from Share → Direct download link
    formats: new Collection(Format::class)->add(
        new Format(ReleaseFormat::FLAC,  new HiDriveLink('BXRsy9S7d')),
        new Format(ReleaseFormat::MP3,   new HiDriveLink('CPJy7AVIu')),
        new Format(ReleaseFormat::STEMS, new HiDriveLink('D2PUDjoII')),
    ),
    embed: new SoundCloudEmbed(          // omit entirely to hide the player
        trackId:     2394077313,         // numeric id from the track's embed URL
        permalink:   'ill',              // the track's slug on SoundCloud
        secretToken: 's-dIMAqki109G',    // only for a private/scheduled track; omit when public
    ),
),
```

Omit a format entry to hide that download card; keep the entry but omit its `HiDriveLink` to render the card
in the "not uploaded yet" state, where clicking returns a 503 instead of redirecting.

**Never paste a full HiDrive URL.** `HiDriveLink` takes the 9-character share id and builds the direct-download
URL around it. It rejects anything that isn't 9 alphanumeric characters, so a truncated paste throws when the
data file loads rather than 404ing at HiDrive later. `cover` and every `Format` take the same `FileLink`
interface — another host means a new class implementing it, and no change to `Release`, `Format`,
`DownloadController` or `ReleaseView`.

**Never paste SoundCloud's embed HTML.** `SoundCloudEmbed` generates it — see `docs/releases.md` for where the
three ids come from. Player style and the six SoundCloud toggles are `SoundCloudPlayerStyle` /
`SoundCloudOption` enums with sensible defaults; a normal release never sets them. Adding another provider
means a new class implementing `Embed`, not a new field on `Release`.

## Deployment

`public/` maps to Strato's `htdocs/` (web-exposed). `data/` lives **outside** the webroot — it's uploaded separately and never via the standard deployment mapping.

- Regular deploy: `./deploy.sh` (rsync over the mounted SFTP), or right-click `public/` →
  **Deployment → Upload to Strato** in PHPStorm
- `deploy.sh` ships `public/`, `src/`, `autoload.php` and `data/` — but **excludes `data/admin.php` and
  `data/site_auth.php`**, because the repo copies are placeholders and syncing them would overwrite the
  live credentials. Upload those two by hand when they change.
- `data/admin.php` holds bcrypt credentials for `/admin/stats`; generate with `php -r "echo password_hash('pw', PASSWORD_BCRYPT);"`

Footer profile links come from `data/profiles.php` — an empty URL hides that link. Brand icons are **vendored** under
`public/assets/img/brand/`, never hot-linked from a platform CDN; see `docs/branding.md` for why and for each platform's
usage rules.

See `docs/deployment.md` for first-time FTP setup, `docs/releases.md` for the full release checklist,
`docs/branding.md` for brand assets and profile links, and `docs/testing.md` for the two test suites.
