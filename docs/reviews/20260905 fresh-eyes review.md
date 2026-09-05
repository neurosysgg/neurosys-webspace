# fresh-eyes review — 20260905

Read every file with no prior context, then went looking rather than reading: ran both suites, both
linters, merged coverage, started the two dev servers, probed `Element`'s URL guard directly, and
walked the running site in a browser.

**Status: the "questions, not defects" section is answered and actioned as of 2026-09-05**, plus the
`--accent` item from *worth fixing*. Outcomes are marked inline below. The rest of *worth fixing*,
all of *accessibility* and all of *code quality / drift* are **untouched and still open** — they were
deliberately left out of that pass, not rejected.

**State on arrival:** `composer test` green — 507 PHPUnit tests / 1135 assertions, 89 verify checks.
`composer lint` clean, 0 of 122 files. Merged coverage 98.01% lines (887/905), matching what
CLAUDE.md claims. No TODO/FIXME anywhere in `src`, `assets`, `test`, `tools` or `data`. Superglobals
appear in exactly two files. One `@` suppression, on the one `fopen` that has a documented reason.

Nothing here is broken. Most of what follows is either **outside** what the tests can see (a colour,
a heading level, an Apache module) or a small inconsistency with a rule the codebase states
elsewhere. Grouped by how much it costs.

## what holds up

Worth writing down, because the rest of this is criticism and it needs calibrating against
something.

- **The URL scheme guard is real.** Probed [Element](src/NeuroSYS/View/Html/Element.php) directly
  with eleven values: `//evil.example/x`, `/\evil.example`, `/\r\n/evil.example`, `javascript:` in
  mixed case and tab-prefixed, `data:text/html`, a leading space. Ten refused, and the three that
  should pass passed. The WHATWG-parser rewrite of `staysOnThisOrigin()` does what its docblock says.
- **The mirror checks work.** Deliberately introduced no drift, but the three-way arrangement
  (`ViewTest` against the filesystem, verify against a live server, `vocabulary.test.mjs` against a
  loaded `main.js`) covers each other's blind spots, and the `.htaccess`↔`dev-router.php` pin caught
  a real divergence once already.
- **`Charset`, `MimeType`, `Header`, `HeaderName`** — every response header on the wire matched what
  the source says it should be, on the 200, the 303, the 404 and the 405.
- **CSS focus and motion.** `:focus-visible` is styled in six places, `prefers-reduced-motion`
  silences the only animation. That is more care than most sites this size take.

## worth fixing

- **`--accent` fails contrast almost everywhere it is used as a foreground.**
  [tokens.css:14](assets/css/base/tokens.css:14) is `#6a00ff`. Measured against the palette:

  | pair | ratio | verdict |
  | --- | --- | --- |
  | `--accent` on `--bg` | **2.85:1** | fails AA text (4.5) *and* non-text (3.0) |
  | `--accent` on `--surface` | **2.63:1** | fails both |
  | `--accent` on `--surface-2` | **2.44:1** | fails both |
  | `--bg` on `--accent` (hovered button) | **2.85:1** | fails both |
  | `--accent-2` on `--bg` | 5.80:1 | fine |
  | `--fg` on `--bg` | 16.04:1 | fine |
  | `--muted` on `--bg` | 5.36:1 | fine |

  The rest of the palette is well chosen; this one token is the outlier, and it is the one carrying
  the most weight. Blast radius:
  - every `<a>` on the site — [elements.css:18](assets/css/base/elements.css:18) — so the footer's
    imprint/privacy/mailto links and the 404's `← home`
  - the focus ring — [elements.css:26](assets/css/base/elements.css:26) — which WCAG 2.2 SC 1.4.11
    wants at 3:1 against *both* adjacent colours
  - `.btn-primary`, the home page's one call to action, in **both** states: text+border at
    [utilities.css:22-23](assets/css/layout/utilities.css:22), and dark-on-purple at
    [utilities.css:30-31](assets/css/layout/utilities.css:30) — so focusing it makes it *less*
    readable
  - the terminal cursor and `status: ready` — [terminal.css:63](assets/css/elements/terminal.css:63),
    [terminal.css:75](assets/css/elements/terminal.css:75)
  - `download-label::before`'s ↓ glyph, and `stats.css:31`

  Cheapest fix is one line: lighten `--accent` to ~`#9a6bff` (about 5.9:1 on `--bg`) and keep
  `#6a00ff` as a separate `--accent-deep` for the filled-button background, where `--fg` on it
  already measures 5.64:1. Note `SoundCloudWidget.ACCENT` already made exactly this judgement for the
  player — "reads as near-black against the player's own dark chrome" — for the same reason the site
  itself has.

  → **FIXED.** `--accent` is `#9a6bff` (5.51 / 5.09 / 4.72 against the three surfaces) and
  `--accent-deep` `#6a00ff` keeps the filled-button ground, where `--fg` on it is 5.64. The button's
  hover/focus now sets `background: var(--accent-deep); color: var(--fg)` instead of the 2.85:1
  pairing it had. Verified in the browser: every link and the `status: ready` value compute to
  `rgb(154, 107, 255)`. The reasoning is written into `assets/css/base/tokens.css` with the numbers.

