# Testing

Three suites that deliberately don't overlap. None catches everything; together they cover
the parts the others structurally can't.

```bash
composer test
```

runs both — unit tests first, then the verify script. Or separately:

```bash
composer unit      # vendor/bin/phpunit
composer verify    # bash test/basic_test.sh
composer coverage  # both suites, merged into one report
composer lint      # phpcs + php-cs-fixer, read-only
npm test           # node --test, the elements and the enum mirrors
npm run coverage   # node --test with coverage, held at 100%
npm run check      # tsc --noEmit, the front end on its own
```

## The split

| | `test/unit/` (PHPUnit) | `test/js/` (node --test) | `test/basic_test.sh` (verify) |
|---|---|---|---|
| **Runs against** | Composer's autoloader | the compiled JS in `public/assets/js/`, in a jsdom DOM | the real `autoload.php`, over real HTTP |
| **Good at** | branches, edge cases, escaping, error paths | what the elements build, and the enum mirrors | integration, `exit`-ing code, the deployed shape |
| **Blind to** | anything that calls `exit`, `header()`, or needs a server | real layout, real CSS, real network | anything with no observable output |

`test/js/` runs the *built* files rather than the TypeScript, so a stale build fails there as well as
in the drift check. It loads them through `main.js`, the same entry point the browser uses, so the
elements under test are registered the same way and by the same list. Two of its cases reach back into PHP through `php -r` — the enum parity ones, and
the Permissions-Policy check — because the fact they guard spans both sides.

The division matters in a few concrete places:

- **`Auth::requireSiteAuth()` / `requireAdminAuth()` call `exit`.** A unit test can't observe that
  without process isolation, so the verify script asserts `/admin/stats` really returns 401 over HTTP.
  The *decision* is a different matter and is unit-tested: `Auth::accepts()` is public and returns a
  bool, so `AdminTest` can ask it about a wrong password without the answer ending the process. That
  split is the same one `SecurityHeaders` makes between `headers()` and `send()`, for the same reason.
- **The demo gate is the same split, twice over.** `Auth::requireDemoAuth()` calls `exit`, so the
  `401` is the verify script's — and so is everything about the audio route, because `header()` is a
  no-op under CLI and that route's whole answer is a status code and four headers. The decision half
  is `Auth::admits()`, which `DemoTest` asks about a wrong password without the answer ending the
  process. What only real HTTP can show is worth listing, because it is most of the feature: the
  `401`, that an **unknown slug is refused identically to a known one**, the realm naming the demo,
  the `206` with its `Content-Range`, the `416`, and the absence of an `ETag`.
- **`data/demos.php` is gitignored, so no test may assume one exists.** `DemoTest` writes its own and
  hands it to `DemoRepository` through the optional `File` parameter; the verify script swaps in a
  fixture of its own and restores whatever was there **in the EXIT trap**, so an interrupt cannot
  leave the real file swapped out. Both are the seam `DownloadController`'s optional repository is,
  for a stronger reason: the real file holds hashes whose passwords are by design not recoverable, so
  there would be no way to log in to it.
- **`autoload.php` uses the `|>` pipe operator**, which is a parse error below PHP 8.5. PHPUnit never
  touches that file (it boots from Composer), so the verify script exercises it directly and checks
  every class under `src/` actually resolves through it.
- **Two more 8.5 features are asked for by name**, in the same Environment block and for opposite
  reasons. `ext/uri` is what `Element` and `Request` parse URLs with; it is bundled with 8.5 but a
  host can build without it, and its absence is a fatal on every request. `#[\NoDiscard]` fails the
  other way — an attribute a runtime does not know is *ignored*, so the guarantee that a builder's
  result gets used would be quietly gone with every test still green. A loud check for each beats
  finding out either way in production.
- **The 503 "not uploaded yet" branch** needs a release with a link-less format, which the live
  catalogue doesn't have. The unit test builds a synthetic one and hands it to `DownloadController`
  through its optional `ReleaseRepository` parameter — that argument exists purely as this seam.
- **Escaping** is unit-tested because it needs hostile inputs (`<script>`, `&`, quotes, multibyte)
  that the real catalogue will never contain.
- **The SoundCloud client has never made a request**, and the tests are why that is fine rather than
  a gap. `Http\Transport` is an interface and `Client` takes one, so `SoundCloudTest` answers every
  request from an array and asserts what went out. No app is registered yet; when one is, the same
  tests still describe the contract. `ReleaseTrackTest` stops where `ReleaseFolderTest` stops — the
  command's uploading branch needs a folder whose audio `metaflac` and `ffprobe` can read, so what
  is covered there is every path that *refuses*.
