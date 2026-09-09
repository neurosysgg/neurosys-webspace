# neuro.SYS

The music release site at **[neurosys.gg](https://neurosys.gg)**. Plain PHP 8.5, HTML and CSS, no
framework and no runtime dependencies. The front end is TypeScript compiled to browser-native ES
modules; the output is committed, so what the server runs is still plain files.

**[docs/README.md](docs/README.md) is the real documentation** — structure, URL map, and a page per
subject. [CLAUDE.md](CLAUDE.md) is the long-form argument behind each decision.

## What you need

| | Why |
|---|---|
| **PHP ≥ 8.5** with `ext-uri`, `ext-dom`, `ext-openssl`, `ext-zlib` | The pipe operator in `autoload.php`, `#[\NoDiscard]`, the URL parsers `Element` and `Request` use, the HTML parser `MarkupParser` reads the privacy policy with, and the signature and gzip that the signed `/api` push is made of. All five are declared in `composer.json` and asked for by name in the verify script. |
| **Node ≥ 26.7** | The front-end build and its tests. The floor is a flag rather than a feature: `--test-coverage-include-all` arrived in 26.7.0, and without it a module nothing imports is not reported as uncovered — it is not reported at all. `.npmrc` makes `engines` a refusal rather than a warning. |
| **Composer** | Dev tooling only — PHPUnit, phpcs, php-cs-fixer. `vendor/` is never deployed. |
| **ffmpeg / ffprobe** | `tools/stage-demo.php` only: transcoding and durations. |
| **metaflac** (`flac`) | `tools/stage-release.php` and `tools/release-track.php` only: reading a prepared release folder. |
| **openssl** (CLI) | Once, to generate the `/update` keypair. See [docs/deployment.md](docs/deployment.md). |

Nothing but PHP is needed to *serve* the site. `composer install` is for the tests and linters,
`npm install` for touching the TypeScript; the stylesheet rebuilds with `node` alone, so a bare
clone can still run that half of the verify script.

## Running it

```bash
npm run dev
```

`php -S` with `tools/dev-router.php`, which is not optional — built assets are served under a
build-stamp path segment that `public/.htaccess` strips in production, and the built-in server
reads no `.htaccess`.

```bash
composer test      # PHPUnit, then the end-to-end verify script
composer lint      # phpcs + php-cs-fixer, read-only
npm run check      # tsc over assets/ts/, tools/*.mjs and test/js/*.mjs
npm test           # the elements and the enum mirrors
```

## Licence

The code is MIT — see [LICENSE](LICENSE). Two things under this tree are **not** covered by it and
are not the author's to license:

- **`public/assets/img/brand/`** — vendored platform marks (Spotify, Apple Music, SoundCloud,
  YouTube, X; the GitHub octicon is itself MIT). Each is used under its owner's brand guidelines,
  and [docs/branding.md](docs/branding.md) records the source and terms for every file. They are
  vendored rather than hot-linked deliberately; that document says why.
- **`data/privacy.*.html`** and the imprint text — legal documents generated for this specific
  site and operator, not boilerplate to reuse.

The music is not in this repository at all.