- **`.claude/launch.json` starts the dev server without the router, and the file is tracked.**
  [launch.json](.claude/launch.json) runs `php -S localhost:8080 -t public` with no
  `tools/dev-router.php`. CLAUDE.md says in bold that the router is not optional; `basic_test.sh`
  pins that *both of its own* `php -S` invocations load it. This is the third invocation and nothing
  checks it. Proved it locally:

  ```
  no router:    /  200   /assets/css/v-76ad0616/style.css  404   /assets/js/v-76ad0616/main.js  404
  with router:  /  200   /assets/css/v-76ad0616/style.css  200   /assets/js/v-76ad0616/main.js  200
  ```

  So anyone who starts the preview from this config gets an unstyled page with no JS — exactly the
  failure the verify check exists to prevent. Add `"tools/dev-router.php"` to `runtimeArgs`, and
  consider widening the verify check from "both invocations in this script" to "every `php -S` in
  the repo".

- **`mod_deflate` is absent locally too, so the compression block has never been observed working
  anywhere.** [public/.htaccess:24](public/.htaccess:24) already records that Strato sends no
  `Content-Encoding` as of 2026-09-05. The local Apache mirror is the same: `httpd -M` lists
  `headers_module` and `rewrite_module` and neither `deflate` nor `brotli`, and
  `/assets/css/style.css` comes back as 13,433 bytes with `Accept-Encoding: gzip` — byte-for-byte the
  file on disk. So `style.css` (13.4 KB) plus the module graph (~50 KB raw) ship uncompressed on
  every cold load, and the `<IfModule>` guard means it will keep doing that in silence.

  Two things follow. First, loading `mod_deflate` on the local Apache is the cheap way to make the
  block *testable* before asking Strato about it. Second, the documents are PHP output, so
  `zlib.output_compression` is a route to compressed HTML that needs no Apache module at all — worth
  knowing as a fallback if Strato says no. It would not help the static assets, which are the larger
  half.

- **Nothing on the site has an `og:` or `twitter:` tag, and there is no favicon.** `Layout::head()`
  emits charset, viewport, title, description, the stylesheet and 41 preloads —
  [Layout.php:40-56](src/NeuroSYS/Layout.php:40). For a release site whose distribution model is
  links pasted into Discord, X and SoundCloud, a shared `/releases/ill` renders as a bare URL with no
  title card and no cover. Every fact a card needs is already typed and to hand: `pageTitle()`,
  `Release::$description`, `$release->cover?->url()`.

  The one thing to decide first is `og:image` pointing at HiDrive — most clients fetch it
  server-side when they unfurl, but a few render it in the reader's client, which would put the
  reader's IP at HiDrive without a click. That is the same transfer `data/privacy.html`'s Strato
  section already covers, so it is a paragraph to check rather than a blocker. `og:title` /
  `og:description` / `og:type` carry no such question and are worth having regardless.

  Separately: with no `/favicon.ico` and no `<link rel="icon">`, every browser's automatic favicon
  request falls through the rewrite to `index.php` and renders a **full 404 document** — layout,
  terminal, 41 preload links — to answer a request that wanted an icon.

## accessibility

The CSS half is well handled; the markup half has three gaps that no test can see.

- **Heading structure is inconsistent across the seven routes.** Checked in the live DOM:

  | route | `<h1>` | note |
  | --- | --- | --- |
  | `/` | `electronic music.` | correct |
  | `/releases` | **none** | page starts at `<h2>releases</h2>` — [ReleasesView.php:42](src/NeuroSYS/View/ReleasesView.php:42) |
  | `/releases/ill` | `ill.` | correct |
  | `/imprint` | **two** — `Impressum`, `Imprint` | [ImprintView.php:39](src/NeuroSYS/View/ImprintView.php:39), [:46](src/NeuroSYS/View/ImprintView.php:46) |
  | `/privacy` | **two** — `Datenschutzerklärung`, `Privacy Policy` | [privacy.html:1](data/privacy.html:1), [:200](data/privacy.html:200) |
  | 404 | **none** | terminal only |

  The two-`<h1>` pages are the defensible ones — two languages, two documents — but the conventional
  shape is one `<h1>` and an `<h2>` per language, or a wrapping `<section>` each.

