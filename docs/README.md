# neuro.SYS — site

Music release site for neuro.SYS at `neurosys.gg`. Plain PHP/HTML/CSS — no framework, no build step.

## Structure

```
neurosys/
├── public/              ← webroot (maps to htdocs/ on Strato)
│   ├── .htaccess        ← rewrites all requests to index.php
│   ├── index.php        ← front controller (4 statements)
│   └── assets/
│       ├── css/style.css
│       ├── js/nav.js    ← SPA navigation (~45 lines, no framework)
│       ├── js/player.js ← click-to-load consent gate for the embed
│       └── img/         ← static images + brand/ (vendored platform icons)
│
├── src/NeuroSYS/        ← application classes (PSR-4, custom autoloader)
│   ├── Controller/      ← one class per route group
│   ├── Exception/       ← ReleaseVerificationException
│   ├── Http/            ← Request, Response types, HttpStatusCode
│   ├── Model/           ← Release, Format, MusicalKey, Genre, ReleaseFormat, Platform
│   │   ├── Embed/       ← Embed interface + SoundCloudEmbed (+ style/option enums)
│   │   └── Link/        ← FileLink interface + HiDriveLink
│   ├── Service/         ← Auth, ReleaseRepository, ProfileRepository, DownloadLogger…
│   ├── Support/         ← Collection<T>, SearchableCollection<T>, Route, JsonDeserializable
│   ├── View/            ← one View class per page; HTML lives here as heredoc strings
│   ├── Layout.php       ← full HTML shell (nav, footer, scripts)
│   └── Router.php       ← URL → Controller mapper
│
├── data/                ← above webroot, never web-accessible
│   ├── releases.php     ← release catalogue (typed Release objects)
│   ├── profiles.php     ← footer profile links
│   ├── privacy.html     ← Datenschutzerklärung, served by PrivacyController
│   ├── admin.php        ← stats page credentials (bcrypt hash)
│   └── logs/            ← downloads.log — see "Download logging" below
│
├── test/
│   ├── basic_test.sh    ← end-to-end verify script (PHP CLI + curl)
│   └── unit/            ← PHPUnit unit tests
│
└── docs/                ← you are here
```

## URL structure

| URL | What happens |
|-----|-------------|
| `/` | home page |
| `/releases` | release catalogue |
| `/releases/{slug}` | release landing page |
| `/releases/{slug}/{format}` | HTTP 303 → HiDrive link (`flac`, `wav`, `mp3`, `aiff`, `stems`, `ogg`) |
| `/imprint` | Impressum (DE + EN) |
| `/privacy` | Datenschutzerklärung, rendered from `data/privacy.html` |
| `/admin/stats` | download stats (HTTP basic auth) |

Any format declared on a release without a `HiDriveLink` returns a plain-text 503 instead of redirecting.

## How it works

- All non-asset requests hit `index.php` via `.htaccess` rewrite.
- `Router` maps URL segments to a `Controller`; the controller fetches its own data, builds a `View`, and returns a `Response`.
- Download routes issue a 303 to the HiDrive direct-download link — no file passes through PHP.
- Navigation is SPA-style: `nav.js` intercepts link clicks, fetches a content fragment (`X-Requested-With: XMLHttpRequest`), and swaps `#content`. Direct loads and no-JS work identically — all links are real hrefs.
- Release metadata lives in `data/releases.php` as typed `Release` objects. That's the only file you edit to add a release.

## Download logging

**Off, deliberately, for legal reasons.** `DownloadLogger::ENABLED` is `false` and `log()` returns on it before the
entry is built, so the referrer is never read and nothing is written. `/admin/stats` says so rather than showing an
empty table, and the verify script asserts the switch stays off.

Turning it on is a privacy-policy decision before a code one — `data/privacy.html` makes no download-tracking claim.
Note also that `data/logs/` is **not** auto-created: `fopen(…, 'ab')` creates the file but not its directory, and
`deploy.sh` excludes `logs/`, so the directory has to exist on the server first. See `CLAUDE.md`.

## Further reading

- [deployment.md](deployment.md) — Strato setup and the deploy workflow
- [releases.md](releases.md) — adding and updating releases
- [branding.md](branding.md) — vendored brand assets and profile links
- [testing.md](testing.md) — unit tests and the verify script