- **The front end is compiled**, so PHPUnit never sees `assets/ts/`: `test/js/` covers what the
  elements build, and the verify script runs it. The front-end checks are skipped with a printed NOTE
  when `node_modules/` is absent, so `composer test` still runs end to end on a clone that has only
  ever seen `composer install`.
- **The embed's markup is built client-side**, so the cases that asserted on it moved with it. What
  stays in `EmbedTest` is the contract the server still has — the element and its attributes; what
  moved to `test/js/soundcloud-player.test.mjs` is the widget URL, the attribution and the
  SoundCloud-hosts-only rule. `test/js/soundcloud-profile.test.mjs` is the same split for the home
  page's profile player, and asserts only what differs: the resource the widget resolves, and the
  attribution crediting the artist once with no dangling separator after it. Everything the two
  share is `SoundCloudWidget`'s and is pinned in the player's file, not twice.

## Adding a unit test

Drop a `*Test.php` into `test/unit/`, namespace `NeuroSYS\Test\Unit`. `NEUROSYS_ROOT` is defined by
`test/bootstrap.php` if you need the real data files.

Files are grouped by layer, not one-per-class: `ModelTest`, `EmbedTest`, `HtmlTest`, `ViewTest`,
`PageTest`, `ServiceTest`, `SupportTest`, `ResponseTest`, `RoutingTest`, `RequestTest`, `ConfigTest`,
`SecurityTest`, `SecurityPolicyTest`, `AdminTest`, `NoDiscardTest`.

Three of those are named for something other than a layer, because that is what they are about:
`PageTest` covers the pages that are only content — the home hero, the imprint, the privacy policy —
`AdminTest` covers the gate and the log it protects, and `NoDiscardTest` is named for a fact that
spans four namespaces at once and belongs to none of them.

## Adding a front-end test

Drop a `*.test.mjs` into `test/js/` and import `./dom.mjs` first — it builds the JSDOM, installs the
globals and loads the real `main.js`, so every element is registered by the same list the browser
uses. Import a module directly only for what `main.js` does not expose: `Navigation`, and the
mirrored enums.

`dom.mjs`'s document is `<main id="content">`, which is not decoration. Without it
`Navigation.forDocument()` returns null and `main.js`'s `?.start()` wires nothing, so a DOM missing
it silently tests the SPA switched off — the one state no real page is ever in. It also installs
`Element`, `HTMLAnchorElement`, `history` and `location`, which Node does not define and Navigation
needs the moment a link is clicked.

The files are `soundcloud-player`, `terminal-window`, `cover-art`, `nesting`, `navigation`,
`vocabulary` and `enum-parity`.

## Fixtures on disk

Several suites need real files: a credentials file for the gates, a data file for a repository, a
folder that reads as a release. They all build them the same way now —
`Directory::temporary('neurosys-admin-')` in `setUp()`, `->file('x.php')->write(…)` for each fixture,
`->remove()` in `tearDown()` — rather than each opening with its own three lines of `sys_get_temp_dir()`,
`mkdir()`, `glob()`, `unlink()`, `rmdir()`.

The directory is random per call, so two suites running at once cannot collide, and prefixed, so
anything left behind by a test that died says which suite left it. `remove()` refuses to recurse: a
fixture is one level deep, and a recursive delete is not something to have lying around where
somebody could reach for it with a path they had not checked.

## Invariants worth keeping green

A few tests exist to stop a specific mistake coming back, not to cover a line:

- **Nothing loads from a third-party host on page load.** `ViewTest` asserts every `src` and every
  stylesheet `href` in the layout is same-origin, and that no `<iframe>` reaches a release page before
  the consent gate is clicked. Breaking either makes us a joint controller for the transfer
  (CJEU C-40/17) — see [branding.md](branding.md).
- **A collection inside a `readonly` object cannot be appended to.** `readonly` protects the
  reference, not what it points at, so `Collection::with()` returning a copy is what actually makes
  `Release::$formats`, `Terminal::$fields` and `SoundCloudEmbed::$options` immutable. `SupportTest`
  builds a `Release`, calls `with()` on its formats and asserts the release still has one.
- **A copy-returning builder's result cannot be dropped.** The half of that guarantee the compiler
  can hold: `with()`, `allow()`, `attr()` and `containing()` carry `#[\NoDiscard]`, `failOnWarning`
  turns the resulting `E_WARNING` into a failing test, and `NoDiscardTest` pins which methods carry
  it in both directions — plus `Auth::accepts()`, the one that is not a builder and the one where a
  dropped result is a gate that never ran. The deliberate discards are the tests that prove a
  builder did *not* mutate its receiver, and each is spelled `(void)`.