- **`<html lang="en">` is the only `lang` attribute in any document.** Confirmed by querying
  `[lang]` on each page: one match, always `HTML=en`. So the German half of the imprint and the
  entire first 200 lines of the privacy policy are announced by a screen reader in an English voice,
  and hyphenation and browser translation treat them as English. `data/privacy.html` even uses
  `&shy;` for German compound breaking, which is the tell. Fix is a `lang="de"` / `lang="en"` on the
  wrapping element of each half — one attribute in `ImprintView`, one edit in the policy file.
  `RawHtml` passes it straight through.

- **SPA navigation drops keyboard focus and announces nothing.** Demonstrated it: focused the
  `/releases/ill` card link on `/releases`, clicked, waited.

  ```
  before  url /releases       focus  A href=/releases/ill
  after   url /releases/ill   focus  BODY            (the link is no longer connected)
  ```

  `document.title` updates correctly, and `window.scrollTo(0, 0)` runs
  ([Navigation.ts:126](assets/ts/Navigation.ts:126)) — but focus falls to `<body>`, so the next Tab
  restarts from the top of the document and a screen reader says nothing at all about having
  arrived somewhere new. This is the standard SPA gap and the standard fix: after the swap, set
  `tabindex="-1"` on `#content` and `.focus()` it, or focus the new `<h1>`. It is more worth doing
  here than on most SPAs, because the rest of the site is careful enough that the contrast is odd.

## code quality / drift

- **`Fragment::each()` is dead.** [Fragment.php:32](src/NeuroSYS/View/Html/Fragment.php:32) — zero
  callers in `src/`, `data/` or `public/`; the single reference in the tree is
  [HtmlTest.php:333](test/unit/HtmlTest.php:333), which exists to cover it. Worth noting *why* it
  has no callers: the two loops that look like its use sites cannot use it — `ReleasesView::content()`
  needs the collection's key as well as its value, and `Layout::footer()` needs a spread into
  `containing()` rather than a `Fragment`. So this is not "nobody remembered"; it is a method whose
  shape does not fit either caller. Delete it, or give it the key.

- **`JetBrains\PhpStorm\NoReturn` is a phantom dependency in production code.**
  [RedirectResponse.php:7](src/NeuroSYS/Http/RedirectResponse.php:7) and
  [PlainTextResponse.php:7](src/NeuroSYS/Http/PlainTextResponse.php:7) import it; `composer.json`
  declares neither it nor anything that pulls it in, and `vendor/jetbrains/` does not exist. Harmless
  at runtime — PHP resolves an attribute class only when something calls `newInstance()` on it, and
  `NoDiscardTest` filters by name without instantiating, which I checked. But it is redundant twice
  over: both methods already declare native `: never`, which every analyser understands, and
  `Auth::challenge()` uses `: never` with no attribute. Three sibling never-returning methods, two
  spellings. Drop the attribute and the `use`.

- **`SecurityHeaders::strictTransportSecurity()` is public and nothing outside the class calls it.**
  [SecurityHeaders.php:86](src/NeuroSYS/Http/SecurityHeaders.php:86). Its two siblings
  `referrerPolicy()` and `permissionsPolicy()` are private. `contentSecurityPolicy()` is public and
  earns it — `SecurityTest:263` reaches for `->hosts()`. But the HSTS tests go through `headers()`
  (`SecurityPolicyTest:80`), so this one is public for nothing.

- **`.csscheck/` is the one verify-script scratch dir not gitignored.** `.gitignore` names
  [`/.tscheck/`](.gitignore:29) and [`/.assetcheck/`](.gitignore:32); the CSS drift check creates
  `.csscheck/` at [basic_test.sh:284](test/basic_test.sh:284). It is `rm -rf`'d either side, so it
  only survives an interrupted run — but then it is untracked *and* unignored, which is the one
  combination that can get committed by accident.

