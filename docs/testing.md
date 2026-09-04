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
composer lint      # phpcs + php-cs-fixer, read-only
npm test           # node --test, the elements and the enum mirrors
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
- **`autoload.php` uses the `|>` pipe operator**, which is a parse error below PHP 8.5. PHPUnit never
  touches that file (it boots from Composer), so the verify script exercises it directly and checks
  every class under `src/` actually resolves through it.
- **The 503 "not uploaded yet" branch** needs a release with a link-less format, which the live
  catalogue doesn't have. The unit test builds a synthetic one and hands it to `DownloadController`
  through its optional `ReleaseRepository` parameter — that argument exists purely as this seam.
- **Escaping** is unit-tested because it needs hostile inputs (`<script>`, `&`, quotes, multibyte)
  that the real catalogue will never contain.
- **The front end is compiled**, so PHPUnit never sees `assets/ts/`: `test/js/` covers what the
  elements build, and the verify script runs it. The front-end checks are skipped with a printed NOTE
  when `node_modules/` is absent, so `composer test` still runs end to end on a clone that has only
  ever seen `composer install`.
- **The embed's markup is built client-side**, so the cases that asserted on it moved with it. What
  stays in `EmbedTest` is the contract the server still has — the element and its attributes; what
  moved to `test/js/soundcloud-player.test.mjs` is the widget URL, the attribution and the
  SoundCloud-hosts-only rule.

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
- **A collection inside a `readonly` object cannot be appended to.** `readonly` protects the
  reference, not what it points at, so `Collection::with()` returning a copy is what actually makes
  `Release::$formats`, `Terminal::$fields` and `SoundCloudEmbed::$options` immutable. `SupportTest`
  builds a `Release`, calls `with()` on its formats and asserts the release still has one.
- **Download logging stays off.** `ServiceTest` asserts `DownloadLogger::ENABLED === false` and that
  the referrer is never read. It's a privacy-policy decision before a code one — see `CLAUDE.md`.
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
- **A tag outside the element it belongs inside says so.** `NestedElement` is what the otherwise
  behaviourless classes do; `terminal-window.test.mjs` asserts both that it fires and that it looks
  through the card anchors rather than only at the direct parent.
- **No markup is built from a string.** Every page is a tree of `View\Html` nodes, and the verify
  script fails on a heredoc or a `'<tag'` literal anywhere under `src/` outside `Element` and
  `Doctype` — the two files whose job is turning a tree into text. Proved by putting `<b>` in a
  view's text and watching it fail.
- **`RawHtml` is constructed in exactly one place.** It is the one node that does not escape, so its
  call sites are pinned rather than trusted: `HtmlTest` scans `src/` and asserts the list is
  `['PrivacyView.php']`. A second one has to be argued for by editing that assertion.
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
- **The committed JS is current with `assets/ts/`.** `deploy.sh` rsyncs `public/` straight from the
  working tree, so editing a `.ts` and forgetting `npm run build` would deploy the previous JS in
  silence. The check rebuilds into a scratch `outDir` and diffs. That scratch directory has to sit
  exactly three levels below the repo root, like `public/assets/js/` does, or every source map's
  `sources` path differs and the diff fails for a reason that has nothing to do with staleness.
- **`assets/ts/` type-checks.** `tsc --noEmit`, with the same config the build uses — `strict`,
  `noUncheckedIndexedAccess`, `exactOptionalPropertyTypes`. This is the front end's equivalent of the
  typed value objects on the PHP side: the point is that a `data-` attribute rename becomes a compile
  error instead of the literal text `undefined` appearing on the page.

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
