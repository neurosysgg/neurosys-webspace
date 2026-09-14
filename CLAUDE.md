# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

It is the short layer: what the code is, the rules it keeps, the traps that fail silently, and the
commands. Each section ends in the document that carries the argument — read that before changing
what the section describes. How any of it got this way is in [`docs/history/`](docs/history/README.md),
and none of it is needed to change the code safely.

**Write docs the same way.** Present tense in `docs/`, past tense in `docs/history/`. When a rule
exists because of a story, the current document states the rule in one sentence and links the story.

## The framework

**`phpanta/` is Phpanta, the framework this site grew, vendored and edited in place.** It has its own
[CLAUDE.md](phpanta/CLAUDE.md), docs, tests and licence — read its CLAUDE.md before touching anything
under it. **Nothing under `phpanta/` may name this site**: `BoundaryTest` resolves every name the
framework's code writes, and the verify script fails any mention of the site's namespace anywhere
under it, comments and docs included. Every `{@link}` under it must land on a framework class or PHP's
own, which `BoundaryTest` checks too, and every link in its documents must stay inside it, which the
verify script checks, so an example borrowed from this site fails rather than dangling. The site's
facts reach the framework through `Site`, the app.
The rules and traps below hold for both trees; the framework's own file states them without the site.

**It is a git submodule**, at the relative URL `../phpanta.git`, so GitHub and HiDrive each resolve
their own copy (`neurosysgg/phpanta`, `users/ecki590/phpanta.git`); clone with
`--recurse-submodules`. A change to the framework is a commit in `phpanta/`, then `git add phpanta`
and a commit in the site — `push-update` refuses anything else. This clone sets `submodule.recurse`,
`push.recurseSubmodules=on-demand` and `status.submoduleSummary`, so a checkout moves the framework
with the site and a push sends the framework commit the site records first. **On-demand skips a
framework commit any of the submodule's remotes already has**, so `git push hidrive` after `git push
origin` would leave HiDrive's framework behind and a clone from there unable to check out; the
submodule's `origin` therefore has two push URLs, GitHub and HiDrive, and one push reaches both. That
is local config (`.git/modules/phpanta/config`) — a fresh clone sets it again with
`git -C phpanta remote set-url --add --push origin <url>`, once per remote. **Checking out or bisecting across the commit that made `phpanta/` a
submodule needs `--no-recurse-submodules`** (`git log -1 -- .gitmodules` names it), and after a
remote's URL changes, `git submodule sync`.

## Stack

Plain PHP 8.5 / HTML / CSS on Phpanta, **no runtime dependencies**. PHP ≥ 8.5 is load-bearing
three times: the pipe operator in `autoload.php`, `#[\NoDiscard]` on the copy-returning builders, and
`ext/uri`, the WHATWG and RFC 3986 parsers `Element` and `Request` put their URL questions to.

Five extensions are named in `composer.json` and asked for by name in the verify script's
Environment block — the only place the question is asked where it matters, because composer never
runs on the server (`vendor/` is not deployed):

| Extension | Needed by | Without it |
|---|---|---|
| `ext/uri` | `Element`, `Request` | every page |
| `ext/dom` | `MarkupParser` (`Dom\HTMLDocument`) | a fatal on `/privacy` alone |
| `ext/intl` | the text layer's `MessageFormatter` | a fatal on every translated page |
| `ext/openssl` | `PublicKey` — the admin's signature and passkey checks; `SessionSeal` | a fatal on a signed call |
| `ext/zlib` | `UpdateApplier`'s `gzdecode()` | a fatal on a push |

