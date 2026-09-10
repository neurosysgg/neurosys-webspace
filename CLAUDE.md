# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

It is the short layer: what the code is, the rules it keeps, the traps that fail silently, and the
commands. Each section ends in the document that carries the argument — read that before changing
what the section describes. How any of it got this way is in [`docs/history/`](docs/history/README.md),
and none of it is needed to change the code safely.

**Write docs the same way.** Present tense in `docs/`, past tense in `docs/history/`. When a rule
exists because of a story, the current document states the rule in one sentence and links the story.

## Stack

Plain PHP 8.5 / HTML / CSS, no framework, **no runtime dependencies**. PHP ≥ 8.5 is load-bearing
three times: the pipe operator in `autoload.php`, `#[\NoDiscard]` on the copy-returning builders, and
`ext/uri`, the WHATWG and RFC 3986 parsers `Element` and `Request` put their URL questions to.

Four extensions are named in `composer.json` and asked for by name in the verify script's
Environment block — the only place the question is asked where it matters, because composer never
runs on the server (`vendor/` is not deployed):

| Extension | Needed by | Without it |
|---|---|---|
| `ext/uri` | `Element`, `Request` | every page |
| `ext/dom` | `MarkupParser` (`Dom\HTMLDocument`) | a fatal on `/privacy` alone |
| `ext/openssl` | `PublicKey` — the API's signature check | a fatal on a signed call |
| `ext/zlib` | `UpdateApplier`'s `gzdecode()` | a fatal on a push |

Each was checked on the live host (Strato, PHP 8.5.9, `cgi-fcgi`) by being **used**, not by
`extension_loaded()` — registered and working are two questions. `php tools/api.php health v1 report`
asks the running deployment the same way.

**`ext/curl` is `require-dev` only.** The site makes no outbound request — the verify script asserts
it by grep — and the one class that does, `Tool\Http\CurlTransport`, is tooling `deploy.sh` never
uploads.

Composer and npm are dev tooling only (PHPUnit, phpcs, php-cs-fixer; TypeScript, esbuild, terser,
jsdom). Nothing on the PHP side is built; the front end's two outputs are committed, so the server
still gets plain files. **Node's floor is 26.7** because `--test-coverage-include-all` landed there;
an older runtime exits on the unknown flag and the 100% gate never runs, which is why `engines` is
enforced by `.npmrc`'s `engine-strict`.

## Local dev

```bash
npm run dev        # php -S localhost:8080 -t public tools/dev-router.php
```

**The router is not optional.** Assets are served under a build-stamp path segment
(`/assets/js/v-a1b2c3d4/main.js`) that `public/.htaccess` strips in production; the built-in server
reads no `.htaccess`, so without the router every stylesheet and module 404s locally. `composer
install` for tests and linters, `npm install` to touch the TypeScript; neither is needed to serve.

## Tests

```bash
composer test      # unit tests, then the end-to-end verify script
composer unit      # PHPUnit only
composer verify    # bash test/basic_test.sh only
composer coverage  # both suites, merged into one report
composer lint      # phpcs + php-cs-fixer, read-only

npm test           # node --test — the elements and the enum mirrors
npm run coverage   # the same, held at 100% lines, branches and functions
npm run check      # tsc over all three trees: assets/ts/, tools/*.mjs, test/js/*.mjs
```

