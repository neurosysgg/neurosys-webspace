# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Stack

Plain PHP 8.5 / HTML / CSS — no framework, no build step, no package manager. The pipe operator (`|>`) is used in `index.php` and requires PHP ≥ 8.5.

## Local dev

```bash
php -S localhost:8080 -t public
```

No build, no transpile, no dependencies to install.

## How the router works

All requests hit `public/index.php` via `.htaccess` rewrite. It:

1. Parses the URL path into segments
2. Dispatches to a template in `templates/`
3. On AJAX requests (`X-Requested-With: XMLHttpRequest`) renders only the content fragment; on full page loads wraps it in `templates/shell.php`

Download routes (`/releases/{slug}/{format}`) log the hit and issue a 303 redirect to a HiDrive direct-download link — no file is served through PHP.

## SPA navigation

`public/assets/js/nav.js` intercepts internal link clicks, fetches the content fragment via XHR, and swaps `#content`. Download links must carry `data-no-spa` to bypass this and trigger a real navigation (otherwise the 303 is consumed silently by fetch).

## Adding a release

Edit `data/releases.php` — that's the only file. The key fields:

| Key | Notes |
|-----|-------|
| `title`, `bpm`, `key`, `description` | display metadata |
| `cover_url` | HiDrive direct-download link to the cover image; falls back to `/assets/img/cover-placeholder.svg` on error |
| `soundcloud_html` | full embed HTML copied from SoundCloud's Share → Embed panel (not just the `src`); omit or leave empty to hide the player |
| `formats.flac/mp3/stems` | HiDrive direct-download links; omit or set `''` to hide that download card |

## Deployment

`public/` maps to Strato's `htdocs/` (web-exposed). `data/` lives **outside** the webroot — it's uploaded separately and never via the standard deployment mapping.

- Regular deploy: right-click `public/` → **Deployment → Upload to Strato** in PHPStorm
- After adding/changing a release: also upload `data/releases.php` manually via the Remote Host panel
- `data/admin.php` holds bcrypt credentials for `/admin/stats`; generate with `php -r "echo password_hash('pw', PASSWORD_BCRYPT);"`

See `docs/deployment.md` for first-time FTP setup and `docs/releases.md` for the full release checklist.