- **An unknown demo is indistinguishable from a wrong password.** A `404` for a slug that names
  nothing and a `401` for one that names something is a catalogue of unreleased tracks, readable one
  guess at a time. The verify script asserts both answer `401`; `DemoTest` asserts the reason it is
  also true of the *timing* — `Auth::requireDemoAuth()` verifies against
  `PasswordHash::unmatchable()` before refusing, so the unknown case pays the same bcrypt the known
  one does. A uniform status code undone by a stopwatch is not uniform.
- **No URL segment can name a file.** A demo's audio is addressed by a label the demo declares, never
  by a file name, so a traversal is a label matching nothing and a `404` like any other. Both suites
  check it — `DemoTest` through the controller with encoded dots, the verify script over HTTP — and
  `DemoTrack` refusing a file name with a separator in it is the second guard, for a typo in
  `data/demos.php` rather than for anything a visitor can send.
- **A path-shaped URL really is a path on this site.** `HtmlTest` walks every spelling of a bare
  authority past `Element` — `//host`, `/\host`, and the two that hide one behind a tab or a
  newline, which the enumerated prefix list this replaced let through. `Element` puts the question
  to PHP 8.5's WHATWG parser now, so the answer covers spellings nobody wrote down.
- **Download logging stays off.** `ServiceTest` asserts `Config::DOWNLOAD_LOGGING === false` and that
  the referrer is never read. It's a privacy-policy decision before a code one — see `CLAUDE.md`.
- **A wrong admin password is refused.** This one had never run. `data/admin.php` ships with an empty
  `pass_hash`, so `Auth::accepts()` short-circuits on its first operand and neither `hash_equals()`
  nor `password_verify()` is reached — which means the verify script's two `/admin/stats → 401`
  checks prove the route is gated without ever comparing a credential. `AdminTest` supplies a real
  bcrypt hash (cost 4, so the suite stays fast) and walks a dozen near-misses past it: wrong case,
  a prefix of the right password, the right password with a character appended.
- **An unconfigured gate is closed, not open.** An empty `pass_hash` accepts nobody, including
  somebody sending an empty password. `password_verify()` against an empty hash is false anyway, so
  the explicit guard is documentation rather than behaviour — and the test asserts the behaviour, so
  it holds whichever of the two is doing the work.
- **A truncated log line costs that line and nothing else.** The downloads log is append-only and a
  crash can cut it mid-write, so `DownloadStats::fromLines()` skips what
  `DownloadLogEntry::fromJson()` rejects rather than failing the page that reads it — the only place
  anyone would find out.
- **The imprint states one address, four times.** It is a legal document, and one built from four
  copies of an address is one with a wrong address eventually. `PageTest` asserts the four rendered
  blocks are byte-identical, not merely present.
- **Every asset path resolves to a file, and every third-party origin is one the CSP accepts.**
  A mistyped `Config::STYLESHEET` is a 404 that renders an unstyled page with nothing in a log; an
  origin carrying a path is a CSP directive the browser drops while the URLs built from it stay
  valid. `ConfigTest` walks both sets — the asset paths against `public/`, the origins through
  `CspHost`, which is the same class the policy validates them with.
- **Download cards carry `data-no-spa`.** Without it `Navigation` fetches the 303 and swallows it, and
  downloads silently stop working while every page still looks fine.
- **The set of custom elements is closed.** An element the browser has never heard of renders as an
  inert inline box with no error, so a misspelled tag is invisible. `ViewTest` pins the tag set the
  views emit; the verify script checks the other direction, that everything `assets/ts/elements/`
  registers appears in the served markup. The two together catch a rename on either side.
- **The consent gate reserves the player's height.** `Embed::height()` feeds `--player-height`, so the
  placeholder and the real iframe are the same size and the page doesn't jump.
- **No view emits an inline style or event handler, and `style-src` has no `'unsafe-inline'`.** The
  allowance existed only for SoundCloud's attribution markup; that block is built through the CSSOM
  now, so it went away. Two assertions keep it away — one on the policy, one on the views.
- **Every custom tag served is registered.** Checked in that direction, not the reverse: the
  terminal's own tags are registered but built by `<terminal-window>`, so no view emits one and
  asking for them in the markup would fail for the wrong reason.