Two suites for two things: `test/unit/` for logic and edge cases, `test/basic_test.sh` for the real
autoloader, real HTTP, the `exit`-ing auth code and repo hygiene. Separately neither coverage number
means much — PHPUnit cannot see `header()` or anything past an `exit` — so `composer coverage`
merges them. **The coverage figure is stated in exactly one place,
[docs/testing.md](docs/testing.md#php)**; re-derive it rather than copying it here.

- **A test that declares any `#[CoversClass]` records coverage for only those classes.** Name every
  class it exercises, including one it only calls — or that class reads 0% while running constantly.
- **Uncovered lines are a decision, not a budget.** A change that adds a guard covers it in the same
  commit; a guard no test can reach is deleted rather than covered by reflection.
- **Whatever ends the request is split from what decides it** — `Auth::accepts()` beside the
  `require*` methods, `SecurityHeaders::headers()` beside `send()` — so the decision can be asserted.
- **The client tests run against the compiled JS** in `public/assets/js/`, so a build that never ran
  is a failing test. The verify script also runs them against the shipped bundle.
- **`npm run coverage` is a gate**, run with `--test-coverage-include-all` so a module nothing
  imports reads as uncovered rather than unreported. A fallback no test can reach fails it: remove
  the fallback.
- The verify script skips the client-side checks with a NOTE when `npm install` has never run, so
  `composer test` works on a bare clone; the `style.css` drift check needs only `node` and always runs.

See [docs/testing.md](docs/testing.md) for the split and every invariant the suites protect.

## Architecture

MVC in plain PHP classes — no template files, no `extract()`, no inline echo, and no HTML written as
a string: a view returns a tree of nodes. [docs/architecture.md](docs/architecture.md) traces a
request end to end.

```
src/NeuroSYS/
├── Controller/     ← one class per route group; fetches its own data, returns a Response
├── Http/           ← Request, the Response types, every header as a typed name and value
│   ├── Api/        ← what an /api address is made of: service, version, action, handler
│   └── Security/   ← CSP, Permissions-Policy and HSTS as typed objects
├── Model/          ← Release, Demo, Profile, Waveform and the enums they compose
│   ├── Production/ ← what the .flp knows: Arrangement, Section, ProductionTime, Plugin
│   ├── Embed/      ← Embed + SoundCloudEmbed (one track) + SoundCloudProfileEmbed (the account)
│   ├── Link/       ← FileLink + HiDriveLink
│   ├── Api/        ← a signed call: ApiCredential, ApiEnvelope, VerifiedRequest
│   ├── Update/     ← a push: UpdateManifest, UpdateRoot, Deployment, UpdateFile, UpdateReport
│   └── Health/     ← what a deployment says about itself
├── Service/        ← Auth, the repositories, DownloadLogger, ApiGate, UpdateApplier
│   └── Api/        ← one ApiHandler per action: UpdatePatch, UpdateVersion, HealthReport
├── Support/        ← Collection, SearchableCollection, File, Directory, Route + SitePath,
│                     Diagnostics, TarArchive, PasswordHash, PublicKey, the Bare* attributes
├── Exception/      ← SiteException and every condition under it
├── View/           ← one View per page; each returns a Node
│   ├── Html/       ← the markup tree, MarkupParser, and the tag/attribute vocabularies
│   └── Terminal/   ← Terminal, TerminalCommand, TerminalField
├── Config.php      ← the facts about this site: identity, origins, paths, switches
├── DataFile.php    ← every file the site reads out of data/, named rather than spelled
├── Layout.php      ← wrap(View): Document — the full HTML shell
└── Router.php      ← URL → Controller; no data dependencies
```

`tools/` is the other tree — seven commands on a CLI layer of their own, the release and demo
tooling, the `.flp` reader, the DSP port and the signing side of the API. It has its own autoloader
and is never deployed. See [docs/tooling.md](docs/tooling.md).

`public/index.php` is six statements: install the last-resort handler → security headers → parse the
request → site auth check → `Router::dispatch()` → send. **The handler is first because it has to
work when nothing else did**: it logs, sends a 500 if headers are still unsent, writes `500`, and
depends on nothing but `SiteException`.

## Rules

Each of these replaces a habit that fails silently with one that fails loudly. They are the reason
the code looks the way it does; follow them in new code without being asked.

- **Nothing builds HTML from a string.** A view returns a `Node`; only `Element` and `Doctype` write
  a `<`, and the verify script fails a heredoc or a `'<tag'` literal anywhere else under `src/`.
  Escaping and the URL-scheme check both live in `render()`, so they hold however an element was
  built. [architecture.md](docs/architecture.md#the-markup-tree)
- **Hand-authored HTML enters only through `Element::containingHtml()`**, which parses it against
  this site's own vocabulary and refuses the rest. Never parse anything a request can influence.
- **Names and values are typed.** A header is a `HeaderName` case and a `HeaderValue`; an attribute
  is an `AttributeName` case and an enum case or `AttributeValue` class for its value. A value with
  a grammar is a class, a fixed vocabulary is an enum. [architecture.md](docs/architecture.md#http--the-wire)
- **A group crossing a public boundary is a `Collection` or `SearchableCollection`** — immutable
  (`with()` copies), lazy (steps fuse into one pass at the first materialiser), and not a
  replacement for a variadic. End a chain in `settled()` when a step does work.
  [collections.md](docs/collections.md)
- **Five habits are refused by `GuidelineTest`**: a bare `array` in a declared type, a bare string
  that is really a name, an `array_*` call a collection has a member for, `@`, and an SPL exception.
  An exception to the first three is an attribute with a reason — `#[BareArray]`, `#[BareString]`,
  `#[BareCall]`; the last two have none. [guidelines.md](docs/guidelines.md)
- **Our exceptions live in `NeuroSYS\Exception`, implement `SiteException`, and extend the SPL class
  they replace**, so every existing `catch` still matches. [architecture.md](docs/architecture.md#exceptions)
- **Builders and queries carry `#[\NoDiscard]` with a message**, and `failOnWarning` makes a dropped
  result a failing test. A deliberate discard in a test is spelled `(void)`.
- **Every address is a `SitePath` case**, and a link is `SitePath::X->to(…)`, never a concatenation.
  Tests still write paths out in full, so a wrong enum fails.
- **A data file is `Config::dataFile(DataFile::X)`, a `File`**, never `is_file()` and
  `file_get_contents()`. A suppressed diagnostic is `Diagnostics::muted()`, never `@`.
- **`Config` holds identity, environment, and facts otherwise stated twice** — nothing else. A fact
  that means something only inside one class stays in that class, where its docblock can say why.
- **A method that ends the request has a public twin that decides it**, so the decision can be
  asserted: `Auth::accepts()`, `SecurityHeaders::headers()`.

## Traps

These fail silently — no error, no log, a page that looks fine. Each links the argument.

**PHP** — [architecture.md](docs/architecture.md), [collections.md](docs/collections.md)
- `SitePath::to()`'s filler must be `function () use (&$values)`. `fn()` captures by value, so every
  placeholder takes the first value — `/releases/ill/ill`, well formed, matching a route, wrong.
- `!== []` is true of every `Collection`; ask `isEmpty()`.
- A mapped `SearchableCollection` keeps its string keys, and string keys spread as named arguments;
  spread `toValues()`.
- Never decide a URL is on-site by "starts with a slash" or a list of prefixes: `/\r\n/host` is
  `https://host` to a browser. `Element::staysOnThisOrigin()` resolves it with the WHATWG parser.
- `File` never creates a directory — never add `@mkdir` for `data/logs/`. Creating one is
  `Directory`'s job and the caller's decision.
- A regex that validates ends in `\z`, not `$` — `$` matches before a trailing newline.
- A page that reads a request header declares it in `View::varyOn()`, or a cache hands one visitor
  the copy built for another.

**Requests and auth** — [docs/security.md](docs/security.md)
- Never `parse_url()` the request target: it fails with `false`, which `??` does not guard.
  `Request::path()` uses `Uri\Rfc3986\Uri::parse()`, which answers null.
- An unparseable target still matches a `{slug}` route, because `{slug}` compiles to `[^/]+`. A slug
  that reaches a header goes through `Auth::demoRealm()`'s `rawurlencode`, and `BasicChallenge`
  refuses anything but `qdtext`.
- `AuthScheme::Basic` is the only spelling of the token on both sides of the handshake — a mismatch
  makes both gates refuse everything, identically, with nothing in any log.
- A misspelled `ServerVariable` or `DataFile` is not an error but a default: `PHP_AUTH_USER` wrong
  is a 401 that reads as a bad password, `site_auth.php` wrong stands the pre-launch gate down.

**The API and deploying** — [docs/security.md](docs/security.md#the-api),
[docs/deployment.md](docs/deployment.md)
- **`data/update.pub` absent means `/api` is off; `data/site_auth.php` absent means the site gate is
  off.** The two files look alike and have opposite polarity.
- `public/api/` must never exist, and an API action never reads a query parameter — it would reach
  the handler unsigned.
- `ApiGate` refuses an unrecognised (null) method on its first line; comparing it would be a 500
  where an absent address sends a 405.
- A write spends its serial **before** applying; a dry run and a read never spend one.
- `update.pub` and `cgi-bin/.update-serial` keep their names for every service — renaming either is
  a hand upload and a serial reset.
- `Config::webroot()` takes only `DOCUMENT_ROOT`'s basename and refuses a blank, relative,
  outside-the-deployment or nonexistent root; `UpdateApplier` takes its `Deployment` by constructor,
  so a test cannot reach the live tree.
- The mirror is an enumerated delete: it never follows a symlink and never calls
  `Directory::remove()`.
- A push leaves byte-identical files untouched, because rewriting a file the request is executing
  makes NFS silly-rename it into an undeletable `.nfsXXXXXXXX`. Remove a stray over the mount.
- A server not yet running the `/api` code can only be updated by `./deploy.sh`; `--url` is an origin.
- Probe the live host by pushing from a **detached worktree at `HEAD`** — the push mirrors the whole
  tree, so a dirty working tree would ship the change being checked for.
- A shared host can gain or lose an Apache module without notice, and every `.htaccess` block is
  `<IfModule>`-guarded, so the failure is silent both ways. Re-check after deploying.

**Front end and builds** — [docs/frontend.md](docs/frontend.md)
- A bundled class name needs **both** esbuild `keepNames` and terser `keep_classnames`, or the
  misnesting error a guard throws reads `must be inside <P>`.
- Never cache-bust with `?v=` on an import specifier: V8 attributes the module to a URL the coverage
  include does not match, and the 100% gate collapses. The build stamp is a path segment instead.
- Both `php -S` invocations — `npm run dev` and the verify script's — must load `tools/dev-router.php`.
- `npm run watch` rebuilds neither the stylesheet nor the manifest; run `npm run build` before
  committing.
- The debug and prod manifests differ on purpose (46 preloads against none) — do not add a diff
  between them.
- The build tools refuse an undeclared flag; keep it that way, since a misspelled `--out` once
  overwrote the committed stylesheet and reported success.
- A view must never emit `loaded` — the gate sets it, and the stylesheet reads it.

**Tests** — [docs/testing.md](docs/testing.md)
- `node --test` with no argument runs `test/js/dom.mjs` as a suite; always pass the quoted
  `'test/js/*.test.mjs'` so node, not the shell, expands it.
- A test helper with a destructured `= {}` parameter drops every property without its own default
  from the inferred type, so its call sites check nothing.
- `max_execution_time` is `'0'` when unlimited, and `'0'` is falsy — `HealthFact` asks `=== ''`.

**Tooling** — [docs/tooling.md](docs/tooling.md)
- `ffprobe` exits 0 on a text file named `*.flac` and takes the codec from the extension;
  readability is decided by asking for a **sample rate**.
- `Probe::decode()` is `popen()`, never `exec()` — `exec()` splits float bytes on newlines.
- `getopt()` stops at the first operand, and `composer coverage` passes its paths first; `Input`
  parses against declared `Option`s and refuses anything else.
- A tooling class never goes under `src/`: `deploy.sh` ships `src/` with `--delete`, and it is
  PHPUnit's coverage source.
- A `.flp` that parses without a tempo is a desynced read, not a project without one; ending on the
  `FLdt` boundary proves nothing. A playlist clip is 32 or 80 bytes by FL version and is probed.
- Uploads are private and there is no `--public`. SoundCloud credentials and tokens never go in
  `data/`, which `deploy.sh` rsyncs to the webroot's neighbour.

## Front end

TypeScript → browser-native ES modules. `public/` is the **debug** tree — plain `tsc` output, one
module per file, source-mapped, committed because the tests, the coverage gate and the drift check
read it by path. `npm run build:prod` derives the **prod** tree in `build/dist/`: bundled by esbuild,
minified by terser, no maps. That is what ships. [docs/frontend.md](docs/frontend.md)

```bash
npm run build        # tsc + style.css + AssetManifest.php (manifest last: it hashes both)
npm run build:prod   # build/dist/ — what deploy.sh and push-update ship
npm run watch        # tsc on save only
```

- **Never hand-edit `public/assets/js/` or `public/assets/css/style.css`.** Edit `assets/ts/` and
  `assets/css/`; the verify script rebuilds and diffs both.
- **Adding an element** is a `Tag` case, a module named for its class under `assets/ts/elements/`,
  and an import in `main.ts` — `main.ts` is the vocabulary.
- **Every fact the client reads out of the server's output is a mirrored enum** in
  `assets/ts/model/`, compared case for case by `enum-parity.test.mjs`. See
  [docs/contracts.md](docs/contracts.md) before renaming one.

## Content

**A release** is one entry in `data/releases.php`. `php tools/stage-release.php <folder>` writes most
of it from a prepared folder (`--project` for the `.flp`); `php tools/release-track.php <folder>
--upload` uploads the track privately and fills in the SoundCloud ids. See
[docs/releases.md](docs/releases.md) and [docs/authoring.md](docs/authoring.md).

```php
'your-slug' => new Release(
    title:       'track title',
    bpm:         140,
    key:         MusicalKey::FSharpMajor,
    genre:       Genre::Dubstep,
    description: 'debut single',
    cover:       new HiDriveLink('J2FXbB70A'),   // the 9-char share id — never a full URL
    formats: new Collection(Format::class)->with(
        new Format(ReleaseFormat::FLAC,  new HiDriveLink('BXRsy9S7d')),
        new Format(ReleaseFormat::STEMS),            // no link: the card shows "not uploaded yet" (503)
    ),
    embed: new SoundCloudEmbed(trackId: 2394077313, permalink: 'ill', secretToken: 's-dIMAqki109G'),
    arrangement: new Arrangement(new Collection(Section::class)->with(Section::named('DROP', 12288))),
    timeSpent: ProductionTime::of(60, 7),
    madeWith: new Collection(Plugin::class)->with(new Plugin('Serum 2')),   // hand-authored
),
```

**A demo** is unreleased work at `/demos/{slug}` behind a password minted per demo, its audio under
`data/demos/` where only PHP can reach it. `php tools/stage-demo.php <file>…` mints, transcodes,
analyses and prints the entry; `--rotate` and `--waveforms` work on a demo already staged. There is
no `/demos`, and `data/demos.php` is gitignored but deployed. See [docs/demos.md](docs/demos.md).

**Download logging is off for legal reasons** — `Config::DOWNLOAD_LOGGING` is `false`, so the
referrer is never read and nothing is written. Turning it on is a privacy-policy change in both
languages first; `data/logs/` must also exist on the server, since `fopen` creates the file and not
its directory and `deploy.sh` excludes it.

## The API and deploying

`/api/{service}/{version}/{action}` is the one address family that writes. Every call is signed with
an ECDSA P-256 key the server cannot use; an unsigned call gets exactly what an absent address gets.
See [docs/security.md](docs/security.md#the-api) and [docs/deployment.md](docs/deployment.md).

```bash
npm run build:prod && php tools/push-update.php --dry-run   # validate, report, write nothing
npm run build:prod && php tools/push-update.php             # public/ + src/ + autoload.php
php tools/api.php update v1 version                         # what is deployed
php tools/api.php health v1 report                          # what this host actually is
./deploy.sh                                                 # full deploy over SFTP; ships data/
```

- **The push is the regular deploy; `./deploy.sh` is the full one and the recovery path** — it owns
  `data/`, and it fixes a push that broke `src/`. Do not make the endpoint replace it.
- **`deploy.sh` excludes `data/admin.php`, `data/site_auth.php` and `data/update.pub`** — the repo's
  `admin.php` is a placeholder with an empty hash, and the other two are gitignored and exist only
  per deployment. All three hold live credentials; upload them by hand.
- **`--delete` is on for `public/` and `src/`, off for `data/`**, so a gitignored demo on the server
  survives a deploy from a clone that never staged it — and a data file removed locally must be
  removed from the server by hand.
- **PHPStorm's right-click upload of `public/` ships the debug tree**, maps and all. Fine for one
  file in a hurry, not for a deploy.
- **Re-check `.htaccess` after deploying** — compression and caching sit behind `<IfModule>`, and
  the version-segment rewrite is the highest-risk line in the file. The curl checks are in
  [docs/deployment.md](docs/deployment.md).

## Documents

| Document | Read before touching |
|---|---|
| [docs/README.md](docs/README.md) | anything — the map, the URLs, how a request flows |
| [docs/architecture.md](docs/architecture.md) | the PHP side: layers, the type discipline, the markup tree, headers, exceptions, `Config`, language |
| [docs/collections.md](docs/collections.md) | `Collection` / `SearchableCollection`, or anything that holds a group |
| [docs/guidelines.md](docs/guidelines.md) | a bare array, a bare string, an `array_*` call, an `@`, a `throw` — or `GuidelineTest` failing |
| [docs/frontend.md](docs/frontend.md) | the TypeScript, the CSS, the builds, the elements, SPA navigation |
| [docs/contracts.md](docs/contracts.md) | any name or value both languages know |
| [docs/testing.md](docs/testing.md) | the suites, the invariants, coverage |
| [docs/security.md](docs/security.md) | auth, headers, the API, what is known and accepted |
| [docs/deployment.md](docs/deployment.md) | the push, `deploy.sh`, Strato, `.htaccess` |
| [docs/performance.md](docs/performance.md) | anything on the hot path |
| [docs/releases.md](docs/releases.md) · [docs/authoring.md](docs/authoring.md) | a release, or the tools that stage one |
| [docs/demos.md](docs/demos.md) | a demo, or the password gate |
| [docs/tooling.md](docs/tooling.md) | anything under `tools/` |
| [docs/branding.md](docs/branding.md) | icons and profile links |
| [docs/history/](docs/history/README.md) | nothing — it is how things got this way |
