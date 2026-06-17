# neuro.SYS — site

Music release site for neuro.SYS at `neurosys.gg`. Plain PHP/HTML/CSS — no framework, no build step.

## Structure

```
neurosys/
├── public/              ← webroot (maps to htdocs/ on Strato)
│   ├── .htaccess        ← rewrites all requests to index.php
│   ├── index.php        ← front controller (9 lines)
│   └── assets/
│       ├── css/style.css
│       ├── js/nav.js    ← SPA navigation (~20 lines, no framework)
│       └── img/         ← static images (logo, placeholder SVG, etc.)
│
├── src/NeuroSYS/        ← application classes (PSR-4, custom autoloader)
│   ├── Controller/      ← one class per route group
│   ├── Http/            ← Request, Response types, HttpStatusCode
│   ├── Model/           ← Release, Format, MusicalKey, ReleaseFormat
│   ├── Service/         ← Auth, ReleaseRepository, DownloadLogger, DownloadLogEntry
│   ├── Support/         ← Collection<T>, SearchableCollection<T>, JsonDeserializable
│   ├── View/            ← one View class per page; HTML lives here as heredoc strings
│   ├── Layout.php       ← full HTML shell (nav, footer, scripts)
│   └── Router.php       ← URL → Controller mapper
│
├── data/                ← above webroot, never web-accessible
│   ├── releases.php     ← release catalogue (typed Release objects)
│   ├── admin.php        ← stats page credentials (bcrypt hash)
│   └── logs/            ← downloads.log (auto-created on first download hit)
│
├── test/
│   └── basic_test.sh    ← smoke tests (PHP CLI + curl)
│
└── docs/                ← you are here
```

## URL structure

| URL | What happens |
|-----|-------------|
| `/` | home page |
| `/releases` | release catalogue |
| `/releases/{slug}` | release landing page |
| `/releases/{slug}/flac` | HTTP 303 → HiDrive FLAC link |
| `/releases/{slug}/mp3` | HTTP 303 → HiDrive MP3 link |
| `/releases/{slug}/stems` | HTTP 303 → HiDrive stems link |
| `/admin/stats` | download stats (HTTP basic auth) |

## How it works

- All non-asset requests hit `index.php` via `.htaccess` rewrite.
- `Router` maps URL segments to a `Controller`; the controller fetches its own data, builds a `View`, and returns a `Response`.
- Download routes log the hit then issue a 303 to the HiDrive direct-download link — no file passes through PHP.
- Navigation is SPA-style: `nav.js` intercepts link clicks, fetches a content fragment (`X-Requested-With: XMLHttpRequest`), and swaps `#content`. Direct loads and no-JS work identically — all links are real hrefs.
- Release metadata lives in `data/releases.php` as typed `Release` objects. That's the only file you edit to add a release.

## Further reading

- [deployment.md](deployment.md) — Strato FTP setup, PHPStorm workflow
- [releases.md](releases.md) — adding and updating releases