- **Every tag the sources register is one `main.ts` imports.** The other half of the same invariant,
  and the half the verify script structurally cannot see: registration is a side effect of importing
  the module, and a tag an element builds itself — `<terminal-cursor>` — never appears in a response.
  `vocabulary.test.mjs` reads the tags out of `assets/ts/elements/` and asserts each one is
  registered once the real `main.js` has loaded. It also pins the file layout: one
  `customElements.define` per module, and a module named for the class it exports.
- **A tag outside the element it belongs inside says so — every one of them.** `NestedElement` is
  what the otherwise behaviourless classes do, so `nesting.test.mjs` checks it for all eleven rather
  than the two that happened to have a test. The pairings are not restated there: each tag is asked
  where it belongs by being put somewhere it is not, and the answer is read off the error message,
  so adding a nested element covers it automatically. It also asserts the guard looks through the
  card anchors rather than only at the direct parent, and that the message names tags rather than
  class names on both sides.
- **A download link is left to the browser.** The `data-no-spa` half of the same invariant
  `ViewTest` asserts about the markup: without it `Navigation` fetches the download route, gets the
  303 and swallows it, and downloads stop working while every page still looks right.
- **A SPA fetch asks for a fragment.** `X-Requested-With` is the entire signal, and drift on either
  side means the server answers with a whole document that `Navigation` then writes into `<main>`.
  `navigation.test.mjs` reads the header off the real fetch; the parity test keeps the two names
  equal.
- **A navigation that fails is handed back to the browser.** `pushState` has already run by the time
  a response arrives, so a 404 or a dead connection would otherwise strand the visitor on a URL
  they never got. Both paths end in `location.assign`.
- **The SPA switches itself off rather than throwing.** No `#content` means `forDocument()` returns
  null, nothing is registered, and every link stays the plain href it always was.
- **The cover falls back without an inline handler.** `onerror=` would need `'unsafe-inline'` in
  `script-src`, which is the one allowance the policy is careful not to carry. The listener is
  attached before `src` is assigned, so a response that fails immediately cannot beat it, and
  `once: true` means a placeholder that is itself missing fails quietly instead of looping.
- **No markup is built from a string.** Every page is a tree of `View\Html` nodes, and the verify
  script fails on a heredoc or a `'<tag'` literal anywhere under `src/` outside `Element` and
  `Doctype` — the two files whose job is turning a tree into text. Proved by putting `<b>` in a
  view's text and watching it fail.
- **`RawHtml` is constructed in exactly one place.** It is the one node that does not escape, so its
  call sites are pinned rather than trusted: `HtmlTest` scans `src/` and asserts the list is
  `['PrivacyView.php']`. A second one has to be argued for by editing that assertion.
- **Nothing under `src/` makes an outbound request.** `index.php` answers requests and never issues
  one, which is what lets the privacy policy claim no server-side call reaches a third party. The
  verify script greps `src/` for `curl_*`, `fsockopen` and `stream_socket_client`. Proved by
  dropping an `fsockopen` into `src/` and watching it fail.
- **curl is called in one place under `tools/lib/`.** The SoundCloud upload is the only outbound
  request this repo makes, and `Http\CurlTransport` is the only class that makes it — the same
  arrangement `Release\Probe` has for shelling out. Options set once cannot disagree between call
  sites, and two of them matter: certificates are verified, and redirects are not followed with a
  credential and a 50 MB body attached.
- **Every multipart field an upload sends is a `TrackField` case.** An API drops a field it does not
  recognise rather than refusing the request over one, so `track[titel]` uploads the file, answers
  201 and leaves an untitled track. `SoundCloudTest` asserts the whole field map, keys included,
  against what `TrackUpload` builds — and the OAuth parameter names beside it stay literals on
  purpose, because misspelling one of those is refused in words before anything is sent.
- **A rotated refresh token reaches disk before the next request goes out.** SoundCloud's refresh
  tokens are single-use: the response carrying the next one has already voided the one that was
  spent, so losing it costs a browser round trip to recover from. The test asserts the *order* — the
  store holds the new token, and the upload that follows carries the new access token.
- **A void element refuses children.** `<img>text</img>` is not markup a browser fixes, it is markup
  it reinterprets, so `Element::containing()` throws rather than emitting it.
- **No attribute value reaches the markup unescaped.** `Element` escapes once, in one place, rather
  than a `htmlspecialchars()` call per attribute at every call site — forgetting one of those is an
  injection. `ViewTest` asserts a value carrying `" onload="` comes back fully escaped, and that a
  boolean attribute and an empty value stay distinguishable (`narrow` vs `options=""`).
