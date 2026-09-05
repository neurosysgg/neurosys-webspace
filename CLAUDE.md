# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Stack

Plain PHP 8.5 / HTML / CSS, no framework, **no runtime dependencies**. Three things need PHP ≥ 8.5
and each is load-bearing: the pipe operator (`|>`) in `autoload.php`, `#[\NoDiscard]` on the
copy-returning builders, and **`ext/uri`** — the WHATWG and RFC 3986 parsers `Element` and `Request`
put their URL questions to. The extension is bundled with 8.5 rather than optional, but bundled is
not the same as present, so `composer.json` names it and the verify script asks for it out loud.
Checked on the live host (Strato, PHP 8.5.9, `cgi-fcgi`) before either was relied on.

Nothing on the PHP side is built or transpiled. The front end does have a build — TypeScript
compiles, and the stylesheet is assembled from its parts — but both outputs are committed, so what
lands on the server is still plain files served statically. See [Front end](#front-end) and
[The stylesheet](#the-stylesheet).

Composer and npm are dev tooling only (PHPUnit, phpcs, php-cs-fixer; TypeScript). `vendor/` and
`node_modules/` are both gitignored and `deploy.sh` uploads neither — what runs on the server is
still plain PHP with a hand-rolled autoloader.

## Local dev

```bash
php -S localhost:8080 -t public tools/dev-router.php
```

The router is not optional. Assets are served under a build-stamp path segment
(`/assets/js/v-a1b2c3d4/main.js`) that `public/.htaccess` strips in production; the built-in server
reads no `.htaccess`, so without the router every stylesheet and module 404s locally while working
live. See [Cache versioning](#cache-versioning).

`composer install` if you want to run the tests or linters, `npm install` if you are going to touch
the TypeScript. Neither is needed just to serve the site.

## Tests

```bash
composer test      # unit tests, then the end-to-end verify script
composer unit      # PHPUnit only
composer verify    # bash test/basic_test.sh only
composer coverage  # both suites, merged into one report
composer lint      # phpcs + php-cs-fixer, read-only
```

Two suites that cover different things — `test/unit/` for logic and edge cases, `test/basic_test.sh`
for the real autoloader, real HTTP, `exit`-ing auth code and repo hygiene. See `docs/testing.md` for
the split and for the invariants that exist to stop specific mistakes recurring.

`composer coverage` merges them, because separately neither number means much: PHPUnit cannot see
`header()` (a no-op under CLI) or anything past an `exit`, so the 401, the 303 and the 405 read as
untested when they are among the most exercised paths on the site. With `NEUROSYS_COVERAGE_DIR` set,
the verify script's dev server runs under Xdebug with `tools/coverage-prepend.php` loaded and dumps
its coverage from a shutdown function — which still runs when a request ends in `exit`, and every
response here does. `tools/merge-coverage.php` unions the two into `build/coverage/`. **98.01% of
lines**; the eighteen that are left are named in `docs/testing.md` and each is deliberate.

**A gate's decision and its 401 are separate.** `Auth::accepts()` is public and returns a bool, the
same way `SecurityHeaders::headers()` is public next to `send()`, and for the same reason: a method
that ends the request cannot be asserted against, so everything worth asserting lives beside it
rather than inside it. That split is why `AdminTest` exists — before it, the credential comparison
had never executed under either suite, because the placeholder `data/admin.php` short-circuits it.

```bash
npm test           # node --test — the elements and the enum mirrors
npm run coverage   # the same, with coverage held at 100%
npm run check      # tsc --noEmit
```

`npm run coverage` is a gate rather than a report: its thresholds are 100 for lines, branches and
functions, so a branch nothing exercises fails the command. That is affordable here and nowhere
else — `assets/ts/` is forty small files with one job each. It runs with
`--test-coverage-include-all`, the front end's version of the `#[CoversClass]` trap: without it a
module nothing imports is not reported as uncovered, it is not reported at all.

The verify script runs the client-side tests too, type-checks `assets/ts/`, and asserts the committed
JS is current with it. All three are skipped with a printed NOTE when `npm install` has never been run,
so `composer test` still works on a bare clone. It also asserts the committed `style.css` is current
with `assets/css/`; that one needs only `node`, so it runs on a bare clone rather than skipping.

The client-side tests run against the **compiled output** in `public/assets/js/`, the same files the
browser loads — so a build that never ran is a failing test rather than a passing one. They use
`node --test` (built in) with `jsdom` for a DOM; both are dev-only, like everything else here.

## Architecture

MVC, all plain PHP classes — no template files, no `extract()`, no inline echo syntax, and since the
markup tree, no HTML written as a string either.

```
src/NeuroSYS/
├── Controller/     ← one class per route group; fetches its own data, returns a Response
├── Http/           ← Request, Response interface, ViewResponse, RedirectResponse, PlainTextResponse
│                     + HttpStatusCode, HttpMethod, MimeType/TopLevelType, Header/HeaderName
│                     and the two header-name enums
│   └── Security/   ← ContentSecurityPolicy, PermissionsPolicy + the enums they compose
├── Model/          ← Release, Format, Profile, MusicalKey, Genre, ReleaseFormat, Platform (typed value objects + enums)
│   ├── Embed/      ← Embed interface + SoundCloudEmbed (one track) + SoundCloudProfileEmbed
│   │                 (the whole account); each renders its element from typed params
│   └── Link/       ← FileLink interface + HiDriveLink; generates share URLs from a share id
├── Service/        ← Auth, DownloadLogger, DownloadLogEntry, ReleaseRepository, ProfileRepository
├── Support/        ← Collection<T>, SearchableCollection<T> (both immutable) + the TypedItems trait
│                     they share, Route, RouteInitialization, JsonDeserializable, Charset
├── View/           ← View abstract base + one concrete per page; each returns a Node, not a string
│   ├── Html/       ← the markup tree: Node, Element, Text, RawHtml, Fragment, Document, Doctype
│   │                 + Tag/HtmlTag, the attribute-name enums, and the attribute-value enums
│   │                   LinkRel / LinkTarget / ScriptType
│   └── Terminal/   ← Terminal, TerminalCommand, TerminalField + the enums they compose
├── Config.php      ← the facts about this site: identity, origins, paths, switches
├── Layout.php      ← static wrap(View): Document — the full HTML shell
└── Router.php      ← pure URL→Controller mapper; zero data dependencies
```

`public/index.php` is five statements: security headers → parse request → site auth check →
`Router::dispatch()` → send.

`SecurityHeaders::send()` runs before anything else, so the CSP and `Referrer-Policy` cover every
response including the 401 `Auth` exits with and the 303 a download redirects with. Every value is a
typed object — `CspDirective`, `CspKeyword`/`CspScheme`/`CspHost` behind a `CspSource` interface,
`ReferrerPolicy`, `PermissionsPolicyFeature`, `StrictTransportSecurity` — so a misspelled directive
or an unquoted `'self'` is a parse error, not a header the browser silently drops. `CspHost`
validates it got a bare origin, the way `HiDriveLink` validates a share id. The CSP allows images
only from HiDrive and frames only from SoundCloud; `script-src` is strict, and no view emits an
inline style or event handler (a test enforces that). `style-src` is strict too: it carried
`'unsafe-inline'` only for SoundCloud's attribution markup, and `<soundcloud-player>` sets those
properties through the CSSOM instead — same styling, nothing for the allowance to cover. `img-src`
went the same way: it allowed `data:` on the strength of a comment saying the cover placeholder
needed it, and the placeholder references nothing at all. **Both suites assert the absence**, because
a scheme source is what gets pasted back in by anyone debugging an image that will not load.

`SecurityHeaders::send()` also *removes* one header. PHP appends `X-Powered-By` with its exact patch
version before any of this code runs, so `expose_php` — php.ini's, not ours on shared hosting — is
only half the switch; `header_remove()` is the half we have. It is the one `ResponseHeader` case
naming a header the site does not send. Only the verify script can see it: `header()` and
`header_remove()` are both no-ops under CLI, so PHPUnit cannot tell either way.

**`Strict-Transport-Security` is the one header about the connection rather than the document**, and
`public/.htaccess` redirects `http://` to `https://` ahead of it. Both halves are needed and neither
is optional: the two auth gates are HTTP Basic, Basic is base64 rather than encryption, and the
pre-launch gate runs on *every request that reaches PHP*. A plaintext request has already put the credentials on the
wire before any redirect can be read, so the redirect fixes that request and the header stops there
being another. It is a year with `includeSubDomains`; `StrictTransportSecurity::ONE_DAY` exists for
ramping an estate you have not checked, and `preload` is deliberately not offered — see the class.

The site is read-only: `Router::dispatch()` answers anything but GET/HEAD with a 405. The `Allow`
header is built from `HttpMethod::allowed()`, which filters the cases by `isReadOnly()` — so the
header cannot claim something the gate does not do, which a hand-written `'Allow: GET, HEAD'` could.
An unrecognised method is `null` rather than a guess, and null is not read-only.

**There is no CSRF surface here, and that is a property rather than an oversight.** It rests on
three independent facts, any one of which would be enough on its own: the site sets **no cookie**
and starts **no session**, so there is no ambient credential for a cross-site request to ride;
there is **no `<form>` anywhere**, and the only state-changing verb is refused by the 405 gate
above; and the one authenticated route is HTTP Basic, where the browser sends credentials because
of the realm rather than because of the origin. So no token, no `SameSite` attribute and no
double-submit anything — there is nothing for them to protect. The CSP still carries
`form-action 'self'`, which on a site with no forms is belt over braces, and stays because the
day a form appears is not the day anyone will remember to add it.

**There is deliberately no CSP `report-uri`/`report-to`** either — a report is a POST, which the
405 gate refuses; a third-party collector is a third-party origin receiving a request from every
visitor before any consent; and a report's `document-uri`/`referrer`/`blocked-uri` is data the
privacy policy does not claim, on the same terms `DOWNLOAD_LOGGING` is off on. See the docblock on
`SecurityHeaders::contentSecurityPolicy()`, which is also where the standing-in-for-it is listed:
the policy is asserted at build time rather than observed at run time.

`Request::path()` is the one place a malformed request target is dealt with, and it is worth
knowing it does two different things. It used to be `parse_url()`, which signals failure with
`false` rather than null — so `?? '/'` read as a guard and was not one, and the `false` reached
`rtrim()` as an uncaught `TypeError`: `GET ///` was a 500, ahead of the router and ahead of the 405
gate. It is `Uri\Rfc3986\Uri::parse()` now, which returns **null** on a target it cannot read —
the thing `??` was looking for all along — so the trap is gone rather than guarded against, and
`///` comes back as the root because that parser can actually read it. A target that genuinely will
not parse comes back **verbatim**, so it matches no route and 404s — answering it with the home page
would be the quieter wrong. Same instinct as `HttpMethod::tryFrom()` returning null rather than
guessing GET.

Every header a response sends is a `Header` — a `HeaderName` case and a value, formatted in one
place instead of a `header('Name: ' . $value)` call per site. The names live in two enums on
purpose: `SecurityHeader` is exhaustive and tested as such, and `ResponseHeader` is everything else.

`Content-Type` is a `MimeType` — a `TopLevelType` case, a validated subtype and a `Charset` — and not
a string with `; charset=utf-8` stapled onto it. A class rather than an enum for the reason
`StrictTransportSecurity` is one: the value carries a parameter, and a case cannot hold one. The two
the site sends are `MimeType::html()` and `MimeType::plainText()`, so no call site types a subtype,
and a malformed one throws where it is written the way `CspHost`'s origin does. The charset is the
half that earns the class — `nosniff` stops a browser guessing the type, and nothing stops it
guessing the encoding.

**The encoding is one fact.** `Charset` sits in `Support/` because both the header and the markup
tree read it and `View/` has no other reason to know anything about HTTP. It carries two forms —
`utf-8` for the header parameter, `canonical()` for the document head and for the site's one escaping
call — because those two readers already wrote it differently, and keeping both is what left every
byte unchanged.

## Config

`Config` holds the facts about *this site* rather than about any of its code, and it is deliberately
narrow — a central bag of constants is the opposite of how everything else here is arranged, where a
fact lives with the thing it describes so its docblock can say why. A constant earns a place only by
being **identity** (name, handle, address, tagline), **environment** (data paths, reachable origins,
switches), or **already stated twice**.

That third one is what made it worth writing:

- `https://my.hidrive.com` was in `HiDriveLink` *and* in the CSP. Change one and covers keep loading
  right up until the policy blocks them.
- `https://w.soundcloud.com` was in the CSP and again in `SoundCloudPlayer.ts`, in another language.
  Drift there means the player is blocked by our own policy with nothing in the page to explain it.
- `neuro.SYS` was in eleven places; the `data/` directory was derived seven times, one of them by a
  different idiom (`__DIR__ . '/../../../data/'` rather than `dirname(__DIR__, 3)`). That is where
  the credentials live.

`assets/ts/Config.ts` mirrors the three the client reads — `NAME`, `HANDLE`, `PLAYER_HOST` — under
the same parity test as the enums. Not the rest: the data paths and the logging switch are the
server's business.

**What stayed put**, because it means nothing outside the file that owns it: `CspHost`'s origin
pattern, `HiDriveLink`'s share-id pattern, SoundCloud's accent and attribution styling,
`Navigation`'s event name. Moving those here would only make them reachable from everywhere.

## The markup tree

**Nothing builds HTML from a string.** A view returns a `Node`, a page is a tree of them, and the
only code that writes a `<` is `Element` and `Doctype`. A verify check enforces that: a heredoc or a
`'<tag'` literal anywhere under `src/` outside those two files fails the build.

| Node | Is |
|---|---|
| `Element` | a `TagName`, typed attributes, child nodes |
| `Text` | a run of text, escaped on the way out |
| `Fragment` | several nodes with no element around them |
| `Document` | a `Doctype` and the `<html>` under it |
| `RawHtml` | the one audited hole — see below |

Four mistakes stop being possible, three of them previously silent: a misspelled tag renders as an
inert inline box, a misspelled attribute is a null the client reads as nothing, an unescaped value is
an injection, and a mismatched closing tag is a document the browser reinterprets. The last one a
tree removes outright — there is no closing tag to get wrong, because there is no text form to write.

**The attribute's *value* is typed too, wherever it is a fixed vocabulary rather than data.**
`attr()` accepts any `BackedEnum` and unwraps it, so `rel`, `target` and `type` are `LinkRel`,
`LinkTarget` and `ScriptType` cases rather than strings — the same move `RequestedWith` and
`ContentTypeOptions` already made beside the headers they fill. It earns its place on the same
grounds the names did: misspell `modulepreload` and forty-one preload hints stop preloading in
silence, misspell `noopener` and a security boundary on every outbound link is quietly not there,
and drop `module` from the script tag and `import` becomes a syntax error. `rel` is a token list, so
`LinkRel::tokens(…)` builds it variadically the way `HttpMethod::allowed()` builds the `Allow`
header. These are server-only, so they have no TypeScript mirror and none is wanted.

`Element::attr()` is the whole attribute API. What you pass decides what renders: a string or int is
a value, `true` is a bare boolean attribute, and `false`/`null` leave it off. `''` and `null` are
deliberately different — `options=""` is a real empty value, `secret-token` absent is not.

`containing()` takes nodes, and a bare string becomes escaped `Text`. That is the safe reading of the
ambiguous case: markup passed as a string shows up as visible `&lt;b&gt;` — wrong on the page, but
*visibly* wrong.

**Both guarantees are enforced in `render()`, not in the builders.** `render()` is the only code that
turns a node into markup, so a rule applied there holds for any element however it was built —
including one assembled by handing the constructor an array, which `attr()` is otherwise the only
thing standing in front of. Two rules:

- **Escaping.** `Element` escapes an attribute value by rendering it as a `Text`, so
  `htmlspecialchars` is called in exactly one place on the whole site, with one set of flags stated
  rather than inherited from the runtime's defaults. `HtmlTest` pins that call site the same way it
  pins `RawHtml`'s.
- **Scheme.** An attribute the browser dereferences is asked what scheme it names, because escaping
  is the wrong tool for a URL and always was — `javascript:alert(1)` contains not one character
  `htmlspecialchars` touches. `AttributeName::isUrl()` says which attributes those are, case by case
  and not enum by enum, since `href` and `class` live in the same one. The allowlist is
  site-relative, `https:` and `mailto:`. **A leading slash is not the same claim as "somewhere on
  this site", so it is asked rather than assumed.** This used to be a two-entry list of the prefixes
  an authority can open with — `//host` and `/\host`, the same URL spelled the way that does not
  look like it — and a list of the spellings that occurred to us is the shape of mistake this class
  exists to avoid. It had missed one: the WHATWG parser strips tab, CR and LF from a URL *before*
  parsing it, so `/\r\n/host` is `//host` is `https://host`, and every "starts with a slash" test
  in the world calls it a path of ours. PHP 8.5 ships that parser, so `Element::staysOnThisOrigin()`
  resolves the value the way a browser would and asks whether it landed where it started.
  `Navigation.ts` has done it this way round on the client all along — for want of a URL parser it
  was the stronger half, and now both halves are the same check. `HtmlTest` pins every spelling,
  the two whitespace ones included, along with the marked attribute set in both directions.

`Profile::url` is checked a second time at its own constructor, the way `HiDriveLink`'s share id is.
The renderer is the backstop and reports the fault on whatever page draws the footer; the constructor
reports it when `data/profiles.php` loads, which is where the mistake actually is.

**`RawHtml` is the single hole, and it is meant to be conspicuous.** It exists for `data/privacy.html`,
a hand-authored document rather than markup a view assembles. `HtmlTest` pins its call sites, so a
second one has to be argued for in a test named for the fact. Never construct one from anything a
request can influence.

Rendering pretty-prints: an element of only elements puts each on its own line, one with any `Text`
among them stays on one line. That rule is not cosmetic — whitespace between inline content is
content, and without it `<h1>ill<span>.</span></h1>` would gain a space inside the title.

## Collections, and why they are immutable

`Collection<T>` and `SearchableCollection<T>` are the only shapes a group of objects takes. A bare
`array` with a `foreach`-and-`instanceof` check in a constructor is the thing they replace — that
loop existed three times, in `Release`, `Terminal` and `SoundCloudEmbed`, and it is now
`TypedItems::guard()`'s single `TypeError`. What is left to check by hand is the *element type*, the
one thing a PHP generic cannot say: `$this->fields->type !== TerminalField::class`.

**What they share is a trait, `Support/TypedItems`, and not a base class** — the codebase's only
trait, and the reason is worth stating. The two are not substitutable and never should be: one is a
list and one is a map, their `with()` methods take different arguments, and nothing anywhere holds
"either kind of collection". `extends` would announce a common type that nothing wants; `use`
announces shared plumbing, which is all it is. Two mechanical consequences follow: `$items` stays
`private`, because PHP flattens a trait's members into the using class where a parent's private
member would have had to become `protected`; and `static::class` still names the collection rather
than the trait, so the `TypeError` reads exactly as it did when the `sprintf` sat in both files.
`SupportTest` asserts that message, which is what would catch a later slip to `self::class`.

What stayed behind in each class is what genuinely differs — `with()`, `find()`, and `all()`
/`getIterator()`, whose bodies are identical but whose return types are `list<T>` against
`array<string, T>`. That difference is the reason there are two classes at all.

**`with()` copies; it does not append.** That is what makes a collection safe to hold inside a
`readonly` value object: `readonly` protects the reference, not what it points at, so a mutable
collection would leave every `Release`, `Terminal` and `SoundCloudEmbed` appendable by anyone
holding one. The name is deliberate too — a discarded `$c->add(…)` reads as correct, a discarded
`$c->with(…)` reads as wrong. Same shape as `ContentSecurityPolicy::allow()`.

**That naming convention was doing a compiler's job, and now the compiler does it.** `with()`,
`allow()`, `attr()` and `containing()` all carry `#[\NoDiscard]` with a sentence saying why the
dropped call did nothing, so a result that goes nowhere is an `E_WARNING` — and `phpunit.xml.dist`
sets `failOnWarning`, which makes it a failing test rather than a line in a log. `Auth::accepts()`
carries one too, and is the only member that is not a builder: it is the gate's entire decision, and
the two `require*` methods are only the challenge wrapped around it. `NoDiscardTest` pins the set in
both directions and asserts each attribute carries a message, because the default warning has none.
The deliberate discards are all in the tests — proving a builder did not mutate what it was called
on, or that a bad argument threw — and each is spelled `(void)`, which says out loud what the test
is there to demonstrate.

Not everything with a `list<…>` in its docblock wants one. `PermissionsPolicy::$denied` and
`ContentSecurityPolicy::$directives` are private, never escape, and are built only through a
variadic — `PermissionsPolicyFeature ...$features` — which PHP already enforces at the boundary. A
collection there adds indirection and no guarantee. The rule is: **a collection replaces a
hand-rolled type check on data crossing a public boundary; it does not replace a variadic.**

## How the router works

All requests hit `public/index.php` via `.htaccess` rewrite. It:

1. Builds a `Request` from `$_SERVER`
2. `Router::dispatch()` maps URL segments to a `Controller`
3. The controller fetches its own data (via `ReleaseRepository` or log file), builds a `View`, returns a `Response`
4. `Response::send()` handles headers/output — `ViewResponse` wraps in `Layout::wrap()` on full-page loads, emits a fragment on AJAX

Download routes (`/releases/{slug}/{format}`) call `DownloadLogger` and issue a 303 redirect to the HiDrive direct-download link.

**Download logging is deliberately off, for legal reasons.** `Config::DOWNLOAD_LOGGING` is `false`, and `log()` returns on it
before the `DownloadLogEntry` is built — so the referrer is never read and nothing is written. `StatsController` skips reading the
log entirely and `/admin/stats` says logging is switched off rather than showing an empty table. Both suites assert the switch
stays off, and the unit test additionally asserts the referrer is never read.

To turn it on later: flip `Config::DOWNLOAD_LOGGING` to `true`. That is a privacy-policy decision before a code one — `data/privacy.html` currently
makes no download-tracking claim, so amend it first. Note the old failure mode is still latent underneath: `fopen(..., 'ab')`
creates the log file but not its directory, and `data/logs/` is excluded from `deploy.sh`, so a freshly enabled logger writes
nothing on the server until that directory exists.

## Front end

TypeScript, compiled to browser-native ES modules. No bundler, no framework.

```
assets/ts/                    ← sources; outside public/, neither web-served nor deployed
├── main.ts                   ← entry point, the only <script> Layout.php loads; imports every element
├── Navigation.ts             ← class Navigation — SPA navigation
├── model/                    ← the mirrored enums — Platform, SoundCloudOption, …
└── elements/                 ← one class per file, named for the class, grouped like src/NeuroSYS/
    ├── NestedElement.ts      ← abstract — the parent guard every content tag inherits
    ├── CoverArt.ts
    ├── embed/                ← ConsentGatedEmbed, SoundCloudWidget,
    │                           SoundCloudPlayer, SoundCloudProfile     (cf. Model/Embed/)
    ├── terminal/             ← TerminalWindow + its five content tags   (cf. View/Terminal/)
    ├── download/             ← DownloadList, DownloadCard, …
    └── release/              ← ReleaseList, ReleaseCard, …
      ↓ npm run build
public/assets/js/             ← generated, committed, deployed
src/NeuroSYS/AssetManifest.php ← generated: the stylesheet, the entry, every module, versioned
```

**Never hand-edit `public/assets/js/`** — it is build output and the next `npm run build` overwrites it.
`npm run watch` rebuilds on save; `npm run check` type-checks without emitting. The verify script fails
if the committed output has drifted from the sources: `deploy.sh` rsyncs `public/` straight from the
working tree, so a forgotten rebuild would ship stale JS and nothing else would notice.

`npm run build` also builds the stylesheet — see [The stylesheet](#the-stylesheet) — and then the
asset manifest, below, which has to come last because it hashes both outputs. `npm run watch` does
neither; `npm run build:css` and `npm run build:assets` are each on its own.

### Preloading the module graph

An ES module graph is discovered a wave at a time, and this one is **five waves deep**: the browser
learns it needs `model/CssClass.js` only after parsing `ConsentGatedEmbed.js`, which it learned about
from `SoundCloudWidget.js`, from `SoundCloudPlayer.js`, from `main.js`. Five sequential round trips
before the last module starts downloading, and none of it is bytes — compressing and stripping
comments leave the number exactly where it was.

`tools/build-assets.mjs` walks the compiled graph and generates `src/NeuroSYS/AssetManifest.php`;
`Layout::modulePreloads()` renders one `<link rel="modulepreload">` per entry, after the stylesheet
because that one blocks rendering and these do not. The preload scanner then sees all 41 at once and
the five waves become one. Cost is 402 gzipped bytes per page.

`modulepreload` rather than `preload as="script"`: it fetches, parses, compiles *and* inserts into
the module map, so the module is instantiated by the time `main.js` asks. The list is every module
rather than the first wave — the spec lets a browser follow a preloaded module's own imports and
Chrome does, but it is not obliged to and Safari has been uneven, so leaning on it would make the
fix silently partial. `main.js` is deliberately absent: it is the `<script src>` already in flight.

Three checks, because the two failure modes are different. The verify script **rebuilds the manifest
and diffs** it (a module missing from a stale list brings its whole subtree's waterfall back), and
**asks the server for every hinted URL** (the list can be perfectly in step with the graph and still
point at nothing, since the URL base is written by hand in the tool). `ViewTest` asserts the same
existence question against the filesystem, so it fails in the fast suite without a server running.

**Neither failure is visible in a browser** — the page works, it is just slower — which is why all
three exist. It is the same instinct as the `Tag`↔CSS parity check.

### Cache versioning

Every built asset is served under a path segment naming a hash of the build:
`/assets/js/v-a1b2c3d4/main.js`. That is what lets `public/.htaccess` mark them
`immutable, max-age=31536000` — a URL that names its own content cannot come to mean something else,
so a returning visitor fetches none of it. The segment is not a directory; the server strips it,
Apache by a `RewriteRule` and the dev server by `tools/dev-router.php`.

**Why a path segment and not a filename or a query.** `Tag.a1b2c3d4.js` would break every test that
imports `public/assets/js/model/Tag.js` by name. `?v=` on each import specifier was written, worked
in a browser, and cost the front end's **100% coverage gate**: V8 attributes a module reached through
a stamped specifier to `…/CoverArt.js?v=48f0b166`, which `--test-coverage-include` does not match, so
everything the tests reach through `main.js` reported zero and the gate fell to 68%. A path segment
costs neither, because a relative specifier resolves against the URL it was loaded from — so
`/assets/js/v-a1b2c3d4/main.js` importing `./model/Tag.js` asks for
`/assets/js/v-a1b2c3d4/model/Tag.js` with **no file rewritten at all**. The compiled JS stays
byte-identical to tsc's output, which is what keeps the drift check a straight diff.

The price is one stamp per build rather than one per file, so any change busts all 42 modules. At
~12KB gzipped that is not worth a second thought, and it buys back a stated invariant.

**`.htaccess` and `dev-router.php` are a mirror** — one rule, two languages — so the verify script
pins that they strip the same pattern, and that *both* `php -S` invocations in it load the router.
That second check exists because they had diverged: `composer test` was green and `composer coverage`
was not, since only one of the two had been given the router.

**Images are deliberately not versioned.** They are vendored and hand-placed, and reached through
`Platform::icon()` and `Config::COVER_PLACEHOLDER` as plain constants — teaching a Model enum to
consult a build artefact costs more than a calendar TTL on files that change about never. The line
is: assets the build generates get a content hash, assets a person drops in keep a date.

**Why not bundle instead.** One file would fix the waterfall *and* recover ~5.7KB of per-file gzip
framing. It would also mean the element tests could no longer import individual modules, and
`assets/ts/`'s 100% coverage gate is measured against them — so it would cost the property that the
tests run against the same files the browser loads. Not worth it for 5.7KB. **Why not minify:** with
comments already stripped by `tsconfig`, a real minifier is worth about 260 gzipped bytes, because
gzip already does what identifier mangling does. Not a dependency's worth on a project with none.

Source maps sit next to the JS with the TypeScript embedded (`inlineSources`), so DevTools shows
`Navigation.ts` without `assets/ts/` having to be served. That is why `public/.htaccess` lists `map` — Strato
500s any static file it has no `SetHandler` for.

**One class per file, named for the class**, the way `src/NeuroSYS/` is — `<terminal-cursor>` is
`TerminalCursor` in `terminal/TerminalCursor.ts`, and the directory it sits in is the component, not
the file. That mirrors the PHP side twice over: the split is the same, and `elements/terminal/` and
`elements/embed/` sit opposite `View/Terminal/` and `Model/Embed/`. Nothing is a loose exported
function: it is `Navigation.onNavigate()` or a method on an element, so a call site says where it
came from.

Because a module registers its tag as a side effect of being imported, `main.ts` imports every one
of them, and that list is the whole vocabulary. `test/js/vocabulary.test.mjs` pins it — a tag the
sources register but `main.ts` never imports fails there, which matters most for the tags an element
builds itself, since those appear in no server response for the verify script to check.

`tsconfig.json` runs `strict` plus `noUncheckedIndexedAccess` and `exactOptionalPropertyTypes`, and
`module: nodenext` makes an extensionless relative import a compile error — a specifier the browser
would 404 on cannot ship. Same instinct as `CspHost` refusing anything but a bare origin.

### Custom elements

A view's output is its own vocabulary. The tags that carry behaviour are self-contained — they build
everything they show, so a view emits the tag and its attributes and nothing else:

Every tag a view may emit is registered, and so is every tag an element builds. The ones with no
behaviour of their own are `NestedElement` subclasses — CSS does their styling, the guard is what
they add — but they are declared all the same, so the vocabulary has one place to look rather than
existing only as a CSS selector.

| Module | Tag | Does |
|---|---|---|
| `NestedElement.ts` | — (abstract) | refuses to connect outside the element it belongs inside |
| `embed/ConsentGatedEmbed.ts` | — (abstract) | the gate: its wording, the reserved height, the click, the swap. Every provider's, whatever it plays |
| `embed/SoundCloudWidget.ts` | — (abstract) | SoundCloud's furniture: the widget URL, the attribution, the accent, the iframe. A subclass answers only which resource it plays |
| `embed/SoundCloudPlayer.ts` | `<soundcloud-player track-id permalink secret-token player-style options track-title height>` | one track. Every attribute but `height` is a `SoundCloudPlayerAttribute`; `height` is an `EmbedAttribute`, because the gate that reserves it is every provider's |
| `embed/SoundCloudProfile.ts` | `<soundcloud-profile player-style options height>` | the whole account's latest tracks. Carries no id, handle or title — there is no release to take them from, and the handle is `Config.HANDLE`, which this side already mirrors |
| `CoverArt.ts` | `<cover-art src fallback alt>` | builds its `<img>`, falls back to the placeholder when the file host 404s |
| `terminal/TerminalWindow.ts` | `<terminal-window label command fields [narrow]>` | builds its whole subtree from a declared `Terminal` — the command, every row, the cursor |
| `terminal/TerminalCommand.ts` | `<terminal-command>` | guard; CSS draws the `$` |
| `terminal/TerminalField.ts` | `<terminal-field tone>` | guard; `tone` decides which half the stylesheet accents |
| `terminal/TerminalKey.ts` `TerminalValue.ts` | `<terminal-key>` `<terminal-value>` | guards, inside a row |
| `terminal/TerminalCursor.ts` | `<terminal-cursor>` | guard; CSS draws the `$` and the blink |
| `download/DownloadList.ts` | `<download-list>` | nothing, deliberately — see below |
| `download/DownloadCard.ts` … | `<download-card format>` `<download-label>` `<download-meta>` | guards only |
| `release/ReleaseList.ts` | `<release-list>` | nothing, deliberately — see below |
| `release/ReleaseCard.ts` … | `<release-card slug>` `<release-title>` `<release-meta>` | guards only |

What stays native is what carries meaning or behaviour the browser provides: `<a>`, `<button>`,
`<h1>`/`<h2>`, `<img>`, `<p>`, `<section>`. The card tags wrap their anchors rather than replacing
them, so links keep working without JS, keyboard access is unchanged, and `data-no-spa` still lands on
a real `<a>`; the wrappers are `display: contents`, so the anchor is still the card to layout. That is
also why `DownloadList` and `ReleaseList` build nothing and never will — what they wrap has to be
server-rendered, so they carry a name and a guard, and no more.

**Every tag that isn't a root extends `NestedElement`**, which walks up from itself looking for an
instance of the element it belongs inside and refuses to connect if it doesn't find one. That is the
implementation those classes have instead of being empty: `<terminal-key>` loose in a page is the same
silent failure as a misspelled tag, and now it says so. The check is "somewhere inside" rather than
"directly under", because a card's tags sit inside the anchor. Note that a throw in
`connectedCallback` does not reach whoever inserted the element — the browser reports it as an
uncaught error instead, which is loud enough to notice and is how the tests capture it.

Two consequences of self-containment worth knowing:

- **With JS off, the self-building elements are empty — and that now includes real content.** A no-JS
  visitor gets no cover image, an empty player frame, and no terminal: no bpm, key or genre on a
  release, and no error line on a 404. **The home page is now in that set too**, since the profile
  player is an element: the hero still reads in full — wordmark, tagline, `releases →` — but under
  `latest tracks` there is a reserved empty box. What that visitor loses is a convenience rather than
  a route, because the footer's plain link to the profile is on every page; `PageTest` pins both
  halves. Links, navigation, downloads, titles, taglines and the privacy and imprint pages are all
  unaffected, and the CSS still reserves every box so nothing reflows when the script lands. This is
  the accumulated cost of building markup client-side, and it is worth re-reading whenever another
  fragment moves. A `<noscript>` inside `<terminal-window>` and `<cover-art>`, carrying the same
  content, buys it back for the price of rendering it twice.
- **The consent notice is written by the element**, not the server. That is still sound: the transfer
  it warns about can only be triggered by a click, a click needs the script, and the script writes the
  notice. The provider is the element — `<soundcloud-player>` knows it is SoundCloud — and the wording
  is asserted in `test/js/soundcloud-player.test.mjs`, where it is written.

### The terminal

`ReleaseView::heroSection()` declares a `Terminal` — a label, a `TerminalCommand` and typed
`TerminalField` rows — and emits one tag. `<terminal-window>` builds the command, every row and the
cursor.

**The command line is an object rather than a string**, because both views that build one
interpolate something they cannot quote: a release title, and — on the 404 — the request path, which
is the one string on this site a visitor writes in full. `new TerminalCommand('find', $path)` quotes
the value and leaves a leading-dash flag bare, so `find "/some odd path"` reads as the shell
transcript it is dressed as. It is **not** a security boundary and must not be read as one: the
result is assigned to `textContent` by `<terminal-window>`, so it was never at risk of being
anything but text. What quoting buys is legibility, including when what it is quoting is hostile. The rows cross as JSON in an attribute, which is the only shape that stays generic across a
release's five metadata rows and a 404's single error line.

`TerminalTone` decides how a row reads, and the stylesheet decides which half of it that colours:
`ok` accents the value, `error` accents the key. The tone is on the row rather than on one half of
it, so that stays a styling decision.

### The embed, and the mirrored enums

`SoundCloudEmbed` no longer builds any markup. It renders `<soundcloud-player>` with the release's
facts as attributes, and the element builds the widget URL and the attribution from them. The split is
that the **server sends the release's facts** and the **element owns the provider's furniture** — the
accent colour, the artist handle, the attribution styling and the iframe attributes all live in
`SoundCloudWidget.ts` now. Adding a provider is an `Embed` implementation and a `ConsentGatedEmbed`
subclass, and nothing else.

**There are two axes here, and only one of them is the provider.** A *provider* is SoundCloud versus
somebody else; a *resource* is one track versus the whole account. The home page carries the second
kind — `SoundCloudProfileEmbed` → `<soundcloud-profile>` — which is the same player pointed at the
profile URL, and SoundCloud resolves that to the latest tracks. So the client grew a middle layer:
`ConsentGatedEmbed` is every provider's gate, `SoundCloudWidget` is SoundCloud's furniture, and each
of the two subclasses answers only `resourceUrl()`, `subject()` and `attributionTarget()`.

**`SoundCloudProfileEmbed` deliberately does not implement `Embed`.** That interface is what a
`Release` holds, and `Release::$embed` is typed for it — a profile player assignable to a release
would be nonsense. It is the release axis, not the gated-player axis, which is why the gate is shared
client-side and the interface is not shared server-side. It also carries **no id, handle or title**:
there is no release to take them from, and the handle is `Config::HANDLE`, which the element already
mirrors. That makes its output strictly emptier than the track player's — both suites assert the
served page names no SoundCloud address at all, and for the profile, no artist either.

**That claim is why the attribute enums are split in two.** `EmbedAttribute` is what any gated embed
carries — `height`, which is `Embed::height()` and therefore an embed's fact rather than SoundCloud's,
plus the `loaded` flag the gate sets. `SoundCloudPlayerAttribute` is SoundCloud's own. The height used
to live in the second one, which meant `ConsentGatedEmbed` — the provider-agnostic base class —
imported one provider's enum to find out how much space to reserve, and a second provider would have
had to emit an attribute named after the first. Nothing about the wire format changed: it is still
`height="300"`.

That means the server's output carries no SoundCloud address at all, which is a stronger version of
the old guarantee: there is nothing for a browser to preconnect or prefetch before the visitor agrees.

`assets/ts/model/` mirrors every fact the client reads out of the server's output. Nothing else:
nothing client-side touches `Genre`, `MusicalKey` or `ReleaseFormat`.

| Mirror | Guards |
|---|---|
| `Platform`, `SoundCloudOption`, `SoundCloudPlayerStyle`, `TerminalTone` | values the client resolves |
| `Tag`, `HtmlTag`, `HtmlAttribute` | what it creates and selects on |
| `SoundCloudPlayerAttribute`, `EmbedAttribute`, `TerminalAttribute`, `CoverArtAttribute`, `LinkAttribute` | what it reads off an element |
| `TerminalFieldKey` | the JSON keys a terminal row arrives under |
| `CssClass`, `ElementId` | what the stylesheet and the SPA router look for |
| `RequestHeader`, `RequestedWith` | the header that asks for a fragment, and the one that revalidates |

The kinds fail differently, which is worth knowing before renaming any of them. A wrong **value**
usually shows: a broken widget URL, a tone that does not colour. A wrong **name** shows as nothing —
`getAttribute` returns null and the element falls back, the browser meets a tag it has never heard of
and lays out an inert inline box, or the SPA router finds no `#content` and quietly switches itself
off with every page still working. None of that reaches a console.

The worst of them is `X-Requested-With`. Drift on either side and the server answers a SPA fetch with
a whole document, which `Navigation` then writes into `<main>` — a page broken in a way nothing
reports, from two strings that used to sit in different languages with nothing between them.

Two names have no PHP side and so no parity test — `tone` and `--player-height` are written by an
element and read only by the stylesheet. `TerminalFieldAttribute` and `CustomProperty` name them
anyway, because the stylesheet is exactly the kind of reader that fails in silence. `loaded` was a
third until `EmbedAttribute` gained a PHP side: it is still written only by the client, but it now
has a case on the other end for the parity test to compare against — the same arrangement as
`ResponseHeader::PoweredBy`, which names a header the site does not send. **A view must never emit
it**; it is named so the stylesheet's `&[loaded]` has something to point at. `CssClass` is the one that *can* be checked against it: `HtmlTest` parses `style.css` and
asserts the sets match in both directions, so a class with no rule and a rule with no class both
fail.

**What deliberately stays a literal**, so the absence reads as a decision rather than an oversight:
the platform's own vocabulary (`'click'`, `'error'`, `'popstate'`, `'same-origin'` — TypeScript's
DOM types already carry those), user-facing copy, and SoundCloud's furniture. The player reproduces
that dialog's output exactly — `allow`, `scrolling`, `frameborder`, the `url`/`color`/`visual` query
keys, the accent and the attribution's font stack — and none of it is a contract with our own code.
`SoundCloudOption` is enumerated only because the *server* says which options are on.

**A mirror is a second copy of a fact, so it is tested.** `test/js/enum-parity.test.mjs` compares each
one against its PHP original — name, backing value, and the accessors the client mirrors — in
declaration order, because `SoundCloudEmbed` and `SoundCloudWidget` both build the query string by
iterating the cases. Add a case on one side only, rename one, retype a backing value or reorder two,
and it fails.

Adding an element means a `Tag` case, a module named for its class, and an import in `main.ts`. Three
checks cover it from three directions: `ViewTest` pins the set a view may emit *and* names the five
tags no view emits because `<terminal-window>` builds them; the verify script checks that every
custom tag in a real response is a `Tag` case; and `vocabulary.test.mjs` checks that every `Tag` case
is actually registered once `main.js` has run — the direction that catches a forgotten import for a
tag no response contains.

### SPA navigation

`Navigation` intercepts internal link clicks, fetches the content fragment via XHR (`X-Requested-With:
XMLHttpRequest`), and swaps `#content`. Download links carry `data-no-spa` to bypass this and trigger
real navigation (otherwise the 303 is consumed silently by fetch).

The selector matches the href *attribute* and the code then uses the *resolved* `link.href`, so
`onClick` reconciles the two: `//evil.example/x` starts with a slash exactly as `/releases` does, and
a cross-origin URL is handed back to the browser rather than fetched. Nothing the server emits is
protocol-relative — `Element` refuses to write one — so this is the client's half of the same rule.
It matters because `go()` ends in an `innerHTML` assignment, which is safe only for as long as what
lands in `#content` is markup this codebase generated.

Nothing re-runs after a swap. The browser upgrades any custom element it parses, including markup
assigned through `innerHTML`, so the gate and the cover wire themselves on arrival. `Navigation`
still fires a `neurosys:navigate` event on `document` — subscribe with `Navigation.onNavigate()`
rather than the string — for anything that is not an element and does need to know.

### The stylesheet

A component's CSS lives with the component, and `public/assets/css/style.css` is generated from it —
the same arrangement as `assets/ts/` → `public/assets/js/`, for the same reasons.

```
assets/css/                   ← sources; outside public/, neither web-served nor deployed
├── main.css                  ← the @import list; the order IS the cascade
├── base/                     ← tokens.css (:root), elements.css (* html body a)
├── layout/                   ← shell.css (what Layout.php emits), utilities.css
├── views/                    ← home.css, release.css, stats.css        (cf. src/NeuroSYS/View/)
└── elements/                 ← card.css, terminal.css, CoverArt.css,
                                embed.css, download.css                 (cf. assets/ts/elements/)
      ↓ npm run build
public/assets/css/style.css   ← generated, committed, deployed
```

**Never hand-edit `public/assets/css/style.css`.** It carries a marker comment above each block
naming the part it came from; edit that. The verify script rebuilds and diffs, so an edit made there
fails the build and is lost at the next one.

`main.css` is the CSS half of what `main.ts` is for the elements: an explicit list, in order, that
nothing derives from a directory walk. `tools/build-css.mjs` inlines each `@import` — the source form
is never the served one, because left in place the browser would discover each part only after
parsing the one before it, and a typo'd href would 404 in silence with that component unstyled.
Inlining costs nothing and makes both a build error. The build also refuses a part imported twice, an
import that does not resolve, and a rule in a manifest — a file either orders parts or is one, so an
ordering decision is never made twice.

**`elements/` mirrors `assets/ts/elements/` at the component level**, because there the directory is
the component and not the file: `terminal.css` styles `<terminal-window>` and the five tags it
builds. The invariant is **every `Tag` case is styled by exactly one part**, asserted in both
directions by `HtmlTest`. That closes the last unchecked mirror on the site — a tag name in the CSS
had nothing on the other end of it, so renaming a case left the stylesheet quietly not matching,
which on a dark page reads as a layout bug rather than a typo.

`card.css` is the single part named for a concept rather than a component, because the catalogue
entry and the download entry genuinely share a look across `release/` and `download/`. It is meant to
be conspicuous the way `RawHtml` is: `HtmlTest` pins the list to that one file, so a second has to be
argued for in a test named for the fact. What falls out of it is worth reading — there is no
`elements/release.css`, because every `release-*` tag *is* card and nothing else.

Two things did not move to a runtime: **the CSS never arrives via JavaScript.** Shadow DOM or
`adoptedStyleSheets` would be the literal way to bundle a stylesheet with its element, and it would
cost a flash of unstyled content on every load and leave a no-JS visitor an unstyled page — spending
exactly the reserved-box guarantee the *Custom elements* section above is careful to keep. Colocation
is a property of the sources; the browser still gets one static file under a strict `style-src`.

## Adding a release

Edit `data/releases.php` — that's the only file. Each entry is a typed `Release` object:

```php
'your-slug' => new Release(
    title:       'track title',
    bpm:         140,
    key:         MusicalKey::FSharpMajor,   // see MusicalKey enum for all 24 keys
    genre:       Genre::Dubstep,            // see Genre enum
    description: 'debut single',
    cover:       new HiDriveLink('J2FXbB70A'),   // id from Share → Direct download link
    formats: new Collection(Format::class)->with(
        new Format(ReleaseFormat::FLAC,  new HiDriveLink('BXRsy9S7d')),
        new Format(ReleaseFormat::MP3,   new HiDriveLink('CPJy7AVIu')),
        new Format(ReleaseFormat::STEMS, new HiDriveLink('D2PUDjoII')),
    ),
    embed: new SoundCloudEmbed(          // omit entirely to hide the player
        trackId:     2394077313,         // numeric id from the track's embed URL
        permalink:   'ill',              // the track's slug on SoundCloud
        secretToken: 's-dIMAqki109G',    // only for a private/scheduled track; omit when public
    ),
),
```

Omit a format entry to hide that download card; keep the entry but omit its `HiDriveLink` to render the card
in the "not uploaded yet" state, where clicking returns a 503 instead of redirecting.

**Never paste a full HiDrive URL.** `HiDriveLink` takes the 9-character share id and builds the direct-download
URL around it. It rejects anything that isn't 9 alphanumeric characters, so a truncated paste throws when the
data file loads rather than 404ing at HiDrive later. `cover` and every `Format` take the same `FileLink`
interface — another host means a new class implementing it, and no change to `Release`, `Format`,
`DownloadController` or `ReleaseView`.

**Never paste SoundCloud's embed HTML.** `SoundCloudEmbed` generates it — see `docs/releases.md` for where the
three ids come from. Player style and the six SoundCloud toggles are `SoundCloudPlayerStyle` /
`SoundCloudOption` enums with sensible defaults; a normal release never sets them. Adding another provider
means a new class implementing `Embed`, not a new field on `Release`.

## Deployment

`public/` maps to Strato's `htdocs/` (web-exposed). `data/` lives **outside** the webroot — it's uploaded separately and never via the standard deployment mapping.

- Regular deploy: `./deploy.sh` (rsync over the mounted SFTP), or right-click `public/` →
  **Deployment → Upload to Strato** in PHPStorm
- `deploy.sh` ships `public/`, `src/`, `autoload.php` and `data/` — but **excludes `data/admin.php` and
  `data/site_auth.php`**, because the repo copies are placeholders and syncing them would overwrite the
  live credentials. Upload those two by hand when they change.
- `data/admin.php` holds bcrypt credentials for `/admin/stats`; generate with `php -r "echo password_hash('pw', PASSWORD_BCRYPT);"`

Footer profile links come from `data/profiles.php` — an empty URL hides that link. Brand icons are **vendored** under
`public/assets/img/brand/`, never hot-linked from a platform CDN; see `docs/branding.md` for why and for each platform's
usage rules.

### What `.htaccess` does to a response

Beyond the `SetHandler` allow-list and the HTTPS redirect, `public/.htaccess` shapes every static
response. Measured on the live host 2026-09-05: Strato compresses **nothing** and sets **no
`Cache-Control`** — `main.js` arrived byte-identical to the file on disk, with only an `ETag` and a
`Last-Modified`.

**Note what that block does and does not reach.** Every `Header set` here sits inside a
`<FilesMatch>` keyed on a file extension, so it applies to what Apache serves and never to a
document, which `index.php` produces. Documents answer for themselves — `ViewResponse` sends
`Cache-Control: no-cache`, an `ETag` over the rendered body and `Vary: X-Requested-With`, so a
returning visitor revalidates and usually gets a 304. The two halves fit together deliberately: a
document embeds the versioned asset URLs, so a cached document naming *last* build's URLs would be
served last build's JS out of the year-long immutable cache below. `no-cache` means there is no
window in which that can happen, rather than a bounded one. Both blocks are `<IfModule>`-guarded, which means an absent module is silence rather
than a 500, and equally means a missing `mod_deflate` would leave the block doing nothing with no
sign. **Re-check after deploying**, since this is not something either test suite can see:

```bash
curl -sI -H 'Accept-Encoding: gzip, br' https://neurosys.gg/assets/js/main.js | grep -i 'encoding\|cache'
```

**The version-segment rewrite is the highest-risk line in the file.** Compression failing costs
bytes; that rewrite failing costs every stylesheet and every module, because the manifest names URLs
only it can resolve — an unstyled page with no JS at all. It is verified against real Apache locally
and against the live host on each deploy, and it is the first thing to check if a deploy goes wrong:

```bash
curl -s https://neurosys.gg/ | grep -oE 'href="/assets/css/v-[^"]+"' | head -1
```

Take that path, request it, and expect a 200 with `immutable` in `Cache-Control`. A 404 means the
`RewriteRule` did not fire and the fix is to revert the manifest to unversioned URLs, not to debug
it live.

Cache lifetimes come in two tiers, split by whether the URL names its own content. Built assets are
served under a build-stamp segment and get `immutable` for a year; see
[Cache versioning](#cache-versioning). Everything else keeps a calendar TTL — an hour for a bare
`.css`/`.js` (nothing the site emits asks for one, so this is only ever a URL somebody typed), thirty
days for images and fonts. The two are made mutually exclusive by document order rather than by an
`env=!` condition, because the rewrite is an internal redirect and the variable then arrives named
`REDIRECT_VERSIONED`; both spellings are set, and only one is ever defined. Verified against real
Apache, not reasoned about.

The live host serves **HTTP/2** (no HTTP/3 — no `Alt-Svc`), Apache 2.4.68.

See `docs/deployment.md` for first-time FTP setup, `docs/releases.md` for the full release checklist,
`docs/branding.md` for brand assets and profile links, `docs/testing.md` for the two test suites, and
`docs/security.md` for the security posture and the assessment findings.
