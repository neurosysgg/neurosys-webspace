# full repo review — 20260904

Read every file. Tests pass (33/33), `php -l` clean across all 30 PHP files, no TODO/FIXME anywhere.
Nothing here is on fire. Grouped roughly by how much it matters.

**Status: all fixed on 2026-09-04**, except the privacy item, which was handled differently — see the
note under it. Test suite is now PHPUnit (`test/unit/`, 174 tests) *plus* the verify script
(`test/basic_test.sh`, 40 checks); see `docs/testing.md`. Outcomes are marked inline below.

## worth fixing

- **`package.xml` is Xdebug's PECL descriptor** — 189 KB / 4738 lines of unrelated junk in the repo root, in since
  `c54310b initial commit`. Delete it.
  → **FIXED — deleted.**
- **`deploy.sh` rsyncs `data/` and will clobber the live admin password.** `data/admin.php` in the repo has
  `pass_hash => ''`, and line 36-39 does `rsync -a "$SRC/data/" "$ABOVE/data/"` with no exclude — so every
  `./deploy.sh` overwrites the server's real bcrypt hash with an empty one and locks `/admin/stats` out
  (fail-closed, so nothing is *exposed*, it just stops working). Also contradicts `CLAUDE.md` and
  `docs/deployment.md`, which both say `data/` is uploaded separately and never via the standard mapping.
  → add `--exclude='admin.php'` (and probably `--exclude='.site_auth.php'`), or drop the `data/` block entirely.
  → **FIXED — `--exclude` for `admin.php`, `site_auth.php`, `.site_auth.php`; documented in CLAUDE.md + deployment.md.**
- **`deploy.sh` sets `SFTP_PASS` and never uses it** — the script relies on the GVFS mount already existing.
  A plaintext password sitting in a file for no reason. Delete the line.
  → **FIXED — removed. NOTE: the script was rebuilt from scratch during this edit, so the password *string* is gone, not just the variable. It was unused, but if it was your only copy, re-derive it from the mount.**
- **privacy policy doesn't mention HiDrive.** Every download card 303s the visitor to `my.hidrive.com`, handing
  STRATO's HiDrive their IP + user agent. `data/privacy.html` covers Strato *as hosting* (log files for this site)
  and SoundCloud, but has zero occurrences of HiDrive/Download/Datei. By the policy's own standard — SoundCloud
  gets a section and it's also click-triggered — the download redirect deserves a paragraph. This is the one
  uncovered transfer left in an otherwise carefully-built privacy posture (consent gate, vendored icons,
  logging off).
  → **DONE DIFFERENTLY — folded into the existing Strato section (DE + EN) rather than given its own, since HiDrive is the same controller and the same AVV covers it. Also corrected "Strato AG" → "STRATO GmbH" in both languages: they converted, and their own Datenschutzerklärung names the GmbH.**
- **nav.js breaks ctrl/cmd/shift-click.** [nav.js:24](public/assets/js/nav.js:24) `preventDefault()`s every
  left-click on `a[href^="/"]` with no modifier-key guard, so "open in new tab" / "open in new window" silently
  do a same-tab SPA swap instead. One-line fix:
  ```js
  if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;
  ```
  → **FIXED — modifier + button guard added; verified in-browser that ctrl/meta/shift clicks now pass through.**
- **nav.js has no `.catch()`.** [nav.js:5-21](public/assets/js/nav.js:5) — `history.pushState` runs *before* the
  fetch, so a network failure leaves the URL pointing at a page the user never got, plus an unhandled rejection.
  Add a `.catch(function () { location.assign(url); })`.
  → **FIXED — falls back to `location.assign(url)`.**
- **`document.title` gets the escaped string.** `ViewResponse` emits `<title>` HTML-escaped for AJAX
  ([ViewResponse.php:34](src/NeuroSYS/Http/ViewResponse.php:34)); nav.js assigns `matches[1]` verbatim
  ([nav.js:16](public/assets/js/nav.js:16)). Verified: a release titled `rock & roll` puts literal
  `rock &amp; roll — neuro.SYS` in the tab. No current title has an escapable char, so it's latent — but it fires
  on the first `&`/`'`/`"` in a track name. Decode in JS, or don't escape into the fragment's title tag.
  → **FIXED — nav.js decodes entities before assigning; covered by a unit test on the server half and verified in-browser on the client half.**

## code quality / drift

