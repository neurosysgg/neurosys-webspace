# Testing

Two suites that deliberately don't overlap. Neither catches everything; together they cover
the parts the other structurally can't.

```bash
composer test
```

runs both — unit tests first, then the verify script. Or separately:

```bash
composer unit      # vendor/bin/phpunit
composer verify    # bash test/basic_test.sh
composer lint      # phpcs + php-cs-fixer, read-only
```

## The split

| | `test/unit/` (PHPUnit) | `test/basic_test.sh` (verify) |
|---|---|---|
| **Runs against** | Composer's autoloader | the real `autoload.php`, over real HTTP |
| **Good at** | branches, edge cases, escaping, error paths | integration, `exit`-ing code, the deployed shape |
| **Blind to** | anything that calls `exit`, `header()`, or needs a server | anything with no observable output |

The division matters in a few concrete places:

- **`Auth::requireSiteAuth()` / `requireAdminAuth()` call `exit`.** A unit test can't observe that
  without process isolation, so the verify script asserts `/admin/stats` really returns 401 over HTTP.
- **`autoload.php` uses the `|>` pipe operator**, which is a parse error below PHP 8.5. PHPUnit never
  touches that file (it boots from Composer), so the verify script exercises it directly and checks
  every class under `src/` actually resolves through it.
- **The 503 "not uploaded yet" branch** needs a release with a link-less format, which the live
  catalogue doesn't have. The unit test builds a synthetic one and hands it to `DownloadController`
  through its optional `ReleaseRepository` parameter — that argument exists purely as this seam.
- **Escaping** is unit-tested because it needs hostile inputs (`<script>`, `&`, quotes, multibyte)
  that the real catalogue will never contain.

## Adding a unit test

Drop a `*Test.php` into `test/unit/`, namespace `NeuroSYS\Test\Unit`. `NEUROSYS_ROOT` is defined by
`test/bootstrap.php` if you need the real data files.

Files are grouped by layer, not one-per-class: `ModelTest`, `EmbedTest`, `ViewTest`, `ServiceTest`,
`SupportTest`, `ResponseTest`, `RoutingTest`, `RequestTest`.

## Invariants worth keeping green

A few tests exist to stop a specific mistake coming back, not to cover a line:

- **Nothing loads from a third-party host on page load.** `ViewTest` asserts every `src` and every
  stylesheet `href` in the layout is same-origin, and that no `<iframe>` reaches a release page before
  the consent gate is clicked. Breaking either makes us a joint controller for the transfer
  (CJEU C-40/17) — see [branding.md](branding.md).
- **Download logging stays off.** `ServiceTest` asserts `DownloadLogger::ENABLED === false` and that
  the referrer is never read. It's a privacy-policy decision before a code one — see `CLAUDE.md`.
- **Download cards carry `data-no-spa`.** Without it `nav.js` fetches the 303 and swallows it, and
  downloads silently stop working while every page still looks fine.
- **The consent gate reserves the player's height.** `Embed::height()` feeds `--player-height`, so the
  placeholder and the real iframe are the same size and the page doesn't jump.
- **Every route pattern is metacharacter-free.** `Route::matches()` interpolates the pattern straight
  into a regex without `preg_quote()`, so a `.` in a future pattern would silently become a wildcard.

## Linting

`phpcs` (PSR-12) and `php-cs-fixer` both run clean. File headers, control structures and everything else
follow PSR-12 exactly. The only exemptions, documented in both configs, are the two sniffs that fight the
house style:

- **column-aligned parameters and call arguments** — `public string                $permalink,` in
  `SoundCloudEmbed`, `new Format(ReleaseFormat::FLAC,  new HiDriveLink(…))` in `data/releases.php`
- **one-line accessors** — `public function all(): array { return $this->items; }`

Two long-line warnings remain in `Layout.php` and `ReleaseView.php`; both are HTML inside a heredoc that
can't wrap without changing the output, and warnings don't fail the build.

Editor note: nvim's stock `nvim-lint` phpcs resolves `vendor/bin/phpcs` and its ruleset against *Neovim's*
cwd, so opening a file from outside the project silently lints it as bare PSR-12 and flags both exemptions
above. `~/.config/nvim/lua/plugins/php.lua` overrides that to resolve from the buffer's own project root.

`vendor/` is gitignored and never deployed — `deploy.sh` only ships `public/`, `src/`, `autoload.php`
and `data/`. The "no package manager" rule in `CLAUDE.md` is about what runs on the server, and that
is still true.
