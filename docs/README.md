# neuro.SYS — site

Music release site for neuro.SYS at `neurosys.gg`. Plain PHP/HTML/CSS — no framework, no build step.

## Structure

```
neurosys/
├── public/              ← webroot (maps to htdocs/ on Strato)
│   ├── .htaccess        ← rewrites all requests to index.php
│   ├── index.php        ← router
│   └── assets/
│       ├── css/style.css
│       ├── js/nav.js    ← SPA navigation (~20 lines, no framework)
│       └── img/         ← cover art goes here ({slug}-cover.jpg)
│
├── templates/           ← HTML fragments, above webroot
│   ├── shell.php        ← persistent nav + logo
│   ├── home.php         ← /
│   ├── releases.php     ← /releases
│   ├── release.php      ← /releases/{slug}(/index)
│   ├── stats.php        ← /admin/stats
│   └── 404.php
│
├── data/                ← above webroot, never web-accessible
│   ├── releases.php     ← release catalogue + HiDrive download URLs
│   ├── admin.php        ← stats page credentials
│   └── logs/            ← downloads.log (auto-created on first hit)
│
└── docs/                ← you are here
```

## URL structure

| URL | What happens |
|-----|-------------|
| `/` | home page |
| `/releases` | release catalogue |
| `/releases/{slug}(/index)` | release landing page |
| `/releases/{slug}/flac` | HTTP 303 → HiDrive FLAC link |
| `/releases/{slug}/mp3` | HTTP 303 → HiDrive MP3 link |
| `/releases/{slug}/stems` | HTTP 303 → HiDrive stems link |
| `/admin/stats` | download stats (HTTP basic auth) |

## How it works

- All non-asset requests hit `index.php` via `.htaccess` rewrite.
- The router parses the URL, dispatches to the right template, and on download routes logs the hit then issues a 303 to the HiDrive direct-download link.
- Navigation is SPA-style: `nav.js` intercepts link clicks, fetches a content fragment (`X-Requested-With: XMLHttpRequest`), and swaps `#content`. Direct loads and no-JS work identically — all links are real hrefs.
- Download URLs and release metadata live in `data/releases.php`. That's the only file you edit to add a release.

## Further reading

- [deployment.md](deployment.md) — Strato FTP setup, PHPStorm workflow
- [releases.md](releases.md) — adding and updating releases