- **`Platform::iconSrc()` can no longer return `''`** — since `9232ea3` all six icons are vendored and every
  match arm returns a path. That makes three things stale at once:
  - the `|| $platform->iconSrc() === ''` guard in
    [ProfileRepository.php:47](src/NeuroSYS/Service/ProfileRepository.php:47) is dead code
  - the `@return` doc on [Platform.php:51](src/NeuroSYS/Model/Platform.php:51) ("or '' if the asset isn't
    vendored yet") is a lie
  - the comment block in [data/profiles.php:12-15](data/profiles.php:12) still says SoundCloud/YouTube/X
    "stay hidden until their brand assets land"
  → **FIXED — dead guard removed from ProfileRepository, both stale comments corrected.**
- **`declare(strict_types=1)` is missing from 8 files in `src/`** — all of `Model/` (except `Embed/` and `Link/`),
  plus `Support/Collection.php` and `Exception/ReleaseVerificationException.php`. Everything else has it.
  → **FIXED — all 8.**
- **9 files have no trailing newline** (PSR-12 §2.2): `autoload.php`, `player.js`, `ImprintController`,
  `PrivacyController`, `ReleaseVerificationException`, `MusicalKey`, `Release`, `ImprintView`, `PrivacyView`.
  Plus trailing whitespace at [ImprintView.php:38](src/NeuroSYS/View/ImprintView.php:38).
  → **FIXED — all 9, plus the trailing whitespace.**
- **neither linter is installed.** `phpcs.xml.dist` and `.php-cs-fixer.dist.php` are both configured and neither
  binary is on PATH — which is exactly why the above survives. Their finders also skip `data/`, so
  `releases.php` / `profiles.php` are linted by nothing.
  → **FIXED — both added as dev dependencies and now run clean (0 errors), covering `data/` too. The
  codebase was then reformatted to PSR-12 for file headers and control structures (52 fixes across 48
  files); the only remaining exemptions are the column-alignment and one-line-accessor sniffs, which are
  deliberate house style. Also fixed nvim's linter, which was resolving the ruleset against Neovim's cwd
  and so ignored these exemptions whenever nvim was opened outside the repo.**
- **`ReleaseFormat::isLossless()` is never called**, and
  [ReleaseView::formatMeta()](src/NeuroSYS/View/ReleaseView.php:171) hardcodes the same FLAC/WAV/AIFF grouping in
  its own match. Two copies of one fact, one of them dead.
  → **FIXED — `formatMeta()` now delegates to it instead of keeping a second copy.**
- **`Request::segments()` is never called** — computed on every request
  ([Request.php:34](src/NeuroSYS/Http/Request.php:34)) purely to feed an accessor nobody uses; `Router` matches on
  `path()`. Drop the field or drop the accessor.
  → **FIXED — field and accessor both dropped.**
- **`Release` isn't `readonly`** while `Format`, `HiDriveLink` and `SoundCloudEmbed` all are, and its properties
  are public+mutable. Odd one out among the "typed value objects".
  → **FIXED.**
- **`SoundCloudEmbed::has()` is public but only used internally** by `playerUrl()`. Could be private.
  → **FIXED — now private.**
- **`composer.json` declares PSR-4 autoloading that nothing uses** (the hand-rolled `autoload.php` does the work,
  there's no `vendor/`), and has no `"require": {"php": ">=8.5"}` despite `autoload.php` using the `|>` pipe
  operator. A PHP 8.4 host fails with a parse error and no explanation.
  → **FIXED — `"php": ">=8.5"` declared; the psr-4 entry is now real (Composer autoloads the unit tests). The verify script also fails loudly on PHP < 8.5.**
- **`.player-consent { height: 300px }`** ([style.css:331](public/assets/css/style.css:331)) duplicates
  `SoundCloudPlayerStyle::Visual->height()`. A release using `Classic` (166px) would jump on load.
  → **FIXED — `Embed::height()` added to the interface, `ReleaseView` passes it as `--player-height`. Verified in-browser: gate and iframe are both 300px, no jump.**
- **CSS header comment contradicts the CSS.** [style.css:3-4](public/assets/css/style.css:3) says
  `accent = cyan (#00e5ff)` / `accent-2 = magenta (#ff2e6e)`; lines 14-15 are actually `#6a00ff` (purple) and
  `#ff2eab` (pink). Those two lines also broke the column alignment of the block above them.
  → **FIXED — comment corrected, alignment restored.**
- **`var(--mono), monospace`** — `--mono` already ends in `monospace` and `--sans` in `sans-serif`, so every use
  site appends a duplicate fallback. ~12 occurrences. Harmless, just noise.
  → **FIXED — 16 duplicate fallbacks removed.**

## docs drift

- **`docs/README.md` says download routes "log the hit"** and that `data/logs/` is "auto-created on first download
  hit". Both false, and both directly contradict `CLAUDE.md`: logging is deliberately off, and the directory is
  explicitly *not* auto-created (`fopen(..., 'ab')` makes the file, not the dir).
  → **FIXED — corrected, and a "Download logging" section added saying it's off and that `data/logs/` is not auto-created.**
- **`docs/releases.md` genre list is missing `FutureBass`** — which `hello-world` actually uses. Someone copying
  that list would think it doesn't exist.
  → **FIXED.**
- **`docs/README.md` structure tree is stale**: no `Model/Embed/`, `Model/Link/`, `Exception/`, `Support/Route.php`,
  `Support/RouteInitialization.php`, `data/profiles.php`, `data/privacy.html`, or `player.js`. The URL table has no
  `/imprint`, `/privacy` or `/releases/{slug}/wav`. nav.js is called "~20 lines" (it's 35); `index.php` is called
  9 lines here and in `CLAUDE.md` (it's 15).
  → **FIXED — tree, URL table and line counts all corrected.**
- **`docs/deployment.md` documents a PHPStorm FTP workflow** that `deploy.sh` has replaced — the `ill.` checklist
  itself says "`./deploy.sh`, 2026-09-04". Worth a line saying which is current, even though deploy.sh is
  gitignored.
- `docs/releases.md` checklist says "22/22 pass"; the suite is 33 checks now.
- `docs/branding.md` "A platform whose icon isn't vendored is skipped even if a URL is set" — describes a
  mechanism that can no longer trigger (see `iconSrc()` above).
  → **FIXED — `./deploy.sh` documented as the current path, with the credential-exclusion caveat.**

## test coverage gaps

Nothing wrong with what's there, just what isn't:

- no check that `/admin/stats` returns 401 (verified by hand: it does, the `pass_hash !== ''` guard holds)
- no check on `/imprint` or `/privacy`
- no HTTP-level check of the 503 "not uploaded yet" path — only the `link === null` default is asserted
- no check that download cards carry `data-no-spa` (if that attribute ever got dropped, the 303 would be
  swallowed by fetch and downloads would silently stop working — exactly the failure the attribute exists to
  prevent)

→ **ALL FIXED, and the suite was restructured into two.** `test/unit/` (PHPUnit, 174 tests) covers logic,
branches and escaping; `test/basic_test.sh` (40 checks) covers what unit tests structurally can't — the real
`autoload.php`, real HTTP, the `exit`-ing auth code, and repo hygiene. `composer test` runs both.
`DownloadController`/`ReleaseController`/`ReleasesController` gained an optional `ReleaseRepository`
parameter purely as the seam for the staged-release branch, which is the limitation the old script's own
comment complained about. See `docs/testing.md`.

## noted, not recommending action

- **no security headers at all** — no `Referrer-Policy`, `X-Content-Type-Options`, `CSP`. The one I'd actually
  consider is `Referrer-Policy: strict-origin-when-cross-origin`: once the consent gate is clicked, the
  SoundCloud iframe currently gets the full release URL as `Referer`. CSP is a bigger job — the `onerror=` inline
  handler on the cover `<img>` ([ReleaseView.php:70](src/NeuroSYS/View/ReleaseView.php:70)) would have to go first.
  → **STILL OPEN — deliberately. Worth doing `Referrer-Policy` on its own sometime; CSP needs the inline `onerror=` gone first.**
- **no HTTP method check anywhere** — `POST`/`PUT`/`DELETE /releases/ill/flac` all 303 like a GET (verified).
  Harmless on a read-only site; `Route` would need a method field to fix, which isn't worth it yet.
  → **STILL OPEN — deliberately, as noted.**
- **`StatsController::parseLog()` would fatal if `file()` returned false** (unreadable-but-existing log). Currently
  unreachable — `ENABLED` is false so `parseLog()` never runs. Worth remembering if logging is ever switched on,
  alongside the missing-`data/logs/` note already in `CLAUDE.md`.
  → **STILL OPEN — unreachable while logging is off.**
- **`Route::matches()` doesn't `preg_quote()` the pattern** ([Route.php:28](src/NeuroSYS/Support/Route.php:28)).
  Fine while all seven patterns are hardcoded and metacharacter-free; a future pattern with a `.` in it would
  quietly become a wildcard.
  → **MITIGATED — a unit test now asserts every registered pattern is metacharacter-free, so this can't silently start biting.**
- **`Auth` compares usernames with `!==`, not `hash_equals`** — timing side channel on a username, which is
  `admin`. Not worth the change.
  → **STILL OPEN — deliberately, as noted.**
- **percent-encoded slugs 404** (`/releases/hello%2Dworld`). Arguably correct — one canonical URL per release.
- **assets bypass site auth** — `.htaccess` passes real files through before `index.php` runs, so during pre-launch
  the CSS/JS/brand icons are public. Irrelevant for what's in there.
- `data/privacy.html` mentions "Kontaktformular" once in the boilerplate intro; there is no contact form.
- `data/.site_auth.php` gets uploaded by `deploy.sh` (rsync copies dotfiles). Inert — `Auth` looks for
  `site_auth.php` without the dot — but the preview password does end up sitting on the server.
  → **FIXED — `deploy.sh` now excludes it along with the other credential files.**