- **The tag and attribute names match their PHP originals.**  The same parity test as the value
  enums, but they fail differently: a wrong value usually shows, a wrong name shows as nothing —
  `getAttribute` returns null, or the browser lays out an inert inline box. `tone` and `loaded` are
  the two with no PHP side, read only by the stylesheet, and no test can follow them.
- **The origins the client uses are the origins the CSP allows.** `Config::PLAYER_HOST` is both the
  widget URL `SoundCloudPlayer.ts` builds and the whole of the CSP's `frame-src`; the parity test
  compares the two sides. Before `Config` they were separate literals in separate languages, and a
  drift would have shown up only as a blocked frame in the console.
- **Every class name is styled, and every styled class is named.** `HtmlTest` parses `style.css`
  with comments stripped and compares its class selectors against `CssClass::cases()`. Both
  directions fail, and differently: a case the stylesheet never mentions is an element styled by
  nothing, and a selector no case names is dead CSS. This is the only mirror in the codebase whose
  actual reader can be tested.
- **Every tag is styled, and every styled tag is a `Tag` case.** The same check for the custom
  elements, and until `assets/css/` existed there was none: a tag name in the stylesheet was a bare
  string with nothing on the other end of it, so renaming a case left the CSS quietly not matching.
  Unlike a misspelled tag in markup — which at least renders visibly wrong — an unstyled element on
  a dark page reads as a layout bug rather than a typo, and reaches no console.
- **Every tag is styled by exactly one part.** `assets/css/elements/` mirrors `assets/ts/elements/`
  at the component level, so "where is `<terminal-key>` styled?" has one mechanical answer. Two parts
  naming a tag is the failure worth naming: whichever `main.css` imports later wins, silently, and
  the loser reads as a rule that simply does not apply. A part named for a component may only style
  tags whose modules live in it, so a rule cannot wander into the wrong file.
- **`card.css` is the one part named for a concept.** The same idiom as `RawHtml`: the catalogue
  entry and the download entry genuinely share a look across two component directories, so the
  exception is pinned rather than trusted, and a second one has to be argued for by editing the
  assertion. Proved by adding a stray part and watching it fail.
- **The committed stylesheet is current with `assets/css/`.** The CSS half of the JS drift check
  below, and for the same reason — `deploy.sh` rsyncs `public/`, so a part edited without a rebuild
  would ship a stale stylesheet nothing else notices. `tools/build-css.mjs` has no dependencies, so
  unlike the TypeScript checks this one runs on a clone that has never seen `npm install`. The build
  itself refuses a part imported twice, an import that does not resolve, an absolute import, and a
  rule sitting in a manifest.
- **The asset manifest is current with the built assets.** `src/NeuroSYS/AssetManifest.php` is walked
  out of the compiled JS and read by `Layout` for the stylesheet href, the script src and the whole
  `<link rel="modulepreload">` list. Stale, it is the quiet kind of wrong — it names a build stamp
  that is no longer what sits at those URLs, so a visitor is handed a year-immutable copy of the
  wrong thing, or every hint misses and each module is fetched twice. Like the stylesheet check this
  reads only committed files, so it runs on a clone that has never seen `npm install`.
- **Every preloaded module resolves.** The drift check proves the manifest matches the graph; it
  cannot prove it points at anything, because the href is a graph path under a URL base written by
  hand in `tools/build-assets.mjs`. So the verify script asks the dev server for all 41, and
  `ViewTest` asks the filesystem the same question — which fails in the fast suite, without a server.
  A preload that 404s is the quietest failure here: the module is simply fetched late, the slow way,
  and the console offers at most an unused-preload notice.
- **The entry point is not also preloaded.** `main.js` is the `<script src>` already in flight, so
  hinting it too is a second instruction to fetch a file the browser is on its way to fetching.
  Asserted in both suites, on the generated list and on the served page.
- **Every asset URL carries the build stamp, and they all carry the same one.** The stamp is what
  makes a year of `immutable` safe; an unversioned URL in that list would be cached for a year under
  a name that can later mean something else. Two stamps at once would mean two versions of one graph
  being served — and since a relative specifier inherits the segment it was loaded from, the modules
  under the older one would go on importing each other.
- **`.htaccess` and the dev router strip the same version segment.** One rule in two languages, with
  nothing but this check between them: drift, and the dev server 404s a URL that works live. A
  second check asserts *both* `php -S` invocations in the verify script load the router — added
  after they diverged, when `composer test` was green and `composer coverage` was not.