- **`SoundCloudEmbed` validates `trackId` and `permalink` but not `secretToken`.**
  [SoundCloudEmbed.php:113](src/NeuroSYS/Model/Embed/SoundCloudEmbed.php:113). Every other free-text
  field on this side of the site fails at data-load: `HiDriveLink` pins nine alphanumerics, `Profile`
  pins an absolute `https://`, `MimeType` pins a subtype, and each says so in the same voice. The
  token is documented as `'s-…'` and checked by nothing, so a truncated paste becomes a player that
  loads to an error rather than a `ReleaseVerificationException` naming the value. Not a security
  issue — `URLSearchParams` encodes it, and the permalink is always prefixed with the profile URL, so
  neither can escape its context. Just the one field that does not follow the rule.

- **Two small doc drifts.** CLAUDE.md:73 says `assets/ts/` is "forty small files"; it is 42.
  `.gitignore:10` says "Deploy script contains credentials", and `deploy.sh:5-6` now says the
  opposite in bold — the password came out in the 20260904 pass, and the reason for the ignore went
  with it.

## questions, not defects

Things that read as decisions someone made, where I could not tell from the outside whether the
consequence was intended.

- **Static assets bypass both auth gates.** `.htaccess:122-123` passes real files through before the
  rewrite, so `Auth::requireSiteAuth()` never sees a request for `/assets/**`. That means the
  pre-launch gate covers documents only — confirmed against the local Apache: `/assets/js/main.js`,
  `/assets/css/style.css` and `/assets/js/main.js.map` all return 200 with no credentials. And
  because `tsconfig.json` sets `inlineSources`, the maps carry the **full commented TypeScript**:
  `/assets/js/Navigation.js.map` has 6,314 bytes of `sourcesContent` starting at `import { ElementId }`.

  `SecurityHeaders`' own docblock states this fact for headers ("served straight by Apache and never
  reach PHP"). The auth half is not written down, and both CLAUDE.md:140 and docs/security.md:61 say
  the pre-launch gate "runs on **every** request", which is the stronger claim. Also worth correcting
  the note at [tsconfig.json:46](tsconfig.json:46): it says maps let DevTools show the source
  "without `assets/ts/` having to be served" — `inlineSources` does serve it, just under a different
  path. If the source is public anyway (it is a GitHub handle in `data/profiles.php`), this is a
  documentation fix. If it is not, it is a `Require` on `/assets/js/*.map` while the gate is up.

  A: source is indeed public ^^.

  → **DONE — documentation, since the source is public.** `tsconfig.json`'s note now says the maps
  *are* served and that `inlineSources` is what to drop on a project where the source is not public.
  `CLAUDE.md` and `docs/security.md` now say the pre-launch gate runs on "every request **that
  reaches PHP**", and `docs/security.md` spells out that `/assets/**` — source maps included — is
  served without either gate.

- **`Navigation.go()` has no in-flight guard.** [Navigation.ts:106](assets/ts/Navigation.ts:106).
  `history.pushState` runs before the fetch ([:88](assets/ts/Navigation.ts:88)) and nothing cancels a
  previous request, so two quick clicks race: whichever response lands last writes `#content`, and
  the address bar already says the *last-clicked* URL. Slow-then-fast leaves the URL and the content
  disagreeing with no error anywhere. An `AbortController` on the previous call, or a monotonic
  sequence number checked before the `innerHTML` line, closes it in three lines. Did not reproduce
  it — over localhost the window is too small — so this is read from the code, not observed.

A: yes, this should be fixed.

  → **FIXED.** `go()` takes a monotonic number on the way in and re-checks it after *both* awaits;
  an `AbortController` cancels the superseded request alongside. The counter is the guarantee, not
  the abort — a fetch can resolve in the microtask before an abort is observed, and
  `navigation.test.mjs` now stages exactly that gap (resolve, drain one microtask, click again).
  Three new tests, and the front end's 100% branch gate is back at 100%. Verified in a real browser:
  two rapid card clicks land on the second link with URL and title agreeing, and the first request
  shows as `ERR_ABORTED`, which is the guard working rather than a fault.

  Related and smaller: `popstate` calls `go(location.pathname)`, which drops any query or hash. The
  site emits neither today.

A: This should be noted in the relevant places, but kept as is until needed fixed.

  → **NOTED, NOT FIXED, as asked.** A comment at the `popstate` listener says what is dropped, why
  nothing here can reach it, and what the fix is (`pathname + search + hash`); `docs/frontend.md`
  carries the same sentence in its SPA section.

