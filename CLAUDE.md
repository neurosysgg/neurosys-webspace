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
response here does. `tools/merge-coverage.php` unions the two into `build/coverage/`. **98.3% of
lines**; of the twenty-four that are left, fourteen are deliberate and named in `docs/testing.md`.
The other ten are a gap rather than a decision — guard-clause `throw`s on the header-value classes
`867372f` added, which nothing has exercised yet. The demo work added two of the same kind and
closed both, so the pattern for closing the rest is written down in `DemoTest`.

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
│                     and the two header-name enums
│   └── Security/   ← ContentSecurityPolicy, PermissionsPolicy + the enums they compose
├── Model/          ← Release, Format, Demo, DemoTrack, Profile, MusicalKey, Genre, ReleaseFormat,
│                     Platform, Waveform + WaveformColumn/WaveformBand (typed value objects + enums)
│   ├── Production/ ← what the .flp knows: Arrangement + Section + SectionKind, ProductionTime, Plugin
│   ├── Embed/      ← Embed interface + SoundCloudEmbed (one track) + SoundCloudProfileEmbed
│   │                 (the whole account); each renders its element from typed params
│   └── Link/       ← FileLink interface + HiDriveLink; generates share URLs from a share id
├── Service/        ← Auth, DownloadLogger, DownloadLogEntry, DownloadStats, ReleaseRepository,
│                     ProfileRepository, DemoRepository, WaveformRepository
├── Support/        ← Collection<T>, SearchableCollection<T> (both immutable) + the TypedItems trait
│                     they share, File + Directory, Route, RouteInitialization, JsonDeserializable,
│                     Charset, PasswordHash
├── View/           ← View abstract base + one concrete per page; each returns a Node, not a string
│   ├── Html/       ← the markup tree: Node, Element, Attribute, Text, RawHtml, Fragment, Document,
│   │                 Doctype + Tag/HtmlTag, the attribute-name enums (WaveformAttribute among
│   │                 them), and the attribute-value
│   │                 enums LinkRel / LinkTarget / ScriptType
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
header. These are server-only, so they have no TypeScript mirror and none is wanted.

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

What stayed behind in each class is what genuinely differs — `with()`, `find()`, `all()`
/`getIterator()`, whose bodies are identical but whose return types are `list<T>` against
`array<string, T>`, and `rebuilt()`, which is the trait's one abstract member. That difference is the
reason there are two classes at all.

**The trait also holds the six query methods, and they are the default way this codebase handles a
group of things:** `where()`, `map()`, `join()`, `first()`, `keys()`, `isEmpty()`. They were written
because `all()` had quietly become the escape hatch *out* of the type — sixteen call sites reached
for it or hand-rolled a `foreach`, and nine of those unwrapped the collection for no purpose but to
hand the array to `array_map`. A collection that must be unwrapped before it can be asked anything
only types its own construction.

Three decisions are worth knowing before adding a seventh:

- **The callback takes the value first and the key second.** That is the order PHP's own
  `array_find`, `array_any` and `array_all` use — `Element::renderChildren()` already calls one —
  and the order `ARRAY_FILTER_USE_BOTH` passes. It is also what keeps a one-argument callback a
  first-class callable, since PHP hands a userland callback extra arguments harmlessly:
  `$links->map(self::profileLink(...))` needs no closure around it. Key-first would have broken
  every such site, and `ReleasesView::card()` had its own parameters swapped to match rather than
  become the exception.
- **`map()` answers with a `list`, not a collection.** A collection is defined by a `class-string`,
  and most call sites map to a `string` or an `array` — neither is a class, and every one of them
  spreads into `containing(...)` or joins. So `where()` returns `static` and chains; `map()` and
  `join()` end the chain.
- **`rebuilt()` is abstract because `where()` cannot decide for both.** A `Collection` is a `list<T>`
  and `array_filter` preserves keys, so it reindexes; a `SearchableCollection` keeps them, which is
  what it is for.

All six carry `#[\NoDiscard]` — they are pure, so a dropped result is never anything but a bug — and
`NoDiscardTest` pins them three times each, since PHP reports a trait's members on both using classes
*and* on the trait.

**What deliberately stays a plain array.** `Preflight`'s findings, `ReleaseFolder::missing()`'s
filter over `Fact::cases()`, `FlpFile::all()` — none crosses a public boundary, and the rule below
already says a collection does not replace a variadic. PHP's own `array_find`/`array_any`/`array_all`
are the API there. `CurlTransport::parts()` keeps its `foreach` for a different reason: it rekeys by
field name, which is not a `map`.

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

**The demo routes are the one place a file passes through PHP**, and that is the whole point of
them: `/demos/{slug}` and `/demos/{slug}/{label}` sit behind that demo's own password, and the audio
lives under `data/` where the web server cannot reach it, so the password covers the bytes rather
than only the page. `FileResponse` answers byte ranges because an `<audio>` element seeks by asking
for one. See [Demos](#demos).

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
directions by `HtmlTest`. That closes the last unchecked mirror on the site — a tag name in the CSS
had nothing on the other end of it, so renaming a case left the stylesheet quietly not matching,
which on a dark page reads as a layout bug rather than a typo.

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
  GitHub and always reach Strato. `--delete` is on, so removing a demo locally removes it live.
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
`docs/demos.md` for putting unreleased work behind a per-demo password,
`docs/branding.md` for brand assets and profile links, `docs/testing.md` for the two test suites, and
`docs/security.md` for the security posture and the assessment findings.
