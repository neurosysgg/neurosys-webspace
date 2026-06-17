# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Stack

Plain PHP 8.5 / HTML / CSS — no framework, no build step, no package manager. The pipe operator (`|>`) is used in `autoload.php` and requires PHP ≥ 8.5.

## Local dev

```bash
php -S localhost:8080 -t public
```

No build, no transpile, no dependencies to install.

## Architecture

MVC, all plain PHP classes — no template files, no `extract()`, no inline echo syntax.

```
src/NeuroSYS/
├── Controller/     ← one class per route group; fetches its own data, returns a Response
├── Http/           ← Request, Response interface, ViewResponse, RedirectResponse, PlainTextResponse, HttpStatusCode
├── Model/          ← Release, Format, MusicalKey, ReleaseFormat (typed value objects + enums)
├── Service/        ← Auth, DownloadLogger, DownloadLogEntry, ReleaseRepository
├── Support/        ← Collection<T>, SearchableCollection<T>, JsonDeserializable interface
├── View/           ← View abstract base + one concrete per page; HTML via heredoc, no template files
├── Layout.php      ← static wrap(View): string — the full HTML shell
└── Router.php      ← pure URL→Controller mapper; zero data dependencies
```

`public/index.php` is nine lines: parse request → site auth check → `Router::dispatch()`.

## How the router works

All requests hit `public/index.php` via `.htaccess` rewrite. It:

1. Builds a `Request` from `$_SERVER`
2. `Router::dispatch()` maps URL segments to a `Controller`
3. The controller fetches its own data (via `ReleaseRepository` or log file), builds a `View`, returns a `Response`
4. `Response::send()` handles headers/output — `ViewResponse` wraps in `Layout::wrap()` on full-page loads, emits a fragment on AJAX

Download routes (`/releases/{slug}/{format}`) log the hit via `DownloadLogger` and issue a 303 redirect to the HiDrive direct-download link.

## SPA navigation

`public/assets/js/nav.js` intercepts internal link clicks, fetches the content fragment via XHR (`X-Requested-With: XMLHttpRequest`), and swaps `#content`. Download links carry `data-no-spa` to bypass this and trigger real navigation (otherwise the 303 is consumed silently by fetch).

## Adding a release

Edit `data/releases.php` — that's the only file. Each entry is a typed `Release` object:

```php
'your-slug' => new Release(
    title:               'track title',
    bpm:                 140,
    key:                 MusicalKey::FSharpMajor,   // see MusicalKey enum for all 24 keys
    description:         'debut single',
    soundcloudEmbedHtml: '<iframe ...>...</iframe>', // full embed HTML from SoundCloud Share → Embed; '' to hide
    coverSrc:            'https://my.hidrive.com/api/sharelink/download?id=...', // HiDrive direct-download link
    formats: new Collection(Format::class)->add(
        new Format(ReleaseFormat::FLAC,  'https://my.hidrive.com/...'),
        new Format(ReleaseFormat::MP3,   'https://my.hidrive.com/...'),
        new Format(ReleaseFormat::STEMS, 'https://my.hidrive.com/...'),
    ),
),
```

Omit a format entry (or leave its URL as `''`) to hide that download card.

## Deployment

`public/` maps to Strato's `htdocs/` (web-exposed). `data/` lives **outside** the webroot — it's uploaded separately and never via the standard deployment mapping.

- Regular deploy: right-click `public/` → **Deployment → Upload to Strato** in PHPStorm
- After adding/changing a release: also upload `data/releases.php` manually via the Remote Host panel
- `data/admin.php` holds bcrypt credentials for `/admin/stats`; generate with `php -r "echo password_hash('pw', PASSWORD_BCRYPT);"`

See `docs/deployment.md` for first-time FTP setup and `docs/releases.md` for the full release checklist.
