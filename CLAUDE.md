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
response here does. `tools/merge-coverage.php` unions the two into `build/coverage/`. **98.40% of
lines** (1609/1635); of the twenty-six that are left, ten are deliberate — the `DOWNLOAD_LOGGING`
switch in `StatsController` and `DownloadLogger` — and ten are a gap rather than a decision:
guard-clause `throw`s on the header-value classes `867372f` added (`CacheControl`, `Vary`,
`Location`), which nothing has exercised yet. The demo work added two of the same kind and closed
both, so the pattern for closing the rest is written down in `DemoTest`. The remaining six, in
`FileResponse`, `Auth` and `File`, are the drift `docs/testing.md` names at the end of its coverage
section. **The lazy-collection work is the same twenty-six lines and not one more** — it added two
of its own (`SectionPosition`'s range guard, and `first()`'s answer when a *pending chain* runs out,
which is a different loop from the fast path's `array_find`) and closed both in the same pass, which
is what that pattern is for. **So is the attribute-value work**, which added three — `SitePath`'s
arity guard both ways, and the `Accept-Language` parameter that is not a weight — and closed all
three in the same pass. The twenty-six are a property of what is *deliberately* untested, not a
budget that grows with the code.

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
functions, so a branch nothing exercises fails the command. That gate is why `test/js/dom.mjs`
carries a **recording 2D context** and a `ResizeObserver`: jsdom implements neither, and the real
`canvas` package is a native build against cairo. Recording is the better test rather than merely
the cheaper one — what is worth asserting about a waveform is which bar was drawn where, in which
colour, at which opacity, and a real canvas would answer that only by being read back as an image.
It also has teeth the other way: `?? 0` on a typed-array index inside `DemoWaveform` was a branch
nothing could reach, and the gate refusing it is what turned that code into a `charCodeAt` that
needs no fallback at all. That is affordable here and nowhere
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
├── Http/           ← Request, Response interface, ViewResponse, RedirectResponse, PlainTextResponse,
│                     FileResponse + ByteRange/ContentRange/ContentLength/AcceptRanges
│                     + HttpStatusCode, HttpMethod, MimeType/TopLevelType, Header/HeaderName
│                     and the two header-name enums, AuthScheme, AcceptedLanguages,
│                     ServerVariable
│   └── Security/   ← ContentSecurityPolicy + CspSourceList, PermissionsPolicy + the enums they
│                     compose
├── Model/          ← Release, Format, Demo, DemoTrack, Profile, MusicalKey, Genre, ReleaseFormat,
│                     Platform, Waveform + WaveformColumn/WaveformBand (typed value objects + enums)
│   ├── Production/ ← what the .flp knows: Arrangement + Section + SectionKind + SectionPosition,
│   │                 ProductionTime, Plugin
│   ├── Embed/      ← Embed interface + SoundCloudEmbed (one track) + SoundCloudProfileEmbed
│   │                 (the whole account); each renders its element from typed params
│   └── Link/       ← FileLink interface + HiDriveLink; generates share URLs from a share id
├── Service/        ← Auth, DownloadLogger, DownloadLogEntry, DownloadStats, ReleaseRepository,
│                     ProfileRepository, DemoRepository, WaveformRepository
├── Support/        ← Collection<T>, SearchableCollection<T> (both immutable and lazy, objects or
│                     scalars)
│                     + the TypedItems trait they share, File + Directory, Route + SitePath,
│                     RouteInitialization, JsonDeserializable, Charset, UrlScheme, PasswordHash
├── View/           ← View abstract base + one concrete per page; each returns a Node, not a string
│   ├── Html/       ← the markup tree: Node, Element, Attribute, Text, RawHtml, Fragment, Document,
│   │                 Doctype + Tag/HtmlTag, the attribute-name enums (WaveformAttribute among
│   │                 them), the attribute-value
│   │                 enums LinkRel / LinkTarget / ScriptType / MediaPreload / MetaName /
│   │                 Language / ViewportWidth, and the AttributeValue interface + ViewportContent
│   └── Terminal/   ← Terminal, TerminalCommand, TerminalField + the enums they compose
├── Config.php      ← the facts about this site: identity, origins, paths, switches
├── DataFile.php    ← every file the site reads out of data/, named rather than spelled
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

**Both sides of the Basic handshake now spell its token once.** `BasicChallenge` wrote
`Basic realm="…"` into the 401 and `Request::fromGlobals()` matched `'Basic '` on the way back in,
in two files, neither knowing about the other — and a mismatch there is the quietest failure on the
site: `Request::authorization()`'s docblock already describes it about the header's *name*, and it
is the same failure. Both gates would refuse everything, identically, on every attempt, with
nothing in any log, and the first thing anybody would suspect is the credentials file. It is
`AuthScheme::Basic` at both ends now, and the grammar under it —
`Basic SP base64(user ":" pass)` — lives in `credentials()` rather than as two `explode()`s in the
middle of building a request. The space is part of the token, deliberately: `Basicxyz` starts with
`Basic` and is not a credential. What it does **not** change is that a payload with no colon is a
user name and an empty password rather than a refusal; that is older than the class and pinned by a
test named for it, and it costs nothing because an empty password matches no bcrypt digest.

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

**The `$_SERVER` keys a request is built from are `ServerVariable` cases**, because every reader of
that superglobal here ends in a default — `?? 'GET'`, `?? '/'`, `?? ''` — which is exactly what
makes a misspelled key indistinguishable from a request that did not carry the value.
`PHP_AUTH_USER` is the one that matters: a typo there leaves the user `''`, which no stored
credential equals, so both gates refuse everything with a 401 that reads as a wrong password.

Not every key belongs on it, and the rule is whose name it is. A request header arrives under
`HTTP_` plus the name upper-cased with dashes as underscores — PHP's transform, so
`Request::header()` applies it to a `RequestHeader` case rather than anybody retyping the result. A
case earns a place on `ServerVariable` when that derivation cannot reach the name
(`REDIRECT_HTTP_AUTHORIZATION` is Apache's invention, not HTTP's) or when the reader has no
`Request` to ask — which is `HTTP_REFERER`, deliberately: `DownloadLogger` must read it *behind* the
`DOWNLOAD_LOGGING` guard, and an argument would be evaluated in front of it. Note the spelling. The
header lost an `r` in 1996 and the property it fills, `$referrer`, did not.

Every header a response sends is a `Header` — a `HeaderName` case and a `HeaderValue`, formatted in
one place instead of a `header('Name: ' . $value)` call per site. The names live in two enums on
purpose: `SecurityHeader` is exhaustive and tested as such, and `ResponseHeader` is everything else.

**Both halves are typed, and the value half arrived second.** The name was an enum first because a
misspelled header name is silent; the value stayed a string on the reasoning that a value is just
text. So is a name. The difference is that a header value has a **grammar** — a quoted `ETag`, a
comma-separated `Allow`, `Basic realm="…"`, `max-age=…; includeSubDomains`, `no-store, private` —
and every one of those was being assembled at a `new Header(…)` call site, which is the one place a
grammar cannot be checked. `ContentSecurityPolicy`, `PermissionsPolicy`, `StrictTransportSecurity`
and `MimeType` already had `render()` and only ever lacked the interface saying what it was for;
`CacheControl`, `ETag`, `Vary`, `Allow`, `BasicChallenge` and `Location` are the ones that had
nowhere typed to live. `SecurityPolicyTest` pins the set in both directions and renders every one.

Two things fell out of it. `SecurityHeaders::send()` used to flatten each case to a string and parse
it back with `SecurityHeader::from()` one line later, purely because the value beside it was a
string — that round trip is gone. And `Location` is now checked: an absolute `https://` URL, the
same shape `Profile` demands, which makes it the one address the site emits that used to have
nothing looking at it.

`Content-Type` is a `MimeType` — a `TopLevelType` case, a validated subtype and a `Charset` — and not
a string with `; charset=utf-8` stapled onto it. A class rather than an enum for the reason
`StrictTransportSecurity` is one: the value carries a parameter, and a case cannot hold one. The two
the site sends are `MimeType::html()` and `MimeType::plainText()`, so no call site types a subtype,
and a malformed one throws where it is written the way `CspHost`'s origin does. The charset is the
half that earns the class — `nosniff` stops a browser guessing the type, and nothing stops it
guessing the encoding.

**A path is a `File`, not a string.** `Config::dataFile()` hands back one, and the five classes that
read `data/` — `Auth`, both repositories, `PrivacyController`, `StatsController` — stop each asking
`is_file()` in their own words. That collapse is what the class is for and it fixed a real fault
along the way: `is_file()` guards a file that is absent and does nothing about one that is present
and unreadable, so `file_get_contents()` warned, and the headers have already gone out by then —
the warning printed into the page ahead of the doctype. `File::read()` answers `null` for both
causes, which is what every caller was collapsing them to anyway.

**And what it is handed is a `DataFile`, not a path.** That is the same argument one level up: a
name nothing recognises resolves to a `File` like any other, `read()` answers null for it, and each
repository turns that null into an empty collection *on purpose* — because a clone that has never
staged a demo has to be a site rather than a fatal. So the guard that makes a fresh checkout work is
the guard that swallows a typo, and `releaes.php` is an empty catalogue with a 200 and nothing in
any log. Two of the eight cases are where the credentials live, and `site_auth.php` is worse than
quiet: its **absence is the off switch**, so a misspelling there does not fail, it stands the
pre-launch gate down. The vocabulary already existed before the enum did — as a hand-maintained data
provider in `ConfigTest` that listed four of the seven and could not notice the rest. That provider
now iterates the cases and asks `isTracked()`, which is checked against git rather than against a
comment.

**The privacy policy is two cases, not one.** `privacy.de.html` and `privacy.en.html` were one
`privacy.html` holding a German document and an English one end to end — which is how two
e-recht24 exports get concatenated by hand, and it was fine for as long as the order was fixed. It
is not fixed any more (see [Language](#language)), and separating the halves at read time would
mean searching a legal document for a heading. Both are tracked, so a clone has the policy it needs;
a half that fails to read is an empty half, which is the answer `File::read()` already gave for the
single file. **`deploy.sh` has no `--delete` on `data/`**, so the old `privacy.html` is still on the
mount and has to be removed by hand — the same asymmetry that keeps demos safe, cutting the other
way for once.

**It cannot create a directory, and that is the decision rather than the omission.** `write()` and
`append()` both fail on a path whose directory is missing. The downloads log's directory is excluded
from `deploy.sh`; an `@mkdir` was once added to "fix" that, had to be reverted, and the directory it
had already made on the live server had to be deleted by hand. Creating one is `Directory`'s to do
and a caller's to ask for. `Directory` also refuses to recurse: `remove()` takes away the files it
holds and then itself, so a fixture comes apart and a tree does not.

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
| `Element` | a `TagName`, a keyed collection of `Attribute`s, child nodes |
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
header. `preload` is `MediaPreload` and `<meta name>` is `MetaName` on the same grounds — the second
is the whole vocabulary of an attribute used nowhere else on the site, and it fails the way the rest
of this list does, which is not at all: a `<meta>` whose name nothing recognises is laid out as
nothing and moved past, so a misspelled `viewport` renders every phone at 980px with the media
queries answering for a screen nobody is holding. `lang` is `Language` on the same grounds and
fails the same way — a language tag nothing recognises is not an error, it is a screen reader
picking the wrong voice and a hyphenation dictionary picking the wrong words. These are
server-only, so they have no TypeScript mirror and none is wanted.

**A value with a *grammar* is a class, not a case**, and `attr()` takes one through an
`AttributeValue` interface — the same shape `HeaderValue` has on the HTTP side, arrived at the same
way and second for the same reason. `ViewportContent` is the one implementation:
`width=device-width, initial-scale=1.0` is a descriptor list of name-value pairs, and it was being
assembled inside the `->attr(…)` call, which is the one place a grammar cannot be checked. Its
width is a `ViewportWidth` case and its scale is a `float`, so neither half can be misspelled.
Note the two rules the class exists to keep: the scale renders `1.0` rather than PHP's `(string)`
of it, which is `1` — the same instinct that kept `Charset` carrying two spellings of one encoding
— and it is formatted with `%F` rather than `%f`, because `%f` under a German locale writes a
decimal comma and a comma is this grammar's own separator.

**Unwrapping happens in `attr()`, and both guarantees stay in `render()`.** That is not a
contradiction of the rule two paragraphs down: unwrapping is *normalisation* — shorthand for the
string a call site would otherwise have typed — where escaping and the scheme check are guarantees,
which have to hold for an element built any way at all. `Attribute` still holds a `?string`, so
what a hostile `AttributeValue` returned is escaped exactly like anything else. `HtmlTest` builds
one to prove it.

`Element::attr()` is the whole attribute API. What you pass decides what renders: a string or int is
a value, `true` is a bare boolean attribute, and `false`/`null` leave it off. `''` and `null` are
deliberately different — `options=""` is a real empty value, `secret-token` absent is not.

**An attribute is an `Attribute`**, held in a `SearchableCollection` keyed by its name. It used to
be an `array{AttributeName, string|null}` in a map keyed by the same string — a two-slot tuple
destructured in the one place that read it, where `[$attribute, $value] = $pair` only reads
correctly if you already know the answer. The name is kept beside the value even though the map is
keyed by it, because `render()` has to ask the name whether it is a URL and a key is a string; the
key is what keeps last-write-wins and declaration order.

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
  this site", so it is asked rather than assumed.** The allowlist itself is `UrlScheme` cases now
  rather than two strings — a scheme is a fact about a URL and not about markup, so it sits in
  `Support/` beside `Charset` for the same two-reader reason, and the footer and the imprint build
  their `mailto:` through it instead of concatenating a prefix each. The *list* stays its own
  constant rather than collapsing to `UrlScheme::cases()`: the enum is the vocabulary a URL may be
  written in, the constant is what is switched on — the distinction `CspScheme::Data` already
  makes. This used to be a two-entry list of the prefixes
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

**`RawHtml` is the single hole, and it is meant to be conspicuous.** It exists for the two halves
of `data/privacy.*.html`, a hand-authored document rather than markup a view assembles. `HtmlTest`
pins its call *files*, so a second view has to be argued for in a test named for the fact — the
policy's two constructions are both in `PrivacyView`, which is why splitting it changed nothing
there. Never construct one from anything a
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

What stayed behind in each class is what genuinely differs — `with()`, `find()`, `getIterator()`,
`ofType()` and `sequenced()`, the last two being the trait's abstract members. That difference is the
reason there are two classes at all.

### A chain stays a collection, and it runs once

**Every transforming step answers with a collection and every one of them is lazy.** `where()` and
`map()` record a stream transformer and run *nothing*; the callbacks are first called by a
materialiser — `toArray()`, `toValues()`, `toKeys()`, `first()`, `last()`, `join()`, `settled()`,
`count()`, `isEmpty()`, `getIterator()` — which folds every pending step over **one pass** of the
source:

```php
$this->directives
    ->map(static fn(CacheDirective $directive): string => $directive->value)
    ->join(', ');
```

The passes are **fused**, not staged: `->where(even)->map(rename)` over `1..6` calls its callbacks
in the order `w1 w2 m2 w3 w4 m4 w5 w6 m6`, so each element goes through the whole chain before the
next is touched and nothing in between is ever built. `SupportTest` asserts that order, because it
is the one property an eager implementation passes every other test without having. It also means
the short-circuiting materialisers genuinely short-circuit — `first()` on that chain stops after
`w1 w2 m2`, and `isEmpty()` after `w1`.

`stream()` builds a fresh generator each time it is asked, so a pipeline is re-iterable and a nested
`foreach` over one works; the classic once-only-`Generator` trap does not apply.

**`map()` reads its element type off the callback's own return declaration**, via
`ReflectionFunction`, which is why it takes no type argument. A `class-string` parameter beside a
callback that already declares `: string` is the same fact written twice, and stating it once puts
it where PHP itself enforces it — a callback returning the wrong thing is a `TypeError` at the
`return`, naming the function, before the collection sees the value. **So a mapped item is not
`guard()`ed**, and that is provable rather than lax. A callback with no declared return type, or a
union or nullable one, throws at the `map()` call; everything else a return type can say (`void`,
`array`, `object`, `static`) is refused by the constructor, which names it.

`map()` answers with the **base** shape rather than `static`: a mapped `CspSourceList` holds strings,
not `CspSource`s, so calling it a source list would be a lie. `where()` and `settled()` keep the
subclass, because neither changes what is held.

**A map keeps its keys through a `map()`.** This used to reindex — the implementation talking, since
`array_map` given two arrays returns one. The consequence to know is that a mapped
`SearchableCollection` can no longer be spread into a call, because string keys are named arguments,
so every spreading call site asks `toValues()` and says so.

**The one rule laziness added: a step that does work runs where it is asked for.** Every callback
here is pure except one — `DemoStage::write()` filters on a predicate that *transcodes with ffmpeg*
and reports whether that worked. Left pending, `write()` writes nothing: its caller's `isEmpty()`
stops at the first failure with every mix behind it unstaged, and the loop that reports the failures
encodes them all a second time. `settled()` is what that method ends in, and it is the member this
change made necessary. `Directory::files()` ends in one too, for the weaker version of the same
reason: `exists()` is a `stat()`, and a directory listing is a snapshot rather than a live query.
Since `settled()` answers with the collection itself when nothing is pending, `assertSame($c,
$c->settled())` is how a test asks "is there work pending here?" without reaching for a private
member.

**What laziness costs is written down rather than waved at.** With no steps pending every
materialiser reads the store directly and builds no generator at all — `toValues()` is **0.45 µs**
against **3.35 µs** for a one-step pipeline, on the two-item collection `Element` holds. That fast
path is why the eager majority is exactly as cheap as it was. The one hot call site that *is* lazy
is `Element::renderChildren()`, which went from `implode('', array_map(…))` at **2.29 µs** to
`map(…)->join('')` at **5.16 µs**; over a whole page that is **1.141 ms → 1.257 ms**, about **10%**
of the time spent rendering markup and a fraction of a percent of a request. If that ever stops
being worth a chain that stays inside the type, `renderChildren()` is the one place to spend a
`foreach` and the only one.

The other place `map()` is called in a loop is `ContentSecurityPolicy::render()`, where the inner
one runs inside the outer one's callback: **16.45 µs → 40.32 µs** per render, so **+24 µs on every
response**. A `map()` costs 1.76 µs before it maps anything — 0.65 µs of `ReflectionFunction`,
0.58 µs to construct the collection, the rest copying — and that is the price of the type coming
from the callback rather than from an argument. Both figures are here so the next person weighing
this has them rather than a guess.

Three more decisions are worth knowing before adding an eleventh member:

- **The callback takes the value first and the key second.** That is the order PHP's own
  `array_find`, `array_any` and `array_all` use — `Element::renderChildren()` already calls one —
  and the order `ARRAY_FILTER_USE_BOTH` passes. It is also what keeps a one-argument callback a
  first-class callable, since PHP hands a userland callback extra arguments harmlessly:
  `$links->map(self::profileLink(...))` needs no closure around it. Key-first would have broken
  every such site, and `ReleasesView::card()` had its own parameters swapped to match rather than
  become the exception.
- **`sequenced()` is abstract because a filter is the only thing that can put holes in a list.** A
  `Collection` renumbers after a `where()` — so a `map()` following one sees `0, 1, 2` exactly as it
  did when `where()` rebuilt an array eagerly — and a `SearchableCollection` keeps its keys, its
  implementation being a `return $stream` rather than a `yield from` so the map pays nothing for a
  layer that hands back what it was given. It is pushed by `where()` alone: resequencing after every
  step, which is how this was first written, spent a generator layer per step renumbering keys that
  were already in order.
- **`ofType()` must answer with its own class**, not the sibling. `map()` writes `$copy->items`
  across instances, which PHP allows only between instances of the class that declared the private
  member — so the obvious way to make a map answer with a list is not a type error but a fatal.

`last()` takes **no predicate**, unlike `first()`: nothing here searches backwards, PHP gives
`array_find()` and no `array_find_last()`, and `where(…)->last()` already answers the day something
wants one.

All ten carry `#[\NoDiscard]` and `NoDiscardTest` pins them three times each, since PHP reports a
trait's members on both using classes *and* on the trait. Nine are pure, so a dropped result is never
anything but a bug; `settled()` is the tenth and belongs to both halves — dropping it is the one
discard here that does work and then throws the work away, which is the mistake it exists to stop.

**`all()` and `keys()` are gone**, replaced by `toArray()` / `toValues()` / `toKeys()`. `all()` said
nothing about which of the two shapes you were getting; the new three say it in their names, and
`toArray()` is the door — everything above it stays inside the type.

**What deliberately stays a plain array.** `Preflight`'s findings, `ReleaseFolder::missing()`'s
filter over `Fact::cases()`, `FlpFile::all()` — none crosses a public boundary, and the rule below
already says a collection does not replace a variadic. PHP's own `array_find`/`array_any`/`array_all`
are the API there. `CurlTransport::parts()` keeps its `foreach` for a different reason: it rekeys by
field name, which is not a `map`. `Dsp/`'s buffers and `DownloadStats`'s tally accumulator stay
arrays for a third reason again — both are written to in a loop, and `with()` copies; see *What
deliberately did not go in* below.

**And a great many things stay arrays because PHP hands them over that way.** `cases()` (19 sites),
`preg_match`'s `&$matches` (17), `unpack` (16), `file` (10), `parse_url` (7), `explode` (6),
`json_decode` (5), `glob`, `range` — about ninety points across `src/` and `tools/` where a builtin
answers with an array and no amount of typing on this side changes that. The target is therefore
**all-collection in the interior with an adapter at each door**, not zero arrays anywhere:
`Directory::files()` is the model, where `glob()` is the door and the `Collection<File>` is what
crosses the boundary.

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

**What moved into them since**, each for the same reason — a shape crossing a public boundary with
nothing checking it: `Element`'s attributes (a `SearchableCollection<Attribute>`, keyed by name,
which is what keeps last-write-wins), `ReleaseFolder`'s audio files (keyed by `ReleaseFormat` value,
in the order the catalogue lists them), and an outbound `Request`'s headers and body fields. None of
those had a hand-rolled check to replace, which is the weaker half of the rule: they had *no* check,
and an `array<string, string|FilePart>` is a docblock's promise rather than the language's.

**And since that, five more, found by asking which `array_*` calls the query methods should have
been doing.** `Element`'s **children** are the one worth reading twice: they are the sibling
parameter of the attributes on the same public constructor, so the argument that moved one had
already been made about the other and stopped one parameter short. `containing()` is a variadic and
PHP guards it; the constructor took a bare `array`, and a string reaching it that way is not a
`TypeError` naming the element but a fatal in `renderChildren()` calling `render()` on a string.
`ViewResponse`, `PlainTextResponse` and `FileResponse` take a `Collection<Header>` for the same
reason the outbound `Request` already did. In `tools/`: `Project::$markers`, whose `markersOf()` was
`where()` spelled `array_values(array_filter(…))`; `Call::$arguments`, reached by three public
factories; and `DemoStage::$sources`, whose `write()` was another longhand `where()`.

One of those turned up a live bug rather than a latent one. `ViewResponse::send()` guarded the 304
with `$cache !== []`, which is true of *every* `Collection` — so a gated page started answering 304
to a guessed validator the moment `cacheHeaders()` returned one. `isEmpty()` is what it should have
been asking all along, and the test named for that hazard caught it in the same run.

**The rule is about the parameter, not the store, and that distinction took a while to draw.** This
file used to say `PermissionsPolicy::$denied` and `ContentSecurityPolicy::$directives` wanted no
collection because they are built only through a variadic that PHP already enforces. The first half
of that is still exactly right — `deny(PermissionsPolicyFeature ...$features)` keeps its variadic,
and a `Collection` parameter there would replace a check the language makes for free with one we
make ourselves. The second half was a non-sequitur: what a class *stores* after the variadic has
guarded the boundary is a separate question, and storing an array meant every one of these classes
rendered itself with `implode(', ', array_map(…))` — which is `join()` spelled out. So `Allow`,
`Vary`, `CacheControl`, `RobotsPolicy`, `PermissionsPolicy`, `Fragment` and `TerminalCommand` all
hold a `Collection` behind a variadic constructor now, and their `render()` is one `join()` each.
The rule, restated: **a collection replaces a hand-rolled type check on data crossing a public
boundary, and it is what a class holds afterwards; it does not replace a variadic.**

### What a collection may hold

**`T of object` was a limit of the check rather than a decision about the data.** `guard()` was a
bare `instanceof`, which is a test only an object can pass — so `list<string>`, `list<float>` and
every counted tally stayed outside the type for a reason that was never argued, only inherited. It
asks `get_debug_type()` beside the `instanceof` now, and `Collection('string')`,
`SearchableCollection('int')` and the rest are collections like any other.

Three things fell out of it, and each is worth knowing before adding a fourth:

- **`null` and `array` are refused as declared types**, and `TypedItems::SCALARS` says so out loud.
  A collection of nulls holds no information; a collection of arrays is the shape every one of
  these was written to replace, so allowing it would let the escape hatch back in under the type's
  own name. That refusal is load-bearing rather than tidy — see `CspSourceList` below, which exists
  because of it.
- **`int` satisfies `float`, and nothing else widens.** That is the one coercion PHP itself makes
  under `declare(strict_types=1)`, so a collection that refused `array_fill(0, 512, 0)` would be
  stricter than the language it is written in. The value is kept as it arrived rather than cast,
  exactly as a parameter would.
- **The declared type is now checked in the constructor**, the same move `HiDriveLink` makes on a
  share id and `CspHost` makes on an origin. `instanceof` answers `false` for a string naming no
  class rather than complaining about it, so `new Collection('Reelase')` was not an error but a
  collection that silently rejected everything ever offered to it — reporting the typo as a
  `TypeError` about the *item*. `class_exists()` and `interface_exists()` are both asked, because
  the first answers `false` for an interface and `Collection(Node::class)` is among the commonest
  shapes here; enums need no third question.

**`CspSourceList` is the one place PHP's lack of nested generics costs something.**
`ContentSecurityPolicy` holds source lists keyed by directive — a map of lists — and a collection is
defined by a `class-string`, so the outer `SearchableCollection` has to be told what its values are.
Told `Collection::class` it would check only that each value is *some* collection, not that it is a
collection of `CspSource`, which is the entire question worth asking about a policy. Naming the
inner list recovers both halves. It is also what refusing `'array'` above forced: a collection of
arrays would have let the map keep untyped values under a collection's name, and closing that door
is what produced the honest structure.

### What deliberately did not go in

**`tools/lib/Dsp/` keeps raw arrays, and it is one function rather than a directory's worth of
exception.** `Fft::transform()` is the codebase's only genuine in-place mutation: its butterfly
reads and writes four arbitrary indices of two arrays per iteration, and the bit-reversal above it
swaps two more. No immutable collection expresses that, and no per-element callback can see another
element. Measured at 512 floats a window and ~2000 windows a mix: `array_fill` 1 ms, one batched
`with()` per window 354 ms, incremental `with()` 1321 ms. Everything else in those three classes —
`hann()`, `magnitude()`, `Analyze::mono()`, `Spectrum::bars()` — builds a *fresh* array in a loop
and returns it, so it is already `map`-shaped and already immutable; it stays arrays because the
port's stated contract is that the caller owns the buffer, and because the hot path is the one place
the 1.5x iteration cost of a collection is worth counting.

**Two query methods were designed and then not written**, each because it had no caller, which is
the same test `first()`'s backwards twin failed. There were three: `mapTo(class-string, fn)` was the
third, and it has since been written under another name — it is what `map()` became when it started
answering with a collection, and it needed no `class-string` argument in the end because the
callback's return declaration already carries one.

- **`do(fn(&$value, $key))`**, an in-place walk, was the obvious answer to the buffers above. It is
  mechanically fine — an arrow function does take a by-ref parameter — but it *is* `map()`, with the
  result landing in the old array instead of a new one, and it cannot reach the only code that
  wanted it. It also measured **5.2x slower** than the indexed loop it would replace (the closure
  call per element, not the reference: a raw by-ref `foreach` is *faster* than indexing), it would
  put a mutation on the class whose immutability makes it safe inside every `readonly` value object
  here, and it would be the first member meant to be discarded — inverting the `with()`/`add()`
  convention this file spends a paragraph on. If in-place ever becomes necessary, the honest shape
  is a separate `Buffer` type, not a hole in this one.
- **`zip()` / `unique()`** would each have served one call site in `Dsp/` and one in `hosts()` —
  both excluded above, both doors.

## How the router works

All requests hit `public/index.php` via `.htaccess` rewrite. It:

1. Builds a `Request` from `$_SERVER`
2. `Router::dispatch()` maps URL segments to a `Controller`, matching against `SitePath` cases
3. The controller fetches its own data (via `ReleaseRepository` or log file), builds a `View`, returns a `Response`
4. `Response::send()` handles headers/output — `ViewResponse` wraps in `Layout::wrap()` on full-page loads, emits a fragment on AJAX

Download routes (`/releases/{slug}/{format}`) call `DownloadLogger` and issue a 303 redirect to the HiDrive direct-download link.

**Every address the site has is a `SitePath` case, and that is one vocabulary rather than two.** The
router used to declare `/releases/{slug}` while nine views concatenated `'/releases/' . $slug`, in
different files, with nothing between them — the largest untyped vocabulary left here and the one
that fails most quietly: a view naming a path the router does not have renders a link that looks
perfectly fine and answers with the site's own 404. The case's *value* is the pattern, placeholders
and all, so `Route::matches()` matches with it and `SitePath::to(...)` fills it in; the placeholder
syntax is one constant both read. `to()` refuses the wrong number of values, which is the check a
concatenation cannot make — `'/releases/' . $slug . '/'` is a perfectly good string and a URL that
matches nothing. Two details worth knowing: each value is `rawurlencode`d, a no-op for every slug,
format and label in `data/` today; and the callback that fills them **must** be a `function` with
`use (&$values)`, because `fn()` captures by value and every placeholder would take the first value
— `/releases/ill/ill`, well formed, matching a route, and the wrong page. `RoutingTest` pins that
one by name.

**The tests keep writing paths out in full, deliberately.** A test asking `SitePath::Release->to('ill')`
would pass with the enum wrong. Same reason the verify script curls real URLs.

**The demo routes are the one place a file passes through PHP**, and that is the whole point of
them: `/demos/{slug}` and `/demos/{slug}/{label}` sit behind that demo's own password, and the audio
lives under `data/` where the web server cannot reach it, so the password covers the bytes rather
than only the page. `FileResponse` answers byte ranges because an `<audio>` element seeks by asking
for one. See [Demos](#demos).

**Download logging is deliberately off, for legal reasons.** `Config::DOWNLOAD_LOGGING` is `false`, and `log()` returns on it
before the `DownloadLogEntry` is built — so the referrer is never read and nothing is written. `StatsController` skips reading the
log entirely and `/admin/stats` says logging is switched off rather than showing an empty table. Both suites assert the switch
stays off, and the unit test additionally asserts the referrer is never read.

To turn it on later: flip `Config::DOWNLOAD_LOGGING` to `true`. That is a privacy-policy decision before a code one — the policy currently
makes no download-tracking claim in either language, so amend both halves first. Note the old failure mode is still latent underneath: `fopen(..., 'ab')`
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
    ├── arrangement/          ← ReleaseArrangement, ArrangementSection  (cf. Model/Production/)
    ├── waveform/             ← DemoWaveform                          (cf. Model/Waveform)
    └── release/              ← ReleaseList, ReleaseCard, …
      ↓ npm run build
public/assets/js/             ← generated, committed, readable, source-mapped
src/NeuroSYS/AssetManifest.php ← generated: the stylesheet, the entry, every module, versioned
      ↓ npm run build:prod
build/dist/                   ← generated, gitignored, deployed — see below
```

**Never hand-edit `public/assets/js/`** — it is build output and the next `npm run build` overwrites it.
`npm run watch` rebuilds on save; `npm run check` type-checks without emitting. The verify script fails
if the committed output has drifted from the sources: `deploy.sh` builds the prod tree out of
`public/`, so a forgotten rebuild would ship stale JS and nothing else would notice.

`npm run build` also builds the stylesheet — see [The stylesheet](#the-stylesheet) — and then the
asset manifest, below, which has to come last because it hashes both outputs. `npm run watch` does
neither; `npm run build:css` and `npm run build:assets` are each on its own.

### Debug and prod builds

`public/` is not what ships. It is the **debug** tree, and it is committed because three separate
things read it by path: `test/js/` imports the modules, `npm run coverage` pins its 100% gate to
`public/assets/js/**`, and the verify script diffs it byte-for-byte against a fresh `tsc`. All three
want output a person can read, which is why the version is a path segment and not a rewritten
specifier — see [Cache versioning](#cache-versioning) for what that already bought.

`npm run build:prod` derives the **prod** tree from it. `tools/build-prod.mjs` copies `public/`
wholesale, minifies every module with terser, deletes all 42 source maps, and writes a manifest of
its own:

```
build/dist/public/                        ← byte-for-byte what lands in the webroot
build/dist/src/NeuroSYS/AssetManifest.php ← the same URLs under a different stamp
```

Two things change, and both are only worth doing at the edge:

- **The maps go.** 79,354 bytes across 42 files, three times the JS they describe, and `inlineSources`
  puts the whole commented TypeScript inside each one. Static assets are served straight by Apache
  and reach neither auth gate, so those were public files. The source is on GitHub — a reason not to
  worry about it, not a reason to serve a second copy from Strato.
- **The JS is minified.** 11,807 gzipped bytes → 9,555, over the 42 separate responses the browser
  actually makes. `tsconfig` used to argue this was worth ~260 bytes; that is what you measure on a
  *concatenated* stream, where gzip's window spans the whole graph and does the work mangling would
  have done. Per file the window is one small module and it does not. On disk: 484K → 292K.

**`keep_classnames` is load-bearing.** `NestedElement.tagOf()` falls back to `constructor.name`, and
that is the text of the error a misnested tag throws — the whole reason those classes are not empty.
It is also why terser rather than esbuild: esbuild keeps the same guarantee by injecting a `__name`
helper into *every file*, which on 42 small modules costs 1,868 of the 2,252 bytes minifying won.
`mangle.properties` stays off one step further out, because `connectedCallback` and
`observedAttributes` are contracts with the browser rather than with us.

**The manifest's stamp differs between the two trees, and that is correct** — a stamp is a claim
about content, and those are different bytes at the same URLs. `deploy.sh` uploads `src/` from the
working tree and then overlays the prod manifest over the one file that differs.

**Four checks, because every failure here is invisible in a browser until it is live.** The verify
script builds the prod tree, asserts it ships no map and names none, diffs the two manifests with
the stamp normalised away — so a module minification dropped fails there — and then re-runs the
**whole client-side suite against the minified bytes**. That last one is the one worth the most:
`test/js/dom.mjs` takes its tree from `NEUROSYS_JS_DIR`, so the nesting guards, `TerminalWindow`'s
subtree, both embeds and `Navigation` all execute what the server will send. `npm test` and
`npm run coverage` take the default and are exactly what they were.

`build-prod.mjs` also refuses a tree where any module is byte-identical to its readable original —
the copy is what would deploy, under a stamp saying otherwise, and a page that is quietly bigger
than it claims has no other symptom.

**Why `build-assets.mjs` grew a `--graph-dir`.** Its import scanner is anchored to whole lines, and
that anchoring is load-bearing (a looser version once walked out of `export class Config {` into the
string below it). terser puts an entire module on one line, so walking the minified tree finds no
imports at all and the tool reports `main.js` as reaching nothing. The graph is a property of the
sources rather than of the formatting, so the readable tree is walked for *which files import which*
and the shipped tree is read for *what is in them*.

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
tests run against the same files the browser loads. Not worth it for 5.7KB.

That argument is weaker than it was, and worth re-reading rather than repeating: now that prod is a
[separate tree](#debug-and-prod-builds), a bundler could run there and leave the debug tree — the
one the tests and the gate read — alone. What it would still cost is the property that the shipped
files *are* the tested files, which is what re-running the suite against the minified bytes
currently keeps. **Minification is the same trade taken the other way**, and it is taken at the edge
for the same reason: 2,252 gzipped bytes, measured per-file rather than over a concatenated stream,
where the old ~260-byte figure came from.

Source maps sit next to the JS with the TypeScript embedded (`inlineSources`), so DevTools shows
`Navigation.ts` without `assets/ts/` having to be served. That is why `public/.htaccess` lists `map` — Strato
500s any static file it has no `SetHandler` for. **The prod tree ships none of them**; the handler
stays for the debug tree, which is what `npm run dev` serves.

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
| `waveform/DemoWaveform.ts` | `<demo-waveform peaks duration>` | a demo's whole shape behind its player: prepends a canvas, leaves the server's children alone. The one element that neither builds its subtree nor builds nothing |
| `arrangement/ReleaseArrangement.ts` | `<release-arrangement>` | nothing, deliberately — the sections are server-rendered, see below |
| `arrangement/ArrangementSection.ts` | `<arrangement-section kind>` | guard; `kind` decides which accent the stylesheet gives it |
| `release/ReleaseList.ts` | `<release-list>` | nothing, deliberately — see below |
| `release/ReleaseCard.ts` … | `<release-card slug>` `<release-title>` `<release-meta>` | guards only |

What stays native is what carries meaning or behaviour the browser provides: `<a>`, `<button>`,
`<h1>`/`<h2>`, `<img>`, `<p>`, `<section>` — and `<audio>`, which is the strongest case of the rule
and the one worth reading twice. A demo's player could have been an element like
`<soundcloud-player>`; it is not, because the browser's own control seeks, takes a keyboard, is
announced by a screen reader, and works with JavaScript off. A release page's empty box with JS off
is a cost this file accepts, since the page is still a page. **A demo is the audio**, so the same
cost there is the whole thing missing. See [Demos](#demos). The card tags wrap their anchors rather than replacing
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

  **A demo page is the deliberate exception**, and the only page here that does not pay this: its
  player is a native `<audio>`, so it works with JS off — see [Demos](#demos) for why that case is
  different from every other one on this list. Its waveform *is* drawn by an element and so is
  absent with JS off, and that costs nothing — which is the distinction worth keeping hold of. An
  element that builds what a page is about spends the guarantee; one that draws behind a card the
  server already wrote whole does not.

  **The arrangement is what re-reading it decided.** It is the newest fragment on the release page
  and the obvious candidate for a self-building element — a JSON attribute and a subtree, exactly
  what `<terminal-window>` does. It is server-rendered instead, because the list of sections is
  text, and the page had already spent this twice. `<release-arrangement>` follows `<release-list>`:
  a name, a guard, and nothing built.
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
| `SoundCloudPlayerAttribute`, `EmbedAttribute`, `TerminalAttribute`, `CoverArtAttribute`, `LinkAttribute`, `WaveformAttribute` | what it reads off an element |
| `WaveformBand` | the four byte offsets a waveform column is read at — the one **numeric** mirror, because the values are positions rather than names |
| `TerminalFieldKey` | the JSON keys a terminal row arrives under |
| `CssClass`, `ElementId`, `SectionKind`, `ArrangementAttribute` | what the stylesheet and the SPA router look for |
| `RequestHeader`, `RequestedWith` | the header that asks for a fragment, the one that revalidates, and `Range` — which no client code writes either; the browser sends it when an `<audio>` is seeked |

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
├── views/                    ← home.css, release.css, demo.css, stats.css (cf. src/NeuroSYS/View/)
└── elements/                 ← card.css, terminal.css, CoverArt.css, embed.css,
                                download.css, arrangement.css, waveform.css
                                                                        (cf. assets/ts/elements/)
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
directions by `HtmlTest`. That closes one unchecked mirror — a tag name in the CSS had nothing on
the other end of it, so renaming a case left the stylesheet quietly not matching, which on a dark
page reads as a layout bug rather than a typo.

**The attribute *values* it selects on were the other one, and they are a third copy.**
`arrangement.css` selects `[kind="drop"]` and `terminal.css` selects `[tone="error"]`, which are
`SectionKind` and `TerminalTone` backing values — pinned PHP↔TS case for case by
`enum-parity.test.mjs`, and in the stylesheet pinned by nothing at all. So a rename made on both
typed sides together passes every parity test there was and stops the CSS matching, and a drop that
draws in the default accent reads as a design decision. `HtmlTest` now asserts it both ways, with
the vocabularies declared in one constant so a *new* attribute-value selector fails until somebody
says where its values come from. The weaker direction has one deliberate exception, pinned the way
`card.css` is: `TerminalTone::Plain` has no rule, because the plain row is the absence of an accent
rather than a rule saying so.

`card.css` is the single part named for a concept rather than a component, because the catalogue
entry and the download entry genuinely share a look across `release/` and `download/`. It is meant to
be conspicuous the way `RawHtml` is: `HtmlTest` pins the list to that one file, so a second has to be
argued for in a test named for the fact. What falls out of it is worth reading — there is no
`elements/release.css`: the `release-*` tags are card, apart from `<release-arrangement>`, which is
its own component and lives in `arrangement.css` with the section tag it wraps.

Two things did not move to a runtime: **the CSS never arrives via JavaScript.** Shadow DOM or
`adoptedStyleSheets` would be the literal way to bundle a stylesheet with its element, and it would
cost a flash of unstyled content on every load and leave a no-JS visitor an unstyled page — spending
exactly the reserved-box guarantee the *Custom elements* section above is careful to keep. Colocation
is a property of the sources; the browser still gets one static file under a strict `style-src`.

## Adding a release

Edit `data/releases.php` — that's the only file. `php tools/stage-release.php <folder>` writes most
of the entry for you from a prepared release folder, and checks the folder is fit to upload first;
see `docs/authoring.md` for what it can and cannot derive. Add `--project <file>` to point it at the
`.flp` or the zip holding it, which is where bpm, key, genre, the arrangement and the time spent
then come from. Each entry is a typed `Release` object:

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
    // The last three come from the .flp, and all three are optional — an entry written before
    // tools/lib/Flp/ existed is still valid exactly as it stands.
    arrangement: new Arrangement(new Collection(Section::class)->with(
        Section::named('INTRO', 0),      // the label renders; SectionKind only picks the accent
        Section::named('DROP', 12288),   // a tick, at the project's ppq — not a time
    )),
    timeSpent: ProductionTime::of(60, 7),
    madeWith: new Collection(Plugin::class)->with(new Plugin('Serum 2')),
),
```

**`madeWith` is hand-authored, like `description`.** The tool emits a candidate list **commented
out**, because a hosted plugin's real name is buried in its wrapper blob at an offset that varies
per plugin — so the scan finds `Serum2` and `Xfer Records` alongside some wreckage, and a person
keeps the ones worth crediting. Nothing guessed reaches the page.

Omit a format entry to hide that download card; keep the entry but omit its `HiDriveLink` to render the card
in the "not uploaded yet" state, where clicking returns a 503 instead of redirecting.

**Never paste a full HiDrive URL.** `HiDriveLink` takes the 9-character share id and builds the direct-download
URL around it. It rejects anything that isn't 9 alphanumeric characters, so a truncated paste throws when the
data file loads rather than 404ing at HiDrive later. `cover` and every `Format` take the same `FileLink`
interface — another host means a new class implementing it, and no change to `Release`, `Format`,
`DownloadController` or `ReleaseView`.

**Never paste SoundCloud's embed HTML.** `SoundCloudEmbed` generates it — see `docs/releases.md` for where the
three ids come from, or let `php tools/release-track.php <folder> --upload` upload the track and print the
entry with them already in it. Player style and the six SoundCloud toggles are `SoundCloudPlayerStyle` /
`SoundCloudOption` enums with sensible defaults; a normal release never sets them. Adding another provider
means a new class implementing `Embed`, not a new field on `Release`.

## Language

The imprint and the privacy policy are the only bilingual pages here, and they always were — each
carries a German half and an English half, one after the other. What changed is that the order is
the visitor's rather than fixed: `Accept-Language` decides which half they meet first.

Four things are worth knowing before touching any of it.

- **Both halves are always sent. Only the order changes.** The German imprint is what discharges
  § 5 DDG and § 18 Abs. 2 MStV, so it is never the half left out; a wrong guess costs a visitor one
  scroll, where a wrong *omission* would cost rather more than that. `PageTest` asserts both halves
  are present under either language, which is the property worth pinning rather than the ordering.
- **Nothing here is translated.** The two halves were already written; the German titles
  (`Impressum`, `Datenschutzerklärung`) are words already in those documents. `AcceptedLanguages`
  chooses between things that exist and never invents one.
- **A page that reads a request header owes a `Vary` naming it**, and both facts are stated in one
  place so the second cannot be forgotten: `View::varyOn()` declares the headers, `ViewResponse`
  builds the header from it, and only those two pages are on the list — so nothing else pays for a
  dependency it does not have. Forget it and there is no error at all; a cache simply becomes free
  to hand one visitor the copy it built for another, which here means the wrong language and
  nothing else visibly wrong. The `ETag` is the belt to that brace, since the two orderings are
  different bytes. The verify script checks both, because only it can see a real header.
- **The default is the argument order, not a branch.** `preferred(Language::English,
  Language::German)` is an English page that will speak German if asked; a tie, a header naming
  neither, and no header at all all come back English. There is no 406 and there should not be:
  the question is only which half leads.

`AcceptedLanguages` parses RFC 9110 §12.5.4 — weights, `*`, `q=0` as a real refusal that outranks
the wildcard — and matches on the **primary subtag**, so `de-AT` and `de-DE` both want the German
half. Two ranges sharing a subtag keep the higher weight, which is what a client means by sending
both. Anything unreadable is skipped rather than rejected: this is the one header on the site whose
value is a browser setting, so it arrives however some client felt like writing it, and a malformed
entry means one preference cannot be honoured rather than that the page cannot be served.

`<html lang>` follows the leading half, and each half carries its own `lang` besides — so a screen
reader changes voice at the boundary instead of reading German aloud in English.

## Demos

Unreleased work at `/demos/{slug}`, behind a password minted per demo. It is the release side turned
inside out, and the inversion is the whole feature: a release is a public page pointing at a HiDrive
share URL, and **a demo is a private page whose audio is private too** — the files sit in
`data/demos/{slug}/`, outside the webroot, so `DemoAudioController` is the only route to them and it
asks for the password first. A share link outlives the password it was sent with; a demo route does
not.

```bash
php tools/stage-demo.php <file>…        # mints a password, transcodes, analyses, prints the entry
php tools/stage-demo.php --waveforms    # waveforms for demos already staged
php tools/stage-demo.php --rotate       # a new password for a demo already staged
```

Seven decisions are worth knowing before touching any of it. `docs/demos.md` is the workflow.

- **Nothing is discovered — every file on the page is named on the command line.**
  `~/Music/neuro.SYS/demos/` is a working directory: eight bounces of one bootleg, four release
  candidates, a mastering export and a zero-byte `alien house.flac`. No rule over it picks the two
  mixes worth sending, so the tool takes a list and derives only what a file can honestly say. That
  is the difference from `ReleaseFolder`, which *reads* a prepared directory with a convention
  behind it.
- **An unknown slug is refused exactly like a wrong password — in the status code and in the time.**
  A 404 for a slug that names nothing and a 401 for one that names something is a catalogue of
  unreleased tracks, readable one guess at a time. So `Auth::requireDemoAuth()` takes a
  **nullable** `Demo` and challenges either way. The timing half is not decoration: returning early
  on a null would answer in microseconds where a real comparison pays bcrypt, so the uniform 401
  would be undone by a stopwatch. It verifies against `PasswordHash::unmatchable()` — a real digest
  with no preimage — and throws the answer away. Same reasoning as `Auth::matches()`'s refusal to
  short-circuit, one level out.
- **There is no `/demos`, and `data/demos.php` is gitignored.** A listing would publish the names of
  unreleased tracks; so would a public repository holding the file that names them. It is still
  *deployed*, because `deploy.sh` rsyncs `data/` from the working tree without consulting git — the
  pairing is deliberate and is the opposite of `data/admin.php`, which is excluded from the rsync
  because the repo copy is a placeholder. `DemoRepository` is guarded like `ProfileRepository` for
  the same reason: every clone starts with no demos, and that has to be a working site rather than a
  fatal.
- **Only the hash is kept.** `Password::mint()` draws four groups of five Crockford base32
  characters from `random_int()` (100 bits, no `I`/`L`/`O`/`U` — it gets read off a screen and typed
  into a browser prompt) and prints the plaintext **once**, to stderr, so `> entry.php` cannot
  capture it. Nothing writes it down. Losing one means `--rotate`, which prints a replacement and
  the single entry line to swap — it touches no audio and loses no hand-written description.
- **Nothing builds a path out of a request.** The URL's last segment is matched against the labels
  the demo declares; a segment naming none is a null and a 404. `DemoTrack`'s own check on its file
  name is the *second* guard on that hazard, for a typo in `data/demos.php` rather than for anything
  a visitor can send.
- **The player is a native `<audio>`**, not a custom element, which is the one place the site
  deliberately breaks its own pattern. A release page's empty box with JS off is a cost CLAUDE.md
  accepts because the page is still a page; a demo *is* the audio. The browser's control also seeks,
  takes a keyboard and is announced by a screen reader — and seeking is why `FileResponse` answers
  ranges at all. A server that ignored `Range` would give a player that plays and will not skip,
  with nothing in any console.
- **The card is a deck screen, and the waveform behind it does not spend that exception.**
  `<demo-waveform>` prepends a canvas and leaves the server's children where they were, so with JS
  off the card is the card it always was — no empty box, because the picture is decoration and the
  player is not. The numbers are a `Waveform`: 512 columns of four bytes, written beside the audio
  at `data/demos/{slug}/{label}.wave` and inlined into an attribute, ~2.7 KB a mix. **The analysis
  is build-time and could not be anything else** — ten seconds of FFT per mix through the port in
  `tools/lib/Dsp/`, which is why the site only ever reads the file. A missing sidecar is a card
  without a picture rather than an error, the state every demo staged before the format existed is
  already in. Two measurements decided how it looks and both are written down where they are made:
  the height is an RMS **relative to the track's own loudest slice**, because a peak envelope is at
  or above full scale in 287 of 512 columns of a mastered bounce (see `WaveformColumn`); and the
  three colours are `Spectrum::bars()` at three bands, whose log spacing lands on 20–207 /
  207–2134 / 2134–22050 Hz without anybody choosing it.

**A demo is unreachable while the pre-launch site gate is on**, and this is a known interaction
rather than a bug: `Auth::requireSiteAuth()` runs on every request and both gates are HTTP Basic, so
a request carries one `Authorization` header and cannot satisfy two gates. Moot today — the gate is
switched off — and the verify script skips the demo HTTP checks with a printed SKIP when it sees
`data/site_auth.php`. If demos ever have to work behind it, the fix is a decision (the demo password
is the stronger credential, so the site gate could stand down for `/demos/`) rather than a patch.

```php
'wna-bootleg' => new Demo(
    title:       'Virtual Riot — We\'re Not Alone [neuro.SYS bootleg]',
    password:    new PasswordHash('$2y$12$…'),   // only ever the hash
    tracks: new Collection(DemoTrack::class)->with(
        new DemoTrack('v4', 'v4.mp3', 157),      // label, file, seconds — newest first
        new DemoTrack('v3', 'v3.mp3', 157),
    ),
    description: 'v4 is the current one, v3 has the old drop',
),
```

## The tooling

`tools/` holds five commands and two things that are not. `stage-release`, `stage-demo`,
`release-track`, `extract-midi` and `merge-coverage` implement `NeuroSYS\Tool\Cli\Command` — a name,
a usage line, the `Option`s it accepts, and a `run()` returning an `ExitCode`. `dev-router.php` and
`coverage-prepend.php` implement nothing, because PHP loads them itself: one is handed to `php -S`
and one is an `auto_prepend_file`, so neither has an argv or an exit code for an interface to
attach to. Each says so in its docblock.

```
tools/
├── autoload.php          ← NeuroSYS\Tool\ → tools/lib/
├── stage-release.php     ├── release-track.php    ├── merge-coverage.php   ← entry points
├── extract-midi.php      ├── stage-demo.php
└── lib/
    ├── Cli/              ← Command, Option, Input, Output, ExitCode, UsageException, Runner
    ├── Command/          ← the five commands, their option enums, and FolderReport — the report
    │                       the two that read a release folder share
    ├── Demo/             ← what puts unreleased work behind a password: Password, DemoSource,
    │                       DemoStage, DemoPreflight, DemoEntryWriter, Encoding, WaveformScan
    ├── Dsp/              ← c-µdsp in PHP: Fft, Analyze, Spectrum — the three modules the
    │                       demo waveform needs, ported with their tests
    ├── Export/           ← where a release's audio comes from: Exporter + PreparedExport and
    │                       FlStudioExport, RenderFormat, ExportedAudio/ExportSource
    ├── Flp/              ← the FL Studio project reader: FlpFile + EventId/EventWidth/Event,
    │                       Project, TimeMarker/MarkerType, ScaleNotation, KeyEstimate, Plugins
    │                       + the notes: Score, Note/PlacedNote, Pattern, Channel,
    │                       Playlist/PlaylistClip
    ├── Midi/             ← the standard MIDI file writer: MidiFile + MidiTrack/MidiNote,
    │                       TimeSignature, VariableLength
    ├── Http/             ← the only outbound requests this repo makes: Transport + CurlTransport,
    │                       Request/Response, FormField, FilePart, OutboundHeader
    ├── Php/              ← the expression tree EntryWriter emits through, so nothing builds
    │                       PHP source from a string: Expression, Value, Call, Argument, Entry
    ├── Release/          ← ReleaseFolder, Preflight, EntryWriter, ProjectFile, ReleasesFile
    │                       + the enums they read
    └── SoundCloud/       ← the upload client: Client, Credentials/CredentialVariable/Authorization/
                            AccessToken/OAuthCredential/TokenStore, TrackUpload/TrackField/
                            TrackSharing, UploadedTrack
```

**The build tools are the same layer in the other language.** `build-css.mjs`, `build-assets.mjs`
and `build-prod.mjs` share `tools/build-cli.mjs` — a `fail`, a `label`, and an argv parsed against
the flags a tool declares — which is `Cli/` on the other side of the boundary and is here for the
reason that layer exists. Each of the three used to hold its own `process.argv.indexOf('--out')`,
so `node tools/build-css.mjs --ou scratch.css` overwrote the committed stylesheet and reported
success: the exact failure `Command::options()`'s docblock describes, still live in the tools
nobody came back for. Every flag there takes a path, which is not a simplification but the whole
vocabulary — there is no `takesValue()` because nothing on that side stands alone. No dependencies
and nothing runs on import, so the stylesheet still rebuilds on a clone that has never seen
`npm install`.

**`FolderReport` is what `stage-release` and `release-track` have in common.** They are the same
command up to their last step — read a folder, judge it, say what is wrong with it, print an entry
— so four blocks were written twice and `reportFindings()` was byte-identical in both files. It is
a class rather than a trait on the test `Support\TypedItems` is on the other side of: that is a
trait because nothing anywhere holds "either kind of collection", while these two *are* both
`Command`s and `Runner` holds either one. What they share is not a kind of command, it is a report.
It is also the first thing to use `Option` as a *type* rather than as a list of cases — each
command declares its own `--project` on its own enum, and the interface is what says the two are
interchangeable there. What stays at the call sites is the sentence each command prints when a
check fails, because those differ and a `string $remedy` parameter would be the mistake `Attempt`
was written to end.

**`release-track` is `stage-release` with its last hole filled.** That command emits an entry whose
`embed:` argument is commented out, because the three SoundCloud ids do not exist until the track is
uploaded; this one uploads it and hands the resulting `SoundCloudEmbed` back to the same
`EntryWriter`, so the entry printed after an upload and the entry printed before one are the same
code with one argument different. Four decisions are worth knowing before running it:

- **It sends nothing without `--upload`.** Everything before that flag reads files on this machine;
  that flag is the step that puts one on somebody else's. Without it the command prints every
  multipart field it would send, by name, and stops.
- **Uploads are private and there is no flag that says otherwise.** Publishing is decided in
  SoundCloud's own interface on the day, which is the step `docs/releases.md` already describes.
  A `--public` would make publishing a typo away, and that mistake has an audience.
- **The credentials are environment variables**, never `data/`. `deploy.sh` rsyncs `data/` to
  Strato and keeps two files off it with an `--exclude` each — one line per secret, added by hand.
  The rotating OAuth token lives at `~/.config/neurosys/soundcloud.json`, outside the repo
  entirely, so no `.gitignore` entry and no rsync flag is what stands between it and a webroot.
- **The multipart field names are an enum and the OAuth parameter names are not**, and the rule is
  the one this codebase applies everywhere: a name is typed when getting it wrong is *silent*. An
  API drops a field it does not recognise, so `track[titel]` uploads the file and leaves an
  untitled track; a misspelled `code_challenge_method` is refused in words, in a browser, before
  anything is sent. `Client::authorized()` is where all three readings of `refresh_token` meet — a
  grant type, a request field name and a `TokenKey` — and a comment there says they coincide rather
  than repeat.
- **The keys a response is *read* under are enums too, for the stronger half of the same reason.**
  `TrackKey` and `TokenKey` name what comes back, where `TrackField` names what goes out — the same
  fact under the provider's two spellings (`track[permalink]` up, `permalink` down). This is the one
  place a misread name is silent *and* plausible: `permalink_url` misspelled reads as an empty
  string, and an empty string looks like a track that simply has no page. `UploadedTrack`'s docblock
  had said exactly that for as long as it read the five keys as literals. `TokenKey` holds both
  `expires_in` (SoundCloud's, a duration on the wire) and `expires_at` (ours, an instant in the
  store), because they are two forms of one fact and splitting them is how the store and the wire
  drift apart.
- **Nothing reaches into a decoded body by string.** `Response::json()` hands back a `JsonBody`,
  which takes a `BackedEnum` and nothing else — deliberately narrower than `FormField::of()`, since a
  field name is sometimes an OAuth parameter this repo leaves literal and a response key never is.
  Its two readers answer `''` and `0` for a key that is absent or wrongly typed, which is what the
  nine hand-written `is_string($body['x'] ?? null)` reads it replaced each did for themselves.
- **A request's headers are the site's own `Header`s**, so `Accept` is a `MimeType` that renders
  `application/json; charset=utf-8` and `Authorization` is an `OAuthCredential` — SoundCloud's
  scheme is `OAuth`, not `Bearer`, which is exactly the sort of thing that reads as a typo and so is
  written down once in a class named for it. Its body is a `Collection<FormField>`, checked at the
  boundary the way every other collection here is — including the token exchange, which took an
  `array<string, string>` and looped it into one inside `Client`.
- **Its target is a `Url`**, absolute and https, parsed by `ext/uri` rather than matched by a
  pattern. `Endpoint::url()` and `Endpoint::track()` build them, and `Request` takes nothing else.
  The site checks every address it emits — `Location`, `CspHost`, `Element`'s scheme allowlist — and
  this was the one with nothing looking at it, on the one request that carries a client secret and a
  rotating refresh token. It is not `Location`: that is a header the *site* sends and it lives under
  `src/`, which `deploy.sh` uploads.
- **`Attempt` names what the client was doing when the API said no.** Four operations, each backed
  by the phrase its failure message reads it back as. It was a `string $what` threaded through two
  private methods, and `SoundCloudException::refused()` had to describe the format it wanted in
  prose — which is what a missing type looks like.

**`stage-demo` is the one command that mints a credential, and the one that writes audio.**
`stage-release` only ever prints; this transcodes each named master into `data/demos/{slug}/` — which
is why it calls `Directory::create()` out loud rather than letting a `File` quietly make its own
parent, the rule `Support\File` states in the negative. Three things it does differently from every
other command here, each argued for in [Demos](#demos): it takes **many** positional arguments
because the mixes on a page are chosen rather than discovered; `--check` returns **before the
password is minted**, so a run made only to read the report cannot leave a real password on screen
that no entry matches; and `--rotate` takes **no** files at all, because changing a password is one
line of an entry and restaging would rewrite every file and lose the description. `--waveforms` is
the fourth, and it is `--rotate`'s shape rather than a staging run's: it reads the operands as
**slugs**, analyses audio that is already staged, and touches no password, no entry and no audio.

It shares `Finding` and `Level` with the release preflight and nothing else. Not `FolderReport`:
that class is built around a `ReleaseFolder` — one path, checked to be a directory, with imports
reconciled against `data/releases.php` — and none of those three is true here. What is left in
common is one `foreach` printing a level and a message, and a shared parent for that would announce
a kind these two are not.

One check in it is worth knowing because the obvious version does not work: **`ffprobe` exits 0 on a
text file named `bounce v3.flac`.** It takes the codec from the extension, reports `flac`, and
answers `N/A` for everything it would have had to decode to know — so `Probe::stream()` hands back a
well-formed `AudioStream` describing nothing, and the demo stages "successfully" with a player that
will not start. `DemoSource::isReadable()` asks for a **sample rate**, which is the thing a name
cannot supply.

**`tools/lib/Dsp/` is a port rather than a design**, which is why it is the one directory here whose
layout was decided somewhere else. `Fft`, `Analyze` and `Spectrum` are `c-µdsp`'s `fft.c`,
`analyze.c` and `spectrum.c` transliterated, with that library's own tests ported beside them in
`DspTest` — known input, known output, a 1 kHz tone pinned to the same bar index it lands in over
there. Three deviations and no more, all stated on `Fft`: a `float` is a double, a length argument
is gone wherever the array already carries it, and two functions return where the C wrote into a
caller's scratch buffer, because PHP has no scratch buffer to own. **The rule that made the C
portable is kept**: nothing under `Dsp/` opens a file or decides a column count. `WaveformScan` is
the consumer, exactly as `ctui-mus`'s `audiovis-core` is over there.

The three modules with no caller here — `loudness.c`, `scope.c`, `spectrogram.c` — stay in C. The
verify script asserts every class under `tools/lib/` loads, so a port nothing calls would arrive
carrying an assertion about itself and nothing else.

**The decode is `Probe::decode()`, and it cannot be `Probe::run()`.** That one is `exec()`, which
splits stdout into lines; float bytes contain newlines as often as any other byte. `popen()` keeps
the stream whole and still hands back an exit status. What comes back is one **string** rather than
an array of samples, which is the constraint the whole design turned on: a three-minute track is 6.9
million samples, roughly 550 MB as a PHP array and 28 MB as bytes. Reading one window at a time out
of it is also precisely the contract `c-µdsp` states — the caller owns the buffer and supplies a
window.

**`extract-midi` reads the notes, which is the half of the project the rest of the reader skips.**
`Project` answers what a release entry needs; `Score` answers what a MIDI file needs — the channels,
the patterns, and the playlist that says which pattern plays where. `php tools/extract-midi.php
<folder|.flp|.zip>` writes the playlist-expanded arrangement, one track per rack channel named for
it, and `--patterns` writes every pattern instead, at the ticks the pattern itself holds. Written
because a remix package wants MIDI and the only way to get it was FL's own export dialog in the
Windows VM — which is why `hello world!`'s package has one and `ill`'s does not.

**It was built against that export rather than against a specification**, which is the only reason
its rules can be stated as measurements. Both were run over two projects and diffed note for note:
7,522 of 7,564 notes identical on one and 3,975 of 3,977 on the other, with every note's position,
pitch, velocity and channel grouping agreeing. Four things are worth knowing before touching it:

- **A playlist clip's width is version-dependent and the file does not state it** — 32 bytes in
  FL 12.4, 80 in FL 25 and 26. Read at the wrong width the arrangement is not an error, it is the
  wrong music, so `Playlist` probes it against a canary the format hands over: a clip's `u16` at
  offset 4 is 20480 in every project tested across both. The smallest width that divides the event
  *and* holds the canary at every clip wins, and a playlist that matches none comes back null
  rather than half-read. Same shape of trap as `EventWidth::NARROW_DWORD`, and the same fix.
- **A length of zero is normal, and a note of zero length is not.** 1,398 notes of one project —
  every hat and every foley hit — carry no length, because FL plays a sample for as long as the
  sample lasts. Written literally they are all silent. `Score::sounded()` gives each the gap to the
  next note on its channel, or the rest of its clip when it is the last; that rule was recovered
  from FL's export and agreed on all 1,398.
- **A note ending exactly on a clip boundary keeps its full length**, where FL shortens some by a
  tick and not others. 51 notes of the 7,564 end on a boundary, FL shortens 42 and leaves 9, and no
  rule separates them. Writing the project's own length is the deliberate choice: a rule wrong nine
  times in fifty-one is worse than none, and a tick at 96 ppq is a thousandth of a bar.
- **Two tracks are written that FL omits**, on the second project. One has clips whose gain field
  reads `0.0` where every other clip reads `1.0`; the other does not and is dropped anyway. Two
  behaviours and one guess, so no rule is written — a remixer can delete a track and cannot recover
  one that was never there.

**The audio is a port, and the FL Studio half of it deliberately refuses.** `Exporter` has two
implementations: `PreparedExport`, which hands back a file already in the release folder — which is
how every release so far was actually made — and `FlStudioExport`, which throws. FL runs in a
**Windows VM**, so rendering is not a process to start but a message to another computer, and which
mechanism (a guest agent, SSH into the guest, a watched shared folder, the hypervisor's guest-exec
channel) is undecided. The half that *is* settled is written down and tested: `FlStudioExport::commandLine()`
builds the `FL64.exe /R /E<format>` invocation from Image-Line's manual. Three constraints are why
none of the four options is obviously right, and they are listed on the class: a `.flp` references
its samples by absolute path so it has to render where it lives, the render uses the project's own
saved export settings, and FL opens its interface during a command-line render. The wine prefix on
this machine is **not** a shortcut — it is not the install the projects were made in, and a render
out of it that looked like it worked would be the worst available outcome.

**A second autoloader, and it is not optional.** The site's maps `NeuroSYS\` to `src/NeuroSYS/`, and
`deploy.sh` uploads `src/` with `--delete` — so a tooling class under it would ship to Strato and
join `phpunit.xml.dist`'s coverage source. Composer's `autoload-dev` was the other candidate and was
turned down for the reason `autoload.php` exists at all: `stage-release` runs on a clone that has
never seen `composer install`. (`merge-coverage` does need `vendor/`, for the coverage library.)

`release-track` needs one thing the site does not: **`ext/curl`**. It is not in `composer.json`,
because that file states what the *site* requires and the site makes no outbound request at all —
a property the verify script now asserts, alongside the one that says curl is called in exactly one
class, the way `Probe` is the one class that shells out.

That autoloader is also what made the typed design affordable. `phpcs` holds `tools/` to PSR-12,
where a class-like symbol needs a namespace *and* a file of its own — which is why this started as
namespaced functions with documented array shapes, and why one-class-per-file stopped being a cost
the moment there was a set of commands to share the loader.

**`Command::options()` is not decoration.** It is what lets `Input` refuse a flag the command never
declared. Both hand-rolled parsers this replaced dropped an unrecognised flag in silence, which for
`merge-coverage` meant a mistyped `--clover` reported success and wrote no report. `getopt()` is
still not the answer, for the reason the old code gave when it declined it: `getopt()` stops at the
first non-option argument, and `composer coverage` passes both of its paths first.

The verify script asserts every class under `tools/lib/` loads, the way it already does for `src/`.
Nothing else reaches them — the CLI layer is outside the coverage source and the commands are run by
hand — so a namespace disagreeing with its path would otherwise surface the first time someone ran
the tool.

## Deployment

`public/` maps to Strato's `htdocs/` (web-exposed). `data/` lives **outside** the webroot — it's uploaded separately and never via the standard deployment mapping.

- Regular deploy: `./deploy.sh` (rsync over the mounted SFTP). It runs `npm run build:prod` first,
  so the tree it uploads is always current — see [Debug and prod builds](#debug-and-prod-builds).
- **PHPStorm's right-click `public/` → Deployment → Upload to Strato uploads the debug tree**, maps
  and all, under a manifest stamped for different bytes. It still *works* — the version segment is
  stripped rather than resolved, so nothing 404s — but it undoes both halves of the prod build
  silently. Use it to push one file in a hurry, not to deploy.
- `deploy.sh` ships `build/dist/public/`, `src/`, `autoload.php` and `data/`, then overlays
  `build/dist/src/NeuroSYS/AssetManifest.php` — the one file in `src/` that differs between the two
  trees. It **excludes `data/admin.php` and `data/site_auth.php`**, because the repo copies are
  placeholders and syncing them would overwrite the live credentials. Upload those two by hand when
  they change.
- **`data/demos.php` and `data/demos/` are the opposite arrangement, and deliberately so.** They are
  gitignored — `origin` is a public GitHub repository and they name unreleased tracks — and *not*
  excluded from the rsync, because rsync reads the working tree rather than git. So they never reach
  GitHub and always reach Strato.
- **`--delete` is on for `public/` and `src/` and deliberately off for `data/`**, which is the one
  asymmetry in the script and the one worth knowing before trusting it. The two trees it deletes
  from are wholly generated or wholly committed, so the working tree is authoritative about what
  should be there. `data/` is not: `demos.php` and `demos/` are **gitignored**, so a clone that has
  never staged a demo has neither, and a deploy from that machine with `--delete` on would take
  every demo off the live server — the exact files whose absence from git is the point. The price is
  that **deleting a demo locally does not delete it live**: the entry goes, so the page and the
  audio route both 401 like a slug that never existed, and the MP3s stay on the mount until somebody
  removes them by hand. See `docs/demos.md`.
- `data/admin.php` holds bcrypt credentials for `/admin/stats`; generate with `php -r "echo password_hash('pw', PASSWORD_BCRYPT);"`

Footer profile links come from `data/profiles.php` — an empty URL hides that link. Brand icons are **vendored** under
`public/assets/img/brand/`, never hot-linked from a platform CDN; see `docs/branding.md` for why and for each platform's
usage rules.

### What `.htaccess` does to a response

Beyond the `SetHandler` allow-list and the HTTPS redirect, `public/.htaccess` shapes every static
response.

**Measured on the live host 2026-09-06, and it is not what the same measurement said the day
before.** Both blocks are working now: a stamped module comes back `content-encoding: gzip` with
`cache-control: public, max-age=31536000, immutable`, and a bare `/assets/js/main.js` comes back
gzipped with `max-age=3600` — so `mod_deflate` is present and the two cache tiers are genuinely
mutually exclusive rather than merely written to be. Strato adds a `Vary: X-Forwarded-For` of its
own, which `Accept-Encoding` is appended to.

On 2026-09-05 the answer was the opposite: **nothing** was compressed, `main.js` arrived
byte-identical to the file on disk with only an `ETag` and a `Last-Modified`, and no `Cache-Control`
came back at all. Nothing in this repository changed between the two readings. That is the whole
argument for the paragraph below rather than a curiosity — a shared host can gain or lose a module
without telling anybody, and the failure is silent in both directions.

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

That URL is the calendar tier and nothing the site emits asks for it. The one a page actually loads
carries the build stamp, so check that tier too — it is the one the year-long `immutable` is on:

```bash
curl -s https://neurosys.gg/ | grep -oE '/assets/js/v-[^"]+/main\.js' | head -1 \
  | xargs -I{} curl -sI -H 'Accept-Encoding: gzip' "https://neurosys.gg{}" | grep -i 'encoding\|cache'
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
`docs/demos.md` for putting unreleased work behind a per-demo password,
`docs/branding.md` for brand assets and profile links, `docs/testing.md` for the two test suites, and
`docs/security.md` for the security posture and the assessment findings.