- **The mirrored enums match their PHP originals.** `assets/ts/model/` is a second copy of facts from
  `src/NeuroSYS/Model/`, compared case by case and in declaration order — the order is the order the
  widget query string is built in, so a reorder is a real bug and fails like one.
- **The server emits no SoundCloud address.** `<soundcloud-player>` builds the widget URL, so there is
  nothing in the served page for a browser to preconnect or prefetch before the visitor consents.
  Asserted on `toElement()` in PHP and against `w.soundcloud.com` over HTTP in the verify script.
- **The CSP names no host but HiDrive and SoundCloud.** A CDN sneaking into a future edit fails the test
  rather than shipping — asserted against `ContentSecurityPolicy::hosts()`, so it sees the typed hosts
  rather than grepping the rendered header.
- **The Permissions-Policy denies nothing the player asks for.** It is built with `denyAll()`, so adding a
  case to `PermissionsPolicyFeature` denies that feature everywhere — including inside the SoundCloud
  iframe, which requests `autoplay; encrypted-media`. The test reads the iframe's own `allow` attribute and
  checks the policy against it, so the two can't drift apart silently.
- **The `Allow` header says what the gate does.** It is derived from `HttpMethod::isReadOnly()`
  rather than written out, and `SecurityTest` asserts both halves — that the read-only cases are
  exactly GET and HEAD, and that a refused request's header matches. Marking a method read-only used
  to mean remembering to edit a string in `Router` too.
- **Every route pattern is metacharacter-free.** `Route::matches()` interpolates the pattern straight
  into a regex without `preg_quote()`, so a `.` in a future pattern would silently become a wildcard.
- **The committed JS is current with `assets/ts/`.** `deploy.sh` builds what it ships out of
  `public/` in the working tree, so editing a `.ts` and forgetting `npm run build` would deploy the
  previous JS in silence. The check rebuilds into a scratch `outDir` and diffs. That scratch
  directory has to sit exactly three levels below the repo root, like `public/assets/js/` does, or
  every source map's `sources` path differs and the diff fails for a reason that has nothing to do
  with staleness.
- **The prod tree builds, ships no source map, and still passes.** `public/` is the debug tree;
  `build/dist/` is what deploy uploads — minified, maps deleted, its own manifest. Every failure
  here is invisible in a browser until it is live, so there are four checks: the tree builds, it
  carries no `.map` and no `sourceMappingURL`, its manifest names the same modules as the committed
  one once the stamp is normalised away, and **the whole client-side suite re-runs against the
  minified bytes**. That last is the one worth the most — `test/js/dom.mjs` takes its tree from
  `NEUROSYS_JS_DIR`, so the nesting guards, `TerminalWindow`'s subtree, both embeds and `Navigation`
  execute what the server will send. `npm test` and `npm run coverage` take the default and are
  unchanged; the 100% gate is still measured against `public/assets/js/**`, which is why the debug
  tree stays readable rather than being minified in place.
- **`assets/ts/` type-checks.** `tsc --noEmit`, with the same config the build uses — `strict`,
  `noUncheckedIndexedAccess`, `exactOptionalPropertyTypes`. This is the front end's equivalent of the
  typed value objects on the PHP side: the point is that a `data-` attribute rename becomes a compile
  error instead of the literal text `undefined` appearing on the page.

## Coverage

Two commands, because they measure two languages:

```bash
composer coverage   # PHP  — 97.84% of lines, and what is left is named below
npm run coverage    # front end — 100% of lines, branches and functions, enforced
```

### The front end

`node --test` has coverage built in. The thresholds in the `coverage` script are set to 100 across
lines, branches and functions, so this is a gate rather than a report: a new branch nothing
exercises fails the command. That is affordable here and nowhere else — `assets/ts/` is forty small
files with one job each.

It runs with `--test-coverage-include-all`, which is the front end's version of the `#[CoversClass]`
trap below: without it a module nothing imports is not reported as uncovered, it is not reported at
all. Coverage is measured against the compiled output in `public/assets/js/`, the same files the
tests load and the browser runs.

### PHP

```bash
composer coverage
```

Runs both PHP suites, merges what each measured, and writes `build/coverage/` — a text summary, a
clover XML and a browsable HTML report. Currently **98.28% of lines** (1488/1514), 97.9% of methods.

Merging is the point. PHPUnit measures `test/unit/` and nothing else, so the code that only the
verify script reaches — `Auth`'s 401, `PlainTextResponse::send()`, `RedirectResponse::send()`,
`SecurityHeaders::send()` — read as untested when they are among the most exercised paths on the
site. They are invisible to PHPUnit twice over: `header()` is a no-op under CLI, and a `send()`
ends in `exit`.