- **HTML responses carry no `Cache-Control`, `ETag`, `Last-Modified` or `Vary`** — checked on
  `/releases/ill` against real Apache. `StatsController`'s docblock says "every other page here is
  public and cacheable", which is true by omission rather than by declaration. On a site this static
  an explicit `public, max-age=…` on documents would both state the intent and be a real win; the
  reason not to is that a stale document against a fresh `main.js` is precisely the mirror drift the
  parity tests exist to catch, arriving by the one route no test can see. Whichever way it goes, the
  current silence is the one option that is not a decision.

  A: Agreed, we should do this. We ought to figure out an elegant way to make this work with the main.js

  → **FIXED, and the elegant part turned out to be structural.** A document already embeds every
  versioned asset URL, so a hash of the rendered body already changes when the build stamp does —
  no coupling to `AssetManifest` had to be written, the dependency was in the bytes. `ViewResponse`
  now renders first, sends `Cache-Control: no-cache`, an `ETag` over that body and
  `Vary: X-Requested-With`, and short-circuits to 304 on a matching `If-None-Match`.

  `no-cache` rather than a `max-age` because a stale document names *last* build's asset URLs, and
  `.htaccess` marked those `immutable` for a year — so any non-zero window is a window of old JS
  against new HTML. This has none. It also keeps `docs/releases.md`'s "no cache to bust, no rebuild
  needed" true, since a `data/releases.php` edit changes the body and so the validator.

  `Vary` was the find inside the find: one URL answers with two bodies depending on
  `X-Requested-With`, which was harmless only while nothing cached. The differing ETags are the
  same guarantee again for a cache that ignores `Vary`. A caller that already set `Cache-Control`
  (only `StatsController`, `no-store, private`) gets none of it and can never answer 304.

  Measured against the local Apache: `200, 8449 bytes` cold, `304, 0 bytes` revalidated.

- **`.htaccess:89` sets `HTTP_AUTHORIZATION` without the `REDIRECT_` twin.**
  [public/.htaccess:89](public/.htaccess:89). Twelve lines above it, the cache block goes to real
  trouble over exactly this — "which one Apache hands to mod_headers depends on whether the rewrite
  changed the path", so both `VERSIONED` and `REDIRECT_VERSIONED` are set. The auth rule almost
  certainly *is* fine, because its pattern is `^` and so it re-fires on the internal redirect to
  `index.php` where the version rule cannot. But `Request::fromGlobals()` reads only
  [`$_SERVER['HTTP_AUTHORIZATION']`](src/NeuroSYS/Http/Request.php:52), and I could not prove the
  passthrough locally: the admin hash is empty, so the gate 401s identically either way. A
  `?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']` costs nothing and removes the asymmetry; otherwise it
  is worth one live check next deploy, since Basic auth failing closed is silent.

  A: yup, lgtm ^^

  → **FIXED.** `Request` reads `HTTP_AUTHORIZATION` and then `REDIRECT_HTTP_AUTHORIZATION`, with the
  reasoning written where it is read. Also extracted `Request::header()`, so `X-Requested-With` and
  `If-None-Match` now derive their `$_SERVER` key from the enum case through one rule instead of one
  hand-written transform.

- **`Collection` and `SearchableCollection` are ~80% the same file.** Both carry `$type`, both
  implement `Countable`/`IteratorAggregate` the same way, both have the same `all()`/`count()`/
  `getIterator()`, and both throw the same `TypeError` with the same `sprintf`. The difference is one
  parameter on `with()`. A shared abstract base or a trait would remove the duplication — but it
  would also blur the one distinction the call sites rely on (int-indexed vs string-keyed), which may
  be exactly why it was not done. Noting it as a fork in the road rather than a finding.

  A: Yes, this would be great ^^

  → **FIXED, as a trait rather than a base class** — `Support/TypedItems`, the codebase's first. The
  two collections are not substitutable (list vs map, different `with()` arity), so `extends` would
  have announced a common type nothing wants while `use` announces shared plumbing, which is all it
  is. Two mechanical payoffs: `$items` stays `private` (a parent's private member would have had to
  become `protected`), and `static::class` still names the collection rather than the trait, so the
  `TypeError` message `SupportTest` asserts is unchanged. `with()`, `find()` and `all()`
  /`getIterator()` stayed behind, the last two because their return types genuinely differ.

  It also exposed a small hole: both suites' "every class under `src/`" walks checked
  `class_exists || interface_exists || enum_exists` and would have skipped a trait — the verify
  script's would have *failed* on one. Both now check `trait_exists` too.