Each was checked on the live host (Strato, PHP 8.5.9, `cgi-fcgi`) by being **used**, not by
`extension_loaded()` — registered and working are two questions. `php tools/api.php health v1
extensions` asks the running deployment the same way; `capability v1 extensions` lists what it has
merely registered. What each runtime — Strato, the local Apache, the CLI — actually has is read
out in [docs/runtime.md](docs/runtime.md).

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
npm run dev        # php -S localhost:8080 -t public phpanta/tools/dev-router.php
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
autoloader, real HTTP, what `header()` really sends and repo hygiene. Separately neither coverage number
means much — `header()` is a no-op under CLI, so `App::run()` and `Answer::send()` are the verify
script's alone — and `composer coverage` merges them. **The coverage figure is stated in exactly one place,
[docs/testing.md](docs/testing.md#php)**; re-derive it rather than copying it here.

- **A test that declares any `#[CoversClass]` records coverage for only those classes.** Name every
  class it exercises, including one it only calls — or that class reads 0% while running constantly.
- **Uncovered lines are a decision, not a budget.** A change that adds a guard covers it in the same
  commit; a guard no test can reach is deleted rather than covered by reflection.
- **Every answer is asserted in-process.** `TestRequest::get('/admin/update')->answer()` is
  `App::handle()` — gate, router, controller, security headers, nothing sent — so a 401, a 303 or a
  405 is a status, headers and a body a test reads, with no `$_SERVER` and no reflection.
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
src/NeuroSYS/                  ← this site
├── Controller/     ← one class per route group; fetches its own data, returns a Response
├── Model/          ← Release, Demo, Profile, Waveform and the enums they compose
│   ├── Production/ ← what the .flp knows: Arrangement, Section, ProductionTime, Plugin
│   ├── Embed/      ← Embed + SoundCloudEmbed (one track) + SoundCloudProfileEmbed (the account)
│   └── Link/       ← FileLink + HiDriveLink
├── Service/        ← the repositories, DemoGate, DownloadLogger
├── Support/        ← SitePath and the route table
├── Exception/      ← ReleaseVerificationException, TerminalException
├── Text/           ← every word the site says, in both languages: Texts and the catalogs
├── View/           ← one View per page; each returns a Node
│   ├── Html/       ← the site's own Tag, attribute and CssClass cases
│   └── Terminal/   ← Terminal, TerminalCommand, TerminalField
├── AssetManifest.php ← GENERATED by the build: the stamp, the stylesheet, the script
├── DataFile.php    ← every file the site reads out of data/, named rather than spelled
├── Layout.php      ← the app's Shell — the full HTML document around a page
└── Site.php        ← the app: identity, origins, switches, and what Phpanta asks of a site

phpanta/src/                   ← Phpanta\, the framework — see phpanta/CLAUDE.md
├── Controller/     ← the Controller interface, the admin's controller, UnroutedController
├── Http/           ← Request, Input, Upload, Session, Answer, the Response types, every header as
│   │                 a typed name and value
│   ├── Api/        ← what an /admin address is made of: service, version, action, handler, listing
│   └── Security/   ← CSP, Permissions-Policy, HSTS, COOP and CORP as typed objects
├── Form/           ← a form as an enum of fields, its rules, a submission read and re-rendered
├── Data/           ← SQLite through PDO: statements, typed rows, transactions, migrations
├── Model/          ← Api/ (a signed call), Update/ (a push, the release it replaced), Health/,
│                     Passkey/ (a device, a challenge, a ceremony's answer)
├── Service/        ← Auth, Login, ApiGate, UpdateApplier, ReleaseRecord; Layer/ (the gates and
│                     layers around a controller); Api/ one handler per action; Health/;
│                     Passkey/ (the admin's browser side: AdminBrowser, the verifier, the store)
├── Support/        ← Collection, SearchableCollection, File, Directory, Route + Path, AdminPath,
│                     the requirement table, Diagnostics, TarArchive, PasswordHash, PublicKey, Bare*
├── Exception/      ← SiteException and every condition under it
├── Text/           ← Language, Languages, Translatable, Translation, the framework's own words
├── View/           ← View, Shell, and Html/ — the markup tree, MarkupParser, Vocabulary
├── App.php         ← what a site tells the framework about itself; run() is the request
├── DataFileName.php + CredentialFile.php ← data files by name; the framework's three credentials
└── Router.php      ← URL → Controller; no data dependencies
```

`tools/` is the site's other tree — its commands, the release and demo tooling, the `.flp` reader
and the DSP port. The CLI layer they stand on, the signing side of the API, the build tools, the dev
router and the coverage prepend are Phpanta's, in `phpanta/tools/`. Both have an autoloader of their
own and neither is deployed. See [docs/tooling.md](docs/tooling.md).

`public/index.php` is two statements after the autoloader, which boots `Site`: install the
last-resort handler, then `Site::current()->run()` — Phpanta's `App::run()`, which points the error
log at `data/logs/` → security headers → parse the request → site auth check → `Router::dispatch()` →
send. **The handler is first because it has to work when nothing else did**: it logs, sends a 500 if
headers are still unsent, writes `500`, and depends on nothing but `SiteException`.

## Rules

Each of these replaces a habit that fails silently with one that fails loudly. They are the reason
the code looks the way it does; follow them in new code without being asked.

- **Nothing builds HTML from a string.** A view returns a `Node`; only `Element` and `Doctype` write
  a `<`, and the verify script fails a heredoc or a `'<tag'` literal anywhere else under `src/` or
  `phpanta/src/`.
  Escaping and the URL-scheme check both live in `render()`, so they hold however an element was
  built. [architecture.md](docs/architecture.md#the-markup-tree)
- **Hand-authored HTML enters only through `Element::containingHtml()`**, which parses it against
  this site's own vocabulary and refuses the rest. Never parse anything a request can influence.
- **Visible text is a `Translatable`, and a view never names a language.** It writes
  `Texts::Releases::Downloads`; the tree puts it into the nearest `lang` when it renders, and
  `TranslationTest` fails a catalog case without its German, and a word a view writes as a literal.
  [language.md](phpanta/docs/language.md)
- **Names and values are typed.** A header is a `HeaderName` case and a `HeaderValue`; an attribute
  is an `AttributeName` case and an enum case or `AttributeValue` class for its value. A value with
  a grammar is a class, a fixed vocabulary is an enum. [architecture.md](docs/architecture.md#http--the-wire)
- **A group crossing a public boundary is a `Collection` or `SearchableCollection`** — immutable
  (`with()` copies), lazy (steps fuse into one pass at the first materialiser), and not a
  replacement for a variadic. End a chain in `settled()` when a step does work.
  [collections.md](phpanta/docs/collections.md)
- **Five habits are refused by `GuidelineTest`**: a bare `array` in a declared type, a bare string
  that is really a name, an `array_*` call a collection has a member for, `@`, and an SPL exception.
  An exception to the first three is an attribute with a reason — `#[BareArray]`, `#[BareString]`,
  `#[BareCall]`; the last two have none. [guidelines.md](phpanta/docs/guidelines.md)
- **Exceptions live in `Phpanta\Exception` — or `NeuroSYS\Exception` for the two only this site
  throws — implement `SiteException`, and extend the SPL class they replace**, so every existing
  `catch` still matches. [architecture.md](docs/architecture.md#exceptions)
- **Builders and queries carry `#[\NoDiscard]` with a message**, and `failOnWarning` makes a dropped
  result a failing test. A deliberate discard in a test is spelled `(void)`.
- **Every address is a `SitePath` case**, and a link is `SitePath::X->to(…)`, never a concatenation.
  Tests still write paths out in full, so a wrong enum fails.
- **A data file is `Site::current()->dataFile(DataFile::X)`, a `File`**, never `is_file()` and
  `file_get_contents()`. A suppressed diagnostic is `Diagnostics::muted()`, never `@`.
- **`Site`'s constants hold identity, environment, and facts otherwise stated twice** — nothing else;
  its methods answer what Phpanta asks of a site. A fact that means something only inside one class
  stays in that class, where its docblock can say why.
- **A gate is its route's, not its controller's.** A gated page is `->through(…)` in
  `RouteInitialization`; no site route has one today, and `RoutingTest` asserts that none carries
  `AdminGate`. The demo gate stays in its controllers only because it needs the demo it looks up.
- **Nothing ends the request but `App::run()`; every decision returns.** A response becomes an
  `Answer` in `App::handle()`, and a gate's refusal is a value it returns, `#[\NoDiscard]` —
  `Auth::siteGate()`, `DemoGate::enter()` — which the caller returns in turn. Never `exit`.

## Traps

These fail silently — no error, no log, a page that looks fine. Each links the argument.

**PHP** — [architecture.md](docs/architecture.md), [collections.md](phpanta/docs/collections.md)
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

**Language** — [docs/language.md](phpanta/docs/language.md)
- `Translatable` is asked before `BackedEnum`: a catalog case is both, and read as an enum it renders
  its key. A translatable with no `lang` above it throws — render a view with `->render(0, $language)`.
- Every page varies on `Accept-Language` and `Cookie`; `ViewResponse` says so for all of them.
- A demo's description is never a catalog case — `src/` is public. It is inline in `data/demos.php`.
- The verify script's "no markup from a string" grep reads comments: an apostrophe followed on the
  same line by a `<tag` fails it.

**Requests and auth** — [docs/security.md](docs/security.md)
- Never `parse_url()` the request target: it fails with `false`, which `??` does not guard.
  `Request::path()` uses `Uri\Rfc3986\Uri::parse()`, which answers null.
- An unparseable target still matches a `{slug}` route, because `{slug}` compiles to `[^/]+`. A slug
  that reaches a header goes through `DemoGate::realm()`'s `rawurlencode`, and `BasicChallenge`
  refuses anything but `qdtext`.
- `AuthScheme::Basic` is the only spelling of the token on both sides of the handshake — a mismatch
  makes both gates refuse everything, identically, with nothing in any log.
- A misspelled `ServerVariable` or `DataFile` is not an error but a default: `PHP_AUTH_USER` wrong
  is a 401 that reads as a bad password, `site_auth.php` wrong stands the pre-launch gate down.

**The API and deploying** — [docs/security.md](docs/security.md#the-admin),
[docs/deployment.md](docs/deployment.md)
- **`data/update.pub` absent means no signed call verifies and no device can be enrolled;
  `data/site_auth.php` absent means the site gate is off.** The two files look alike and have
  opposite polarity. `data/admin-passkeys.json` absent means no device is enrolled.
- **Passkeys are on because `Site::origin()` names `https://neurosys.gg`** — and only where
  `data/session.key` exists; without it the entrance says browsers cannot sign in. In development
  and from loopback only, the request's own `Origin` comes first, so the local Apache runs a real
  ceremony at `neurosys.localhost` — with a key registered there, which opens nothing live. A revocation takes effect on the
  next request; a browser write's tap binds `POST <path>`, not the field values.
- **A caller the admin cannot verify gets one answer at every depth below `/admin`**, whether the
  address exists or not — a `303` to `/admin` for a page, a `401` challenging for `NS1` for data,
  never an `Allow`. An answer that differed for a real address would tell a stranger what is in it,
  and nothing would look wrong.
- `public/admin/` must never exist, and an admin action never reads a query parameter or a form
  field — it would reach the handler unsigned. The framework's `InputTest` reads the API's code and
  fails on either. A browser's form is read by `AdminBrowser` alone, into a manifest of the fields
  the action declares.
- **`data/logs/` must exist and be writable by PHP, or the error log silently falls back to the
  host's own log** — PHP says nothing when it cannot open the file. `deploy.sh` never creates it;
  `health v1` warns. Locally php-fpm runs as `http`, so the directory is group `http`, `2775`.
  `data/throttle/` likewise, or the admin's entrance answers `503` to every post.
- `ApiGate` refuses an unrecognised (null) method on its first line; comparing it would be a 500
  where every other stranger gets the admin's one answer.
- A write spends its serial **before** applying; a dry run and a read never spend one.
- `update.pub` and `cgi-bin/.update-serial` keep their names for every service — renaming either is
  a hand upload and a serial reset.
- `App::webroot()` takes only `DOCUMENT_ROOT`'s basename and refuses a blank, relative,
  outside-the-deployment or nonexistent root; `UpdateApplier` takes its `Deployment` by constructor,
  so a test cannot reach the live tree.
- The mirror is an enumerated delete: it never follows a symlink and never calls
  `Directory::remove()`.
- A push leaves byte-identical files untouched, because rewriting a file the request is executing
  makes NFS silly-rename it into an `.nfsXXXXXXXX` that lives as long as the worker holding it. The
  mirror reports a stray in a note, never as a failure; removing one over the mount is tidiness.
- A server not yet running the `/admin` code can only be updated by `./deploy.sh`; `--url` is an origin.
- A fault is shown in full only when `PHPANTA_ENVIRONMENT=development` **and** the request is from
  loopback — never `SetEnv` it on Strato; `health v1` warns if a deployment says it. The dev router
  sets it for `php -S`; the local Apache needs the line in its vhost. [runtime.md](docs/runtime.md)
- A health check **returns** its 503 — a thrown `ApiException` is a 422 — and `Requirement::check()`
  never throws: nothing catches it, so one throw is a 500 for the whole report. [health.md](phpanta/docs/health.md)
- Probe the live host by pushing from a **detached worktree at `HEAD`** — the push mirrors the whole
  tree, so a dirty working tree would ship the change being checked for. Run `git submodule update
  --init` in it: `push-update` refuses a `phpanta/` that is missing, has uncommitted changes, or is
  not the commit `HEAD` records — `--any-framework` is the deliberate way past.
- A shared host can gain or lose an Apache module without notice, and every `.htaccess` block is
  `<IfModule>`-guarded, so the failure is silent both ways. Re-check after deploying.
- Strato buffers no output and the local Apache buffers 4096 bytes, so a stray byte before a
  `header()` works locally and costs the live response its headers. Strato also drops notices and
  deprecations (`error_reporting` 22519). [runtime.md](docs/runtime.md)
- `public/.user.ini` is PHP's per-directory php.ini: `.htaccess` and `phpanta/tools/dev-router.php` both hand
  it to the router for an ordinary 404 (a deny would 403, which says it exists). Strato caches it for
  300 s, and it cannot switch on `opcache.enable`.

**Front end and builds** — [docs/frontend.md](docs/frontend.md)
- A bundled class name needs **both** esbuild `keepNames` and terser `keep_classnames`, or the
  misnesting error a guard throws reads `must be inside <P>`.
- Never cache-bust with `?v=` on an import specifier: V8 attributes the module to a URL the coverage
  include does not match, and the 100% gate collapses. The build stamp is a path segment instead.
- Both `php -S` invocations — `npm run dev` and the verify script's — must load `phpanta/tools/dev-router.php`.
- `npm run watch` rebuilds neither the stylesheet nor the manifest; run `npm run build` before
  committing.
- The debug and prod manifests differ on purpose (49 preloads against none) — do not add a diff
  between them.
- The build tools refuse an undeclared flag; keep it that way, since a misspelled `--out` once
  overwrote the committed stylesheet and reported success.
- A view must never emit `loaded` — the gate sets it, and the stylesheet reads it.
- `Passkey.start()` in `main.ts` is unconditional: a passkey form arrives with a `Navigation` swap
  after the entry script ran, so it listens at the document.

**Tests** — [docs/testing.md](docs/testing.md)
- `node --test` with no argument runs `test/js/dom.mjs` as a suite; always pass the quoted
  `'test/js/*.test.mjs'` so node, not the shell, expands it.
- A test helper with a destructured `= {}` parameter drops every property without its own default
  from the inferred type, so its call sites check nothing.
- `max_execution_time` is `'0'` when unlimited, and `'0'` is falsy — `HealthFact` asks `=== ''`,
  and a floor is told which value means no limit (`-1` for `memory_limit`, `0` for `post_max_size`).

**Tooling** — [docs/tooling.md](docs/tooling.md)
- `ffprobe` exits 0 on a text file named `*.flac` and takes the codec from the extension;
  readability is decided by asking for a **sample rate**.
- `Probe::decode()` is `popen()`, never `exec()` — `exec()` splits float bytes on newlines.
- `getopt()` stops at the first operand, and `composer coverage` passes its paths first; `Input`
  parses against declared `Option`s and refuses anything else.
- A tooling class never goes under `src/` or `phpanta/src/`: both ship to the server, and both are
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
    description: Texts::Releases::Descriptions::HelloWorld,   // both languages; a plain string works too
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

**Download logging is off for legal reasons** — `Site::DOWNLOAD_LOGGING` is `false`, so the
referrer is never read and nothing is written. Turning it on is a privacy-policy change in both
languages first; `data/logs/` must also exist on the server, since `fopen` creates the file and not
its directory and `deploy.sh` excludes it.

## The API and deploying

`/admin/{service}/{version}/{action}` is the one address family that writes, and each depth above it
lists what is under it. Every call is signed with an ECDSA P-256 key the server cannot use — or comes
from a browser that unlocked with a passkey that key enrolled, and taps again for every write; a
caller the admin cannot verify sees its entrance and nothing else, so a stranger learns that there is
an admin and nothing about what is in it. `/api` is gone, with no alias — it answers like
`/no-such-page`. See [docs/security.md](docs/security.md#the-admin) and
[docs/deployment.md](docs/deployment.md).

```bash
npm run build:prod && php tools/push-update.php --dry-run   # validate, report, write nothing
npm run build:prod && php tools/push-update.php             # phpanta/ + src/ + autoload.php + public/
php tools/api.php update v1                                 # what the server offers there; fewer operands, less deep
php tools/api.php update v1 version                         # what is deployed
php tools/api.php health v1 report                          # does the host meet the site's floor (503 if not)
php tools/api.php capability v1 extensions                  # what it has; also runtime, settings, deployment, errors
php tools/api.php update v1 probe                           # what its filesystem lets a push do (a write)
php tools/api.php access v1 enrol --code <code> --name phone  # enrol a device /admin registered
php tools/api.php access v1 passkeys                        # the enrolled devices; revoke --passkey <id>
./deploy.sh                                                 # full deploy over SFTP; ships data/
```

- **What the site needs of its host is declared in code**, so a push carries a floor with the code
  that needs it: the framework's floor in `phpanta/src/Support/RequirementInitialization.php`, and a
  requirement of this site's own in `Site::ownRequirements()` — a built-in kind, or a class
  implementing `Requirement`. See [docs/health.md](phpanta/docs/health.md).

- **The push is the regular deploy; `./deploy.sh` is the full one and the recovery path** — it owns
  `data/`, and it fixes a push that broke `src/`. Do not make the endpoint replace it.
- **`deploy.sh` excludes `data/admin.php`, `data/site_auth.php`, `data/update.pub`,
  `data/session.key` and `data/admin-passkeys.json`** — every credential the framework reads. All
  five are gitignored, exist only per deployment and hold live credentials; this site has no
  `admin.php` at all, since no route stands behind the framework's Basic admin gate. Upload or mint the keys by hand (no SSH: mint `session.key` locally and
  upload it); the device store is written on the server by `access v1 enrol`. See
  [docs/deployment.md](docs/deployment.md#6-the-admin-in-a-browser).
- **`--delete` is on for `public/`, `src/` and `phpanta/src/`, off for `data/`**, so a gitignored demo on the server
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
| [phpanta/CLAUDE.md](phpanta/CLAUDE.md) · [phpanta/docs/](phpanta/docs/architecture.md) | anything under `phpanta/` — the framework's rules, traps and documents |
| [docs/architecture.md](docs/architecture.md) | the site's PHP: its layers, `Site`, its recipes — the framework's half is [phpanta/docs/architecture.md](phpanta/docs/architecture.md) |
| [phpanta/docs/collections.md](phpanta/docs/collections.md) | `Collection` / `SearchableCollection`, or anything that holds a group |
| [phpanta/docs/guidelines.md](phpanta/docs/guidelines.md) | a bare array, a bare string, an `array_*` call, an `@`, a `throw` — or `GuidelineTest` failing |
| [docs/frontend.md](docs/frontend.md) | the site's TypeScript and CSS, its elements — the build and SPA navigation are [phpanta/docs/frontend.md](phpanta/docs/frontend.md) |
| [docs/contracts.md](docs/contracts.md) | any name or value both languages know |
| [phpanta/docs/language.md](phpanta/docs/language.md) | any visible word, the catalogs, `Request::language()`, the switch and its cookie |
| [docs/testing.md](docs/testing.md) | the suites, the invariants, coverage |
| [docs/security.md](docs/security.md) | auth, the site's gates, what is known and accepted — headers and the API are [phpanta/docs/security.md](phpanta/docs/security.md) |
| [docs/deployment.md](docs/deployment.md) | the push, `deploy.sh`, Strato, `.htaccess` |
| [phpanta/docs/health.md](phpanta/docs/health.md) | the `health` and `capability` services, or a requirement to declare |
| [docs/runtime.md](docs/runtime.md) | anything that depends on which PHP it runs under — Strato's, the local Apache's, the CLI's |
| [docs/performance.md](docs/performance.md) | anything on the hot path |
| [docs/releases.md](docs/releases.md) · [docs/authoring.md](docs/authoring.md) | a release, or the tools that stage one |
| [docs/demos.md](docs/demos.md) | a demo, or the password gate |
| [docs/tooling.md](docs/tooling.md) | anything under `tools/` |
| [docs/branding.md](docs/branding.md) | icons and profile links |
| [docs/history/](docs/history/README.md) | nothing — it is how things got this way |