So the verify script's dev server collects its own. With `NEUROSYS_COVERAGE_DIR` set it starts
under `XDEBUG_MODE=coverage` with `tools/coverage-prepend.php` as `auto_prepend_file`, which
records line coverage and writes it out **from a shutdown function** — the whole trick, because a
shutdown function still runs when a request ends in `exit`, and every response here does. That is
one dump per request; `tools/merge-coverage.php` unions them with PHPUnit's `--coverage-php` output
and renders the combined report. `composer verify` on its own is untouched and sets nothing.

#### What is deliberately not covered

Fourteen lines, in five groups, none of which a test can reach as the repository stands:

- **`DownloadLogger::log()`'s body (7 lines)** is behind `Config::DOWNLOAD_LOGGING`, a `false`
  constant that both suites assert stays false. It is dead on purpose. Reaching it would mean making
  the switch injectable, which is exactly the guarantee that assertion exists to make — so the lines
  stay uncovered and the switch stays a constant. It used to be thirteen: the locked append moved
  into `Support\File::append()`, which is tested directly, so what is left behind the switch is the
  switch and the entry it does not build.
- **`StatsController::handle()`'s body (3 lines)** needs an admin login to succeed, and
  `data/admin.php` in the repository is a placeholder with an empty `pass_hash` — the real
  credentials are uploaded by hand and `deploy.sh` excludes the file. The counting is all in
  `DownloadStats::fromLines()`, which is fully unit-tested against a log file on disk.
- **`Auth::requireSiteAuth()`'s challenge (1 line)** is only reachable when `data/site_auth.php`
  exists, and it is gitignored precisely so the repository copy cannot switch pre-launch auth on.
  The admin gate's identical branch *is* covered, over HTTP, by the verify script.
- **`File::write()`'s chmod branch (2 lines)** fires when a file this process just created cannot
  have its mode set. `chmod()` on a file you own fails only under conditions a test would have to be
  root to arrange, and the branch exists so a credential is never left readable — the rename branch
  beside it *is* covered, by writing at a name a directory already holds.
- **`FileResponse::stream()`'s short-read break (1 line)** fires when `fread()` returns nothing on a
  handle that is not at EOF — a file truncated between the `size()` that set the `Content-Length` and
  the read that fills it. Same kind of branch as the chmod one above: it exists so a truncated file
  ends the response rather than looping, and there is no way to arrange it from a test. The guard
  beside it *is* covered, by deleting the file between constructing the response and sending it — and
  that one mattered, because opening an unreadable file warns, and by then the headers have gone out,
  so the warning would print into the audio. `@fopen` is there for the reason `File::read()`'s is.

#### Ten more, which are a gap rather than a decision

These arrived with the header-value classes in `867372f` and nothing has exercised them since, so
they are listed here to be closed rather than justified:

- **`CacheControl::of()` (3)**, **`Vary::on()` (3)** and **`Location::verify()` (4)** — guard clauses
  that throw `SecurityPolicyException` on an empty or malformed value. `HiDriveLink`'s equivalent
  throw is tested by `badShareIdProvider` in `ModelTest`, which is the shape these want, and
  `DemoTest` closed the two of the same kind that the demo work added — `ContentLength`'s negative
  length and `RobotsPolicy::of()`'s empty list — so the pattern is now written down twice.

  `CacheControl::doNotStore()` used to be listed here as *"a factory no call site uses yet"*, which
  was already wrong when it was written (`StatsController` calls it) and is now doubly so: it is what
  every demo response says.

The rest of this document's claim — that every uncovered line is deliberate — held when it was
written and does not now. Three small tests in `ResponseTest` would restore it.

### The development tooling

`tools/lib/` has nine test files and is **deliberately outside the coverage source**. The figure
above is a claim about the shipped site; folding in code whose job is to shell out to `metaflac` and
`ffprobe` would either drop the number or invite contrived tests to prop it up.

- `test/unit/CliTest.php` — the `Cli/` layer. Argument parsing is the part worth pinning, because
  the two hand-rolled parsers it replaced agreed on the one thing that was wrong: an unrecognised
  flag was dropped in silence. It also covers the case `getopt()` gets wrong, flags written after
  operands, which is exactly how `composer coverage` invokes `merge-coverage`.