- **`ReleaseView` builds the terminal's command by string concatenation** —
  [ReleaseView.php:59](src/NeuroSYS/View/ReleaseView.php:59),
  `'./release --track "' . $release->title . '"'`. Safe (it is a `Text` on the way out, and I
  confirmed the escaping), but a title containing a `"` renders a command line that looks wrong. The
  only place on the site where a value is pasted into a quoted string rather than passed as a field.

  A: See if you can think of something elegant for this.

  → **FIXED — a `TerminalCommand` value object that owns the quoting.** `new TerminalCommand('find',
  $this->path)` quotes a value and leaves a leading-dash flag bare. The release page's line is
  byte-identical to before (`./release --track "ill."`); the 404's changes from `find /nope` to
  `find "/nope"`, which is the improvement — that path is the one string on this site a visitor
  writes in full, and a space in it used to smear the line. Explicitly **not** a security boundary:
  `<terminal-window>` assigns the result to `textContent`, so it was never at risk of being anything
  but text, and the docblock says so out loud.

- Additional:
  I'd like to move some of the enum HTML-Attribute values (noopener|module|noreferrer etc...) into actual enums. Have a look over which other places we could do something like this.

---

## raised during the fix pass, not yet done

- **`Header` types its name but not its value.** `Header(HeaderName $name, string $value)` — the
  name is an enum, the value is a bare string, and this pass added three more of those
  (`no-cache`, the ETag, `X-Requested-With`) on top of `Allow`, `Location`, `WWW-Authenticate` and
  `no-store, private`. The natural shape is a `HeaderValue` interface with a `render()`, which
  `ContentSecurityPolicy`, `PermissionsPolicy`, `StrictTransportSecurity` and `MimeType` already
  satisfy in all but name — they each have exactly that method — plus small value types or enums
  for the handful that are still strings. That would make `Header` typed on both halves, the way
  `Element::attr()` now is after `LinkRel`/`LinkTarget`/`ScriptType`.

  Worth doing as its own pass rather than folded into this one: it touches every `new Header(…)` on
  the site, and `SecurityHeaders::headers()` returns `array<string, string>`, which would want to
  become a list of `Header` first.

  → **DONE in pass 2.** `HeaderValue` is one method, `render()`. The four that already had it gained
  one line each; `ReferrerPolicy` and `ContentTypeOptions` gained a one-line `render()`; and the six
  values that were still being assembled at the call site became types — `CacheControl` (over a
  `CacheDirective` enum), `ETag`, `Vary`, `Allow`, `BasicChallenge` and `Location`.
  `SecurityPolicyTest` pins the set in both directions and renders every one. Three things fell out
  that were not the point but are worth more than it:

  - `SecurityHeaders::send()` flattened each case to a string and parsed it back with
    `SecurityHeader::from()` one line later, purely because the value beside it was a string. Gone.
    `headers()` keeps its `array<string, string>` shape, because that is what its readers want —
    including `test/js/soundcloud-player.test.mjs`, which shells out to PHP for one of them.
  - `HttpMethod::allowed()` is retired: `Allow::readOnly()` derives the same list from the same
    predicate, so the string version was production code with no production caller.
  - **`Location` is now checked.** It was the one address the site emits that nothing looked at —
    every `href` goes through `Element`'s scheme guard and every profile URL through `Profile`, but
    the `Location:` on a download 303 went out as whatever string it was handed. It must now be an
    absolute `https://` URL, with the same `\S`/`\z` details as `Profile::URL_PATTERN`.

## PhpStorm, pass 2

Two systemic sources, both fixed:

- **The four exceptions extended `Exception`, which PhpStorm treats as *checked*.** Its
  unhandled-exception inspection then flags every call site that neither catches nor redeclares —
  65 `containing()` calls alone, plus every `Release`, `HiDriveLink`, `Profile`, `SoundCloudEmbed`
  and `Terminal` construction. They now extend `LogicException`, which is in PhpStorm's default
  unchecked list *and* is the honest classification: nothing on this site catches any of them, and
  every case is "something in this repository is written wrong" rather than a condition a caller
  recovers from. Checked first that nothing catches them — the only `catch` in the tree is a
  `Throwable` in a dev tool.
- **`JetBrains\PhpStorm\NoReturn` was an undefined class**, imported by `RedirectResponse` and
  `PlainTextResponse` from a package this project does not require and does not have. Both methods
  already declared native `never`, which says the same thing to every analyser, and
  `Auth::challenge()` had always been plain `never`. Removed.

Anything still showing in the IDE is not something this pass could see from outside it.
