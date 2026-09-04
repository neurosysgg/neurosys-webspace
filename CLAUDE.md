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

The verify script also type-checks `assets/ts/` and asserts the committed JS is current with it. Both
are skipped with a printed NOTE when `npm install` has never been run, so `composer test` still works
on a bare clone.

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
handler (a test enforces that). `style-src` keeps `'unsafe-inline'` solely because `SoundCloudEmbed`
reproduces SoundCloud's attribution markup verbatim.

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
├── main.ts                   ← entry point, the only <script> Layout.php loads
├── Navigation.ts             ← class Navigation — SPA navigation
└── elements/
    ├── PlayerConsent.ts      ← class PlayerConsent — <player-consent>
    └── CoverArt.ts           ← class CoverArt — <cover-art>
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

One class per file, named for the class, the way `src/NeuroSYS/` is. Nothing is a loose exported
function any more: it is `Navigation.onNavigate()` or a method on an element, so a call site says
where it came from.

`tsconfig.json` runs `strict` plus `noUncheckedIndexedAccess` and `exactOptionalPropertyTypes`, and
`module: nodenext` makes an extensionless relative import a compile error — a specifier the browser
would 404 on cannot ship. Same instinct as `CspHost` refusing anything but a bare origin.

### Custom elements

A view's output is its own vocabulary. The tags that carry behaviour are self-contained — they build
everything they show, so a view emits the tag and its attributes and nothing else:

| Tag | Registered | Does |
|---|---|---|
| `<player-consent provider height embed>` | yes | builds the gate, reserves the player's height, then hosts the embed in place on click |
| `<cover-art src fallback alt>` | yes | builds its `<img>`, falls back to the placeholder when the file host 404s |
| `<terminal-window label [narrow]>` | no | the terminal frame; the title bar and its three lights are one CSS pseudo-element |
| `<terminal-command>` `<terminal-field>` `<terminal-key [error]>` `<terminal-ok>` `<terminal-cursor>` | no | terminal content; the `$` sigil and the cursor glyph are drawn by CSS |
| `<download-list>` `<download-card format>` `<download-label>` `<download-meta>` | no | the download group and its entries |
| `<release-card slug>` `<release-title>` `<release-meta>` | no | a catalogue entry |

What stays native is what carries meaning or behaviour the browser provides: `<a>`, `<button>`,
`<h1>`/`<h2>`, `<img>`, `<p>`, `<section>`. The card tags wrap their anchors rather than replacing
them, so links keep working without JS, keyboard access is unchanged, and `data-no-spa` still lands on
a real `<a>`; both wrappers are `display: contents`, so the anchor is still the card to layout.

Two consequences of self-containment worth knowing:

- **With JS off, `<cover-art>` and `<player-consent>` are empty.** The CSS still reserves both boxes,
  so nothing reflows when the script lands, but a no-JS visitor gets no cover image and an empty
  player frame. Everything else — links, navigation, downloads, all text — works as before. Put a
  `<noscript><img …></noscript>` inside `<cover-art>` if that trade is not worth it.
- **The consent notice is written by the element**, not the server. That is still sound: the transfer
  it warns about can only be triggered by a click, a click needs the script, and the script writes the
  notice. `ReleaseView` supplies the `provider` name and a unit test pins it, because getting it wrong
  means a notice naming the wrong company.

Adding an element means adding it to `ViewTest::testTheViewsEmitOnlyKnownCustomElements`, which pins
the set: a misspelled tag renders as an inert inline box with no error otherwise. The verify script
checks the other direction, that everything `assets/ts/elements/` registers appears in the served
markup.

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