- `test/unit/ReleaseFolderTest.php` — the parts of `Release/` that need no folder on disk: the
  enharmonic key parser, slug derivation, format ordering, and the shape of the emitted entry. The
  last of these `eval`s the generated block and asserts it produces a renderable `Release`, so a
  staged entry cannot be merely plausible.
- `test/unit/SoundCloudTest.php` — the upload client, against a `Transport` that answers from an
  array. No app is registered yet, so nothing has ever been sent; what is pinned is what *would* be
  — the URL, the `OAuth` scheme, every multipart field under its declared name, and the order in
  which a rotated refresh token reaches disk.
- `test/unit/ReleaseTrackTest.php` — the export port and every path `release-track` *refuses* on.
  Its uploading branch runs only on a folder that passes its preflight, which means real audio that
  `metaflac` and `ffprobe` can read; that half is exercised by running the tool.
- `test/unit/FlpTest.php` — the `.flp` reader, against projects assembled byte by byte rather than
  against a committed multi-megabyte fixture. The events worth pinning are the ones a real file
  would never show you: an event that overruns the chunk, a length prefix that runs past it, and
  one long enough to overflow into a *negative* size — which used to sail through the overrun
  check, because a negative size is always within bounds.
- `test/unit/PluginsTest.php` — the length-prefixed-string scan, which produces candidates rather
  than facts and has to keep producing exactly the ones it did.
- `test/unit/PreflightTest.php` — every finding, against fixtures on disk. This is where the stems
  zip is compared against the loose folder beside it, including the zip that opens and holds
  nothing.
- `test/unit/ProjectFileTest.php` — finding the project: loose, inside a zip, or named outright by
  `--project`, and what happens when it will not parse.
- `test/unit/StageDemoTest.php` — `stage-demo`, which is the only command here that mints a
  credential. The tests that matter are not about the entry it prints: that a password verifies
  against its own hash and nothing else does, that two hundred draws are two hundred different
  strings, that **`--check` mints none at all** (a run made only to read the report would otherwise
  leave a real password on screen that no entry matches), that the plaintext never reaches the stream
  `> entry.php` captures, and that a file `ffprobe` cannot read is refused before anything is staged.
  The generator is reached by reflection so the loops cost no bcrypt — the hashing is proved once,
  separately.

  One case there is worth knowing because the obvious check does not work: **`ffprobe` exits 0 on a
  text file named `bounce v3.flac`**, reporting the codec its extension implies and `N/A` for
  everything else. `DemoSource::isReadable()` asks for a sample rate instead, which is the thing a
  file name cannot supply.

What reads a real folder is exercised by running the tool. The verify script asserts every class
under `tools/lib/` loads, which is the one thing nothing else would catch — a namespace that
disagrees with its path fails the first time somebody runs the command, and not before.

#### A number is not a measurement

PHPUnit restricts recorded coverage to what `#[CoversClass]` names, so a class no test file declares
reads as 0% however thoroughly the suite exercises it. Before this was noticed, 53 of the 161
uncovered statements were in that state — covered, unattributed. The fix is to write the assertion
the class deserves and then declare it, never to add the attribute on its own: an attribute with no
test behind it moves the number and nothing else.

## Linting

`phpcs` (PSR-12) and `php-cs-fixer` both run clean. File headers, control structures and everything else
follow PSR-12 exactly. The only exemptions, documented in both configs, are the two sniffs that fight the
house style:

- **column-aligned parameters and call arguments** — `public string                $permalink,` in
  `SoundCloudEmbed`, `new Format(ReleaseFormat::FLAC,  new HiDriveLink(…))` in `data/releases.php`
- **one-line accessors** — `public function all(): array { return $this->items; }`

`phpcs` reports no warnings either, as of the markup tree — the last one was a 193-character line in
`Layout.php`, HTML inside a heredoc that couldn't wrap without changing the output. There is no
heredoc left to be long.

Editor note: nvim's stock `nvim-lint` phpcs resolves `vendor/bin/phpcs` and its ruleset against *Neovim's*
cwd, so opening a file from outside the project silently lints it as bare PSR-12 and flags both exemptions
above. `~/.config/nvim/lua/plugins/php.lua` overrides that to resolve from the buffer's own project root.

`vendor/` and `node_modules/` are gitignored and never deployed — `deploy.sh` only ships `public/`,
`src/`, `autoload.php` and `data/`. The "no package manager" rule in `CLAUDE.md` is about what runs on
the server, and that is still true: TypeScript compiles here, and the server receives the plain `.js`
it produced.

There is no linter for the TypeScript — `tsc` under `strict` is the whole check. Adding ESLint would
mean a second toolchain for a handful of small files.
