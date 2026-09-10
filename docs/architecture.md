# Architecture — the PHP side

How `src/NeuroSYS/` is arranged, what each layer is allowed to know, and what to do when you need to
add something. The front end has its own doc ([frontend.md](frontend.md)), and the facts the two
sides state twice have a third ([contracts.md](contracts.md)).

Two companions go deeper than this map: [collections.md](collections.md) for the only shape a group
of values takes, and [guidelines.md](guidelines.md) for the five habits `GuidelineTest` keeps out.
The response policies and the hardening of the request are argued in [security.md](security.md).
How any of it got this way is in [history/](history/README.md).

---

## The shape in one screen

```
request
  │
  ├─ public/.htaccess          rewrite everything to index.php; http:// → https://
  │
  └─ public/index.php          in this order:
       │
       ├─ set_exception_handler(…)     ⓪ the last resort, for when nothing else worked
       ├─ SecurityHeaders::send()      ① headers first, so they cover every exit below
       ├─ Request::fromGlobals()       ② $_SERVER → a typed, readonly Request
       ├─ Auth::requireSiteAuth()      ③ pre-launch gate; may exit 401
       ├─ Router::dispatch()           ④ URL → Controller, then the method gate → Response
       └─ Response::send()             ⑤ headers + body, or a redirect, or plain text
```

Everything below the front controller is plain classes. There is no framework, no container, no
config file that wires anything up: a controller constructs what it needs, and the one thing
assembled up front is the route table.

```
src/NeuroSYS/
├── Http/           the wire — what arrived, what goes back, and every header on it
│   ├── Api/        what an address under /api is made of
│   └── Security/   the response security policies, as typed objects
├── Controller/     one class per route group; fetches data, returns a Response
├── Service/        the things that talk to the outside — data files, credentials, the API gate
│   └── Api/        one handler per API action
├── Model/          the domain: releases, demos, and everything they are made of
│   ├── Embed/      third-party players
│   ├── Link/       off-site files
│   ├── Production/ what the .flp knows: arrangement, time spent, plugins
│   ├── Api/        what a signed call is made of
│   ├── Update/     what a push adds to that
│   └── Health/     what a deployment can say about itself
├── View/           one class per page; each returns a Node, never a string
│   ├── Html/       the markup tree
│   └── Terminal/   the terminal component's declared form
├── Support/        the shapes everything else is built out of
├── Exception/      every condition the site can be in, by name
├── Config.php      the facts about this site
├── DataFile.php    every file the site reads out of data/, named rather than spelled
├── Layout.php      the shell every full page is wrapped in
└── Router.php      URL → Controller, and nothing else
```

`CLAUDE.md` carries the same tree one level deeper, class by class.

### Which way the dependencies point

```
Controller ──→ Service ──→ Model ──→ Support
     │                       │
     └────────→ View ────────┘
                 │
                 └──→ View\Html
```

Two rules hold the arrows straight, and both are worth knowing before you add a `use` statement:

- **`View\Html` depends on nothing above it.** It is a markup library that happens to live here. It
  knows about `Support\Charset` and `Support\UrlScheme` and no other part of the application.
- **`Model` never renders itself into markup — but it does build elements.** `SoundCloudEmbed`
  returns an `Element`, which looks like a violation and is not: it emits *one custom tag with
  typed attributes*, and the tag's contents are built client-side. The model declares; the view
  arranges; `View\Html` renders.

---

## The request, traced

Follow one request all the way through. Everything below happens for `GET /releases/ill`.

### ⓪ The last-resort handler

**It is first because it is the one that has to work when nothing else did.** Without it an uncaught
throwable is a PHP fatal, which on a host whose `display_errors` we do not own is either a blank page
with a 200 already on the wire or a stack trace naming absolute paths — a choice left to a php.ini
rather than made here. It logs the fault, sends a 500 if `headers_sent()` says there is still a
response to shape, and writes a body of exactly `500`. It depends on nothing: no `Response`, no
`MimeType`, no view, because reaching for the markup tree would be reaching for the most likely
thing to have just broken, and a throw inside an exception handler is a fatal with the original
swallowed. The one type it does name is `SiteException`, to say in the log whether the fault came
from this repository — and that is free, see [Exceptions](#exceptions).

### ① Security headers, before anything can fail

[`SecurityHeaders::send()`](../src/NeuroSYS/Http/SecurityHeaders.php) runs *before* the request is
even parsed. That ordering is the whole design: the 401 that `Auth` exits with, the 405 the router
refuses a POST with, and the 303 a download redirects with all get the full header set, because none
of them can run before this line.

It also *removes* one header — `X-Powered-By`, which PHP appends with its exact patch version before
any of our code runs. See [security.md](security.md) for the policies themselves.

### ② `$_SERVER` becomes a `Request`

[`Request::fromGlobals()`](../src/NeuroSYS/Http/Request.php) is the only place a request is built
from the superglobals. What comes out is `readonly` and typed, and three of its decisions are
deliberate:

| Member | Decision |
|---|---|
| `method()` | `HttpMethod::tryFrom()` — **nullable**. An unrecognised verb is `null`, and null is not read-only. Never guessed as GET. |
| `path()` | Parsed with `Uri\Rfc3986\Uri::parse()`, which returns `null` on failure — so `??` is a real guard, where `parse_url()`'s `false` would not be. A target that will not parse comes back as **its own path**, everything up to the first `?` or `#`. That still matches a placeholder route, because `{param}` compiles to `([^/]+)`; see [security.md](security.md). |
| `authUser()` / `authPassword()` | Read from `PHP_AUTH_*`, falling back to decoding `Authorization` — Strato does not always hand PHP the former. See [deployment.md](deployment.md). |

The path is **raw, not decoded**: a route matches the target as it was sent.

**The `$_SERVER` keys a request is built from are `ServerVariable` cases**, because every reader of
that superglobal ends in a default — `?? 'GET'`, `?? '/'`, `?? ''` — which is exactly what makes a
misspelled key indistinguishable from a request that did not carry the value. `PHP_AUTH_USER` is the
one that matters: a typo there leaves the user `''`, which no stored credential equals, so both
gates refuse everything with a 401 that reads as a wrong password.

Not every key belongs on it, and the rule is whose name it is. A request header arrives under
`HTTP_` plus the name upper-cased with dashes as underscores — PHP's transform, so
`Request::header()` applies it to a `RequestHeader` case rather than anybody retyping the result. A
case earns a place on `ServerVariable` when that derivation cannot reach the name
(`REDIRECT_HTTP_AUTHORIZATION` is Apache's invention, not HTTP's) or when the reader has no
`Request` to ask — which is `HTTP_REFERER`, deliberately: `DownloadLogger` must read it *behind* the
`DOWNLOAD_LOGGING` guard, and an argument would be evaluated in front of it. Note the spelling. The
header lost an `r` in 1996 and the property it fills, `$referrer`, did not.

### ③ The pre-launch gate

[`Auth::requireSiteAuth()`](../src/NeuroSYS/Service/Auth.php) checks for `data/site_auth.php`. If
the file is absent it returns immediately — *that absence is how the gate is switched off*, and the
file is gitignored precisely so the repo copy cannot switch it on. It is also why a misspelled
`DataFile` case there would not fail but stand the gate down; see [Data files](#data-files).

### ④ Routing

[`Router::dispatch()`](../src/NeuroSYS/Router.php) does two things, in order:

1. **The match.** Each [`Route`](../src/NeuroSYS/Support/Route.php) is a
   [`SitePath`](../src/NeuroSYS/Support/SitePath.php) case, a factory closure and a
   [`MethodPolicy`](../src/NeuroSYS/Support/MethodPolicy.php). `{param}` compiles to `([^/]+)`, and
   captures are passed positionally to the factory.
2. **The method gate**, asked of the matched route rather than globally. Nine routes are
   `ReadOnly` and answer anything but `GET`/`HEAD` with a 405 whose `Allow` comes from
   `Allow::readOnly()` — derived by filtering the cases, so the header cannot advertise something
   the gate does not do. `/api` is `Delegated`: the router forms no opinion and its controller
   answers every method itself, because any opinion the router formed would tell an unsigned caller
   the address is real. Two policies rather than a set of methods per route, because a route naming
   its own set would make its 405 name `POST`.

An unmatched path falls through to
[`UnroutedController`](../src/NeuroSYS/Controller/UnroutedController.php), which gives the 404 for a
read verb and the 405 for a write one — and is the same object `ApiController` delegates to, so
that no address under `/api` and an address that does not exist can answer differently.

The route table is built in
[`RouteInitialization::routes()`](../src/NeuroSYS/Support/RouteInitialization.php) — ten entries,
one per `SitePath` case, in match order.

**Every address the site has is a `SitePath` case, and that is one vocabulary rather than two.** A
view naming a path the router does not have would render a link that looks perfectly fine and answers
with the site's own 404, so views never concatenate a path: the case's *value* is the pattern,
placeholders and all, so `Route::matches()` matches with it and `SitePath::to(...)` fills it in; the
placeholder syntax is one constant both read. `to()` refuses the wrong number of values, which is the
check a concatenation cannot make — `'/releases/' . $slug . '/'` is a perfectly good string and a URL
that matches nothing. Two details worth knowing: each value is `rawurlencode`d, a no-op for every
slug, format and label in `data/` today; and the callback that fills them **must** be a `function`
with `use (&$values)`, because `fn()` captures by value and every placeholder would take the first
value — `/releases/ill/ill`, well formed, matching a route, and the wrong page. `RoutingTest` pins
that one by name.

**The tests keep writing paths out in full, deliberately.** A test asking
`SitePath::Release->to('ill')` would pass with the enum wrong. Same reason the verify script curls
real URLs.

### ⑤ The controller, the view, the response

A controller fetches its own data. Nothing is injected for it, and there is no shared context object
— [`ReleaseController`](../src/NeuroSYS/Controller/ReleaseController.php) constructs a
`ReleaseRepository`, asks it for a slug, and returns either a `ViewResponse` wrapping `ReleaseView`
or one wrapping `NotFoundView` with a 404.

The optional `?ReleaseRepository $releases = null` constructor parameter on the release and download
controllers is a **test seam and nothing else** — it is how the "format staged, no link yet" branch
gets exercised without a real release.

Then [`ViewResponse::send()`](../src/NeuroSYS/Http/ViewResponse.php) makes the one branch that
matters:

- **full page** → `Layout::wrap($view)` → a `Document`
- **AJAX fragment** → a `Fragment` of `<title>…</title>` plus the view's content

Both send `Content-Type: text/html; charset=utf-8` explicitly. The fragment is why: a page carries
`<meta charset>`, a fragment carries nothing, so the header is all the browser has.

Download routes (`/releases/{slug}/{format}`) call `DownloadLogger` and answer with a 303 to the
HiDrive direct-download link. **The demo routes are the one place a file passes through PHP**:
`/demos/{slug}` and `/demos/{slug}/{label}` sit behind that demo's own password, and the audio lives
under `data/` where the web server cannot reach it, so the password covers the bytes rather than only
the page. `FileResponse` answers byte ranges because an `<audio>` element seeks by asking for one.
See [demos.md](demos.md).

---

## The layers

### `Http/` — the wire

Everything about a request or a response is a typed value here, not a string.

| Type | Is |
|---|---|
| `Request` | readonly, built once from `$_SERVER` |
| `Response` | an interface with one method: `send(Request): void` |
| `ViewResponse` | renders a `View`; the only response that returns rather than exiting |
| `RedirectResponse` | `Location` + status, then `exit` |
| `PlainTextResponse` | body + status + extra headers, then `exit` |
| `FileResponse` | a file under `data/`, whole or as a byte range — the demo audio |
| `Header` | a `HeaderName` and a `HeaderValue`; formats `Name: value` in one place |
| `MimeType` | a `TopLevelType`, a validated subtype, and a `Charset` |
| `HttpStatusCode` | every status code, backed by its number |
| `HttpMethod` | the eight methods, and which of them only read |

Most responses `exit` and one does not. That is not an inconsistency: a redirect and a plain-text
refusal are terminal, and a `ViewResponse` returns so that `echo` is the last thing that happens.
The consequence is that **PHPUnit cannot observe the exiting ones**, which is why the verify script
exists — see [testing.md](testing.md).

**Header names live in two enums on purpose.** `SecurityHeader` is exhaustive and tested as such —
`SecurityHeaders::headers()` sends exactly its cases. `ResponseHeader` is everything else. Folding
them together would make the exhaustiveness assertion meaningless. `RequestHeader` is the inbound
direction; all three implement `HeaderName`, so `Header` formats any of them.

**Header *values* are typed too, because a header value has a grammar** — a quoted `ETag`, a
comma-separated `Allow`, `Basic realm="…"`, `max-age=…; includeSubDomains`, `no-store, private` —
and a `new Header(…)` call site is the one place a grammar cannot be checked. `HeaderValue` is one
method, `render()`, and its implementations are the objects that know each header's grammar:
`ContentSecurityPolicy`, `PermissionsPolicy`, `StrictTransportSecurity`, `ReferrerPolicy`,
`ContentTypeOptions` and `MimeType`, plus `CacheControl`, `ETag`, `Vary`, `Allow`, `BasicChallenge`
and `Location`. `Header` accepts nothing else, so a value cannot be assembled as a string at the call
site. `Location` accepts only an absolute `https://` URL, the same shape `Profile` demands.
`SecurityPolicyTest` pins the set in both directions — every implementer must be rendered by its
table, and the table must name every implementer.

**`Content-Type` is a `MimeType`**, and not a string with `; charset=utf-8` stapled onto it. A class
rather than an enum for the reason `StrictTransportSecurity` is one: the value carries a parameter,
and a case cannot hold one. The two the site sends are `MimeType::html()` and `MimeType::plainText()`,
so no call site types a subtype, and a malformed one throws where it is written the way `CspHost`'s
origin does. The charset is the half that earns the class — `nosniff` stops a browser guessing the
type, and nothing stops it guessing the encoding.

### `Controller/` — one class per route group

A controller is thin by construction: fetch, decide, return. The only one with real logic is
[`StatsController`](../src/NeuroSYS/Controller/StatsController.php), which parses the download log —
and that log is not written, because logging is off. See
[Download logging](README.md#download-logging).

Every controller implements `Controller::handle(Request): Response`. There is no base class, because
there is nothing to share.

### `Service/` — the outside world

| Class | Talks to |
|---|---|
| `ReleaseRepository` | `data/releases.php` — lazily loaded, slug-keyed |
| `ProfileRepository` | `data/profiles.php` — skips platforms with an empty URL |
| `DemoRepository` | `data/demos.php` — gitignored, so every clone starts with none |
| `WaveformRepository` | `data/demos/{slug}/{label}.wave` — a missing sidecar is a card without a picture |
| `Auth` | `data/site_auth.php`, `data/admin.php`, and each demo's password hash |
| `DownloadLogger` | `data/logs/downloads.log` — returns before doing anything, see below |
| `ApiGate`, `UpdateApplier` | every check a signed call passes, and the writing a push does — see [security.md](security.md) |

The repositories load lazily and cache, and take an optional path so a test can point them somewhere
else. Each reads a PHP file that `return`s typed objects — there is no parser, no schema, no
serialisation format. A bad release is a `ReleaseVerificationException` thrown while the file loads.

**`Auth::accepts()` is public and returns a bool; the 401 is separate.** A method that ends the
request cannot be asserted against, so the decision lives beside the challenge rather than inside
it. Both credential comparisons run every time and are combined afterwards — chaining them with
`&&` would leak which half was wrong through timing, since bcrypt is deliberately slow and
`hash_equals()` is not.

**Download logging is deliberately off, for legal reasons.** `Config::DOWNLOAD_LOGGING` is `false`,
and `log()` returns on it before the `DownloadLogEntry` is built — so the referrer is never read and
nothing is written. `StatsController` skips reading the log entirely and `/admin/stats` says logging
is switched off rather than showing an empty table. Both suites assert the switch stays off, and the
unit test additionally asserts the referrer is never read. Turning it on is a privacy-policy decision
before a code one — the policy makes no download-tracking claim in either language — and
`data/logs/` must exist on the server first: `fopen(…, 'ab')` creates the file but not its directory,
and `deploy.sh` excludes it.

### `Model/` — the domain

Typed value objects and enums: `Release`, `Format`, `Demo`, `DemoTrack`, `Profile`, `Waveform` with
`WaveformColumn` and `WaveformBand`, and the enums `MusicalKey`, `Genre`, `ReleaseFormat`,
`Platform`.

Two sub-namespaces exist so a release can name a thing without knowing where it lives:

- **`Link/`** — `FileLink` is an interface with one method, `url()`. `HiDriveLink` builds a
  direct-download URL from a 9-character share id. Another host is a new class and no change
  anywhere else. Deliberately **not** `Stringable`: an implicit conversion would let a link slip
  into a string unnoticed.
- **`Embed/`** — `Embed` is what a `Release` holds. `SoundCloudEmbed` implements it.
  `SoundCloudProfileEmbed` deliberately **does not** — it is a different *resource*, not a different
  *provider*, and a profile player assignable to a release would be nonsense. See
  [frontend.md](frontend.md#the-embed-hierarchy) for the client half, where the two do share a base.

The rest describe something other than a release: `Production/` is what the `.flp` knows
(`Arrangement`, `Section`, `ProductionTime`, `Plugin` — see [authoring.md](authoring.md)); `Api/`,
`Update/` and `Health/` are what a signed call, a push and a health report are made of — see
[security.md](security.md).

### `View/` — one class per page

A view has two abstract methods: `pageTitle(): string` and `content(): Node`. It returns a tree;
something else decides when that tree becomes markup. It also answers `language()` — what goes on
`<html lang>` — and `varyOn()`, the request headers the page reads; see [Language](#language).

`View` itself carries two helpers worth knowing about, because the views would otherwise
reimplement them: `title()` (section + em dash + site name) and `accented()` (splits a trailing `!`,
`.` or `?` into a span, which is what makes `ill.` and `electronic music.` read the way they do).

[`Layout::wrap()`](../src/NeuroSYS/Layout.php) is the shell: head, header with the wordmark, `<main
id="content">`, footer with the profile links, and one `<script type="module">`.

### `Support/` — the shapes

`Collection<T>` and `SearchableCollection<T>` with the `TypedItems` trait they share — see
[collections.md](collections.md). `File`, `Directory` and `Diagnostics`, below. `Route`, `SitePath`,
`MethodPolicy` and `RouteInitialization`. `Charset` and `UrlScheme`. `PasswordHash` and `PublicKey`;
`TarArchive` and its entries. `BareArray`, `BareString` and `BareCall`, the three attributes that
excuse an exception to a guideline — see [guidelines.md](guidelines.md).

**The encoding is one fact.** `Charset` sits in `Support/` because both the header and the markup
tree read it and `View/` has no other reason to know anything about HTTP. It carries two forms —
`utf-8` for the header parameter, `canonical()` for the document head and for the site's one escaping
call — because those two readers write it differently, and one enum keeps both spellings in step.

### Data files

**A path is a `File`, not a string.** `Config::dataFile()` hands back one, and every class that reads
`data/` goes through it rather than asking `is_file()` in its own words. `is_file()` guards a file
that is absent and does nothing about one that is present and unreadable, so a following
`file_get_contents()` would warn — and the headers have already gone out by then, so the warning
would print into the page ahead of the doctype. `File::read()` answers `null` for both causes, and
`File::lines()` answers `[]`.

**What silences that warning is `Diagnostics`, not `@`.** Every read and write in `File` and
`Directory` runs through `Diagnostics::muted(…)`, which installs an error handler for the closure and
takes it down again in a `finally`. The difference from `@` is not tidiness: `@` silences every
diagnostic raised anywhere in the expression at any severity, where this names the severities it
claims and hands the rest back to PHP untouched. See [guidelines.md](guidelines.md#the--rule).

**And what it is handed is a `DataFile`, not a path.** A name nothing recognises would resolve to a
`File` like any other, `read()` would answer null for it, and each repository turns that null into an
empty collection *on purpose* — because a clone that has never staged a demo has to be a site rather
than a fatal. So the guard that makes a fresh checkout work is the guard that would swallow a typo:
`releaes.php` would be an empty catalogue with a 200 and nothing in any log. Nine cases, three of
them where a credential lives — and `site_auth.php` is worse than quiet: its **absence is the off
switch**, so a misspelling there would not fail, it would stand the pre-launch gate down. `ConfigTest`
iterates the cases and asks `isTracked()`, which is checked against git rather than against a
comment.

**The privacy policy is two cases, one per language**, because the page's order is the visitor's
(see [Language](#language)) and separating the halves at read time would mean searching a legal
document for a heading. Both are tracked, so a clone has the policy it needs; a half that fails to
read is an empty half. **`deploy.sh` has no `--delete` on `data/`**, so a data file renamed or
removed here has to be deleted from the server by hand.

**`File` cannot create a directory, and that is the decision rather than the omission.** `write()`
and `append()` both fail on a path whose directory is missing. Creating one is `Directory`'s to do and
a caller's to ask for — don't add an `@mkdir` to make `data/logs/` appear. `Directory` also refuses to
recurse: `remove()` takes away the files it holds and then itself, so a fixture comes apart and a
tree does not.

---

## The type discipline

Almost everything here is one of four shapes. Recognising which one you need is most of the work of
adding something.

### 1. An enum, when the vocabulary is closed

`Genre`, `MusicalKey`, `ReleaseFormat`, `HttpStatusCode`, `CspDirective`, `Tag`, `SitePath`,
`DataFile`, every attribute name. A typo becomes a parse error instead of a value the browser
silently drops.

Enums here list **what is used, not what exists** — `HtmlTag` has the elements the site emits and no
others. The exceptions are the ones where the registry really is closed (`TopLevelType`,
`HttpStatusCode`) or where exhaustiveness is the point (`SecurityHeader`).

Single-case enums are not a mistake: `Charset`, `RequestedWith` and `Doctype` exist to make a value
a *type*, not to offer a choice. Every enum is backed — see [guidelines.md](guidelines.md).

### 2. A value object with a `verify()`, when the value arrives as free text

`HiDriveLink` (a share id), `Profile` (a URL), `CspHost` (a bare origin), `MimeType` (a subtype),
`Release` (a positive bpm). Each validates in its constructor and throws.

The point is **where** it throws. These all fire while `data/` is being loaded, naming the offending
value, rather than surfacing later as a broken link nobody clicks.

`Profile::url` is checked here *and* again by `Element` at render time. That is not redundancy: the
renderer is the backstop and reports the fault on whatever page draws the footer; the constructor
reports it where the mistake actually is.

### 3. An interface, when the axis is "which provider"

`FileLink`, `Embed`, `Response`, `Controller`, `Node`, `TagName`, `AttributeName`, `HeaderName`,
`HeaderValue`, `AttributeValue`, `CspSource`. Each has one or two methods and exists so a call site
can hold the abstraction without knowing the implementation.

### 4. An immutable collection, when a group crosses a public boundary

`Collection::with()` **copies**. That is what makes a collection safe inside a `readonly` value
object — `readonly` protects the reference, not what it points at. The name is chosen so a dropped
result reads as wrong, and PHP 8.5 enforces it: the builders and the query methods carry
`#[\NoDiscard]` and `phpunit.xml.dist` sets `failOnWarning`, so a discarded result is a failing test.
A collection replaces a hand-rolled type check on data crossing a public boundary; it does not
replace a variadic. The whole of it is in [collections.md](collections.md).

---

## Exceptions

Every condition this site can be in has a name, and all of them live in `NeuroSYS\Exception`. Twelve
classes — one of them abstract — and one interface:

| Class | Is | Raised by |
|---|---|---|
| `SiteException` | the marker, an interface | — |
| `ApiException` | a signed request that cannot be read or trusted | `ApiCredential`, `ApiEnvelope` |
| ` └ UpdateException` | a payload that cannot be read or applied | 5 classes |
| `MarkupException` | abstract; the three below | — |
| ` ├ ElementException` | an element asked to be what no element can be | `Element` |
| ` ├ ParserException` | markup outside this site's own vocabulary | `MarkupParser` |
| ` └ TerminalException` | rows that cannot reach the element that draws them | `Terminal` |
| `CollectionException` | a collection asked to hold or produce the wrong type | `TypedItems` |
| `GuidelineException` | an excuse for a guideline with a hole in it | the three attributes |
| `MimeTypeException` | a media type that is not one | `MimeType` |
| `ReleaseVerificationException` | a `data/` value object built from data it cannot accept | 15 classes |
| `RouteException` | a `SitePath` given the wrong number of values | `SitePath` |
| `SecurityPolicyException` | a policy value that is not valid on the wire | 10 classes |

**`SiteException` is an interface because the inheritance chain is already spent.** Eight classes
declare it and the four under `MarkupException` and `ApiException` inherit it; of the eight, five are
a `LogicException`, one a `RuntimeException`, one a `TypeError` and one an
`InvalidArgumentException` — each saying something true — so the question *did this come from us*
has nowhere else to live. It matters more than it looks: `CollectionException extends TypeError`
extends **`Error`**, a sibling of `Exception` rather than a subclass, so `catch (Exception)` — the
widest net anybody reaches for by habit — misses one of the eleven concrete classes, silently, in the
class most likely to be thrown by a mistake made five minutes ago. Only `Throwable` catches all
eleven, and `Throwable` also catches everything PHP raises. This interface is the difference, and the
handler in `public/index.php` is what it is for.

**An exception becomes ours by extending the SPL class it already was, not by replacing it.**
`CollectionException extends TypeError`, `GuidelineException extends InvalidArgumentException`,
`ApiException extends RuntimeException`: every `instanceof`, every `catch` and every
`expectException` that matched the SPL class still matches, and the only thing ours adds is that the
throw says which layer raised it. Throwing an SPL class is how you avoid making a promise; extending
one is how you keep it.

**`MarkupException` is abstract**, because nothing throws it. It is what its three subclasses have in
common, and saying so in the language is what stops a fourth kind arriving as a bare
`MarkupException` — which would read as "one of those three" and be none of them. A `catch` or an
`@throws` naming it means any of the three.

**`ApiException` is the same arrangement and is deliberately *not* abstract**, which is the
difference worth reading. Both exist so one `catch` at a boundary covers a family without listing
it — `ApiGate` names `ApiException` and gets `UpdateException` with it — but this one is thrown:
`ApiCredential` and `ApiEnvelope` raise it about a request that is nobody's service in particular.

**Two throws that look misplaced are argued rather than moved.** `Terminal` throws
`ReleaseVerificationException` for its element-type guard — but that guard is the seventh of seven
identical `is_a($this->x->type, …)` checks, the other six of which are in `Model/`, and splitting one
off would put a single question in two classes. `PasswordHash` throws it for a digest that is not
bcrypt, which is a `data/` value object failing at load — exactly what the class is documented for.
In both cases the class *name* is the only thing that reads oddly, and a name is a cheaper thing to
live with than a check in two places.

The rule that keeps every `throw` naming one of these is in
[guidelines.md](guidelines.md#the-exception-rule).

---

## Config

`Config` holds the facts about *this site* rather than about any of its code, and it is deliberately
narrow — a central bag of constants is the opposite of how everything else here is arranged, where a
fact lives with the thing it describes so its docblock can say why. A constant earns a place only by
being **identity** (name, handle, address, tagline), **environment** (data paths, reachable origins,
switches), or **already stated twice** — which is why it holds the HiDrive origin (`HiDriveLink` and
the CSP both need it; drift and covers load right up until the policy blocks them), the SoundCloud
player host (the CSP and `SoundCloudPlayer.ts`; drift and our own policy blocks the player with
nothing on the page to explain it), the site's name, and the path to `data/`.

`assets/ts/Config.ts` mirrors the three the client reads — `NAME`, `HANDLE`, `PLAYER_HOST` — under
the same parity test as the enums. Not the rest: the data paths and the logging switch are the
server's business.

**What stays put**, because it means nothing outside the file that owns it: `CspHost`'s origin
pattern, `HiDriveLink`'s share-id pattern, SoundCloud's accent and attribution styling,
`Navigation`'s event name. Moving those here would only make them reachable from everywhere.

---

## The markup tree

**Nothing builds HTML from a string.** A view returns a `Node`, a page is a tree of them, and the only
code on the site that writes a `<` is `Element` and `Doctype`. The verify script fails the build if a
heredoc or a `'<tag'` literal appears anywhere else under `src/`.

| Node | Is |
|---|---|
| `Element` | a `TagName`, a keyed collection of `Attribute`s, child nodes |
| `Text` | a run of text, escaped on the way out |
| `Fragment` | several nodes with no element around them |
| `Document` | a `Doctype` and the `<html>` under it |
| `MarkupParser` | the reader — hand-authored markup, back into the four above |

Four mistakes cannot happen, three of which would otherwise be silent: a misspelled tag renders as an
inert inline box, a misspelled attribute is a null the client reads as nothing, an unescaped value is
an injection, and a mismatched closing tag is a document the browser reinterprets. The last one a
tree removes outright — there is no closing tag to get wrong, because there is no text form to write.

### Building one

```php
new Element(HtmlTag::A)
    ->attr(HtmlAttribute::ClassName, CssClass::BtnPrimary)
    ->attr(HtmlAttribute::Href, '/releases')
    ->containing('releases →');
```

`attr()` is the whole attribute API, and what you pass decides what renders:

| Value | Renders |
|---|---|
| `'visual'`, `5` | `player-style="visual"`, `height="5"` |
| any backed enum | its value — `class="hero"` |
| an `AttributeValue` | its `render()` — `content="width=device-width, initial-scale=1.0"` |
| `''` | `options=""` — a real empty value |
| `true` | `narrow` — a bare boolean attribute |
| `false`, `null` | nothing at all |

`''` and `null` are deliberately different. A public SoundCloud track has no secret token, and
`secret-token=""` is not the same thing to the client as no attribute.

**An attribute is an `Attribute`**, held in a `SearchableCollection` keyed by its name. The name is
kept beside the value even though the map is keyed by it, because `render()` has to ask the name
whether it is a URL and a key is a string; the key is what keeps last-write-wins and declaration
order.

`containing()` takes nodes; a bare string becomes escaped `Text`. That is the safe reading of the
ambiguous case — markup passed as a string shows up as visible `&lt;b&gt;`, which is wrong on the
page but *visibly* wrong.

### Typed attribute values

**The attribute's *value* is typed too, wherever it is a fixed vocabulary rather than data.**
`attr()` accepts any `BackedEnum` and unwraps it, so `rel`, `target` and `type` are `LinkRel`,
`LinkTarget` and `ScriptType` cases rather than strings — the same move `RequestedWith` and
`ContentTypeOptions` make beside the headers they fill. It earns its place on the same grounds the
names did: misspell `modulepreload` and every preload hint stops preloading in silence, misspell
`noopener` and a security boundary on every outbound link is quietly not there, and drop `module`
from the script tag and `import` becomes a syntax error. `rel` is a token list, so `LinkRel::tokens(…)`
builds it variadically the way `Allow::readOnly()` builds the `Allow` header. `preload` is
`MediaPreload` and `<meta name>` is `MetaName` on the same grounds — the second is the whole
vocabulary of an attribute used nowhere else on the site, and it fails the way the rest of this list
does, which is not at all: a `<meta>` whose name nothing recognises is laid out as nothing and moved
past, so a misspelled `viewport` renders every phone at 980px with the media queries answering for a
screen nobody is holding. `lang` is `Language` on the same grounds and fails the same way — a language
tag nothing recognises is not an error, it is a screen reader picking the wrong voice and a
hyphenation dictionary picking the wrong words. These are server-only, so they have no TypeScript
mirror and none is wanted.

**A value with a *grammar* is a class, not a case**, and `attr()` takes one through an
`AttributeValue` interface — the same shape `HeaderValue` has on the HTTP side, for the same reason:
an `->attr(…)` call site is the one place a grammar cannot be checked. `ViewportContent` is the one
implementation: `width=device-width, initial-scale=1.0` is a descriptor list of name-value pairs. Its
width is a `ViewportWidth` case and its scale is a `float`, so neither half can be misspelled. Note the
two rules the class exists to keep: the scale renders `1.0` rather than PHP's `(string)` of it, which
is `1` — the same instinct that keeps `Charset` carrying two spellings of one encoding — and it is
formatted with `%F` rather than `%f`, because `%f` under a German locale writes a decimal comma and a
comma is this grammar's own separator.

**Unwrapping happens in `attr()`, and both guarantees stay in `render()`.** That is not a
contradiction of the rule in the next section: unwrapping is *normalisation* — shorthand for the
string a call site would otherwise have typed — where escaping and the scheme check are guarantees,
which have to hold for an element built any way at all. `Attribute` holds a `?string`, so what a
hostile `AttributeValue` returned is escaped exactly like anything else. `HtmlTest` builds one to
prove it.

### The two guarantees, and where they live

Both are enforced in **`render()`, not in the builders**, because `render()` is the only code that
turns a node into markup — so the guarantee holds for any element however it was assembled,
including one built by handing the constructor its attributes directly, which `attr()` is otherwise
the only thing standing in front of.

- **Escaping.** `Element` escapes an attribute value by rendering it as a `Text`, so
  `htmlspecialchars` is called in exactly one place on the whole site, with `ENT_QUOTES |
  ENT_SUBSTITUTE | ENT_HTML401` written out rather than inherited from the runtime. `HtmlTest` pins
  that call site the same way it pins `containingHtml()`'s.
- **Scheme.** An attribute the browser dereferences is asked what scheme it names, because escaping
  is the wrong tool for a URL — `javascript:alert(1)` contains not one character `htmlspecialchars`
  touches. `AttributeName::isUrl()` says which attributes those are, case by case and not enum by
  enum, since `href` and `class` live in the same one.

  The allowlist is site-relative, `https:` and `mailto:` — `UrlScheme` cases, since a scheme is a
  fact about a URL and not about markup, which is why the enum sits in `Support/` beside `Charset`
  and why the footer and the imprint build their `mailto:` through it. The *list* stays its own
  constant rather than collapsing to `UrlScheme::cases()`: the enum is the vocabulary a URL may be
  written in, the constant is what is switched on — the distinction `CspScheme::Data` makes too.

  **A leading slash is not the same claim as "somewhere on this site"**, so it is asked rather than
  assumed. `Element::staysOnThisOrigin()` resolves the value with the WHATWG parser PHP 8.5 ships —
  against a reserved `.invalid` base, the way a browser would — and asks whether it landed where it
  started. Never test for "starts with a slash", and never list the prefixes an authority can open
  with: the parser strips tab, CR and LF *before* parsing, so `/\r\n/host` is `//host` is
  `https://host`. `Navigation.ts` makes the same check on the client. `HtmlTest` pins every
  spelling, the two whitespace ones included, along with the marked attribute set in both directions.

### Pretty-printing is not cosmetic

An element whose children are all elements puts each on its own line; one with any `Text` among them
stays on one line. Whitespace between inline content is content — without that rule
`<h1>ill<span>.</span></h1>` would gain a space inside the title.

### Hand-authored markup: `MarkupParser` and `containingHtml()`

`data/privacy.de.html` and `data/privacy.en.html` are a hand-authored document rather than markup a
view assembles. `MarkupParser` reads them into the tree, and `Element::containingHtml()` is the door:

```php
new Element(HtmlTag::Section)
    ->attr(HtmlAttribute::Lang, $language)
    ->containingHtml($html);
```

It is the safe twin of `containing()`, which is the pair worth reading together: `containing('<b>x</b>')`
puts visible `&lt;b&gt;` on the page because a string is content, and `containingHtml('<b>x</b>')`
parses the same argument into a real `<b>` — after checking that `b` is an element this site emits and
that everything on it is an attribute this site emits. Because it builds through `attr()` and
`containing()` rather than around them, escaping and the scheme check apply to a parsed document
exactly as they do to one a view assembled; the parser itself never looks at a URL and does not need
to.

**The refusals are the point, so they are exhaustive rather than illustrative** — the same stance
`TarArchive` takes about a member name off the network. An unknown element, an unknown attribute
(which is what refuses an `onerror=`), a comment, a CDATA section, an element from another namespace,
content the parser hoists into `<head>`, a `<script>` — whose content is raw text that `Text` would
escape into meaning something else — and **any HTML5 parse error at all**, each a `ParserException`.
The last is the one that matters most: `Dom\HTMLDocument` reports a stray `</div>` as a warning and
then recovers silently, which for a hand-edited legal document would mean the rest of the policy
disappearing with nothing anywhere saying so. Both halves parse with zero errors, which is what makes
refusing on any of them affordable.

Four details are worth knowing before touching it:

- **It needs `ext/dom`**, one of the extensions asked for by name — bundled with PHP is not the same
  as built into a host's PHP — and the failure is a fatal on `/privacy` alone, the one page here that
  is a legal obligation rather than a choice.
- **The doctype it prepends is `Doctype::Html5`, not a literal**, which is what puts the parser in
  no-quirks mode; without it *every* fragment reports `unexpected-token-in-initial-mode` and the
  error trap is noise. Reusing the class that owns that string also means `MarkupParser` holds no `<`
  literal at all, so `Element` and `Doctype` stay the only two files that write one.
- **A name is resolved with `tryFrom()`**, where the honest question is which case has this
  `tagName()`. The two are one question only because every case of every vocabulary enum spells its
  name as its backing value, which `HtmlTest` pins — along with the parser's two registries of enums,
  in both directions against reflection. An enum missing from a registry does not break the parser,
  it makes every one of that enum's names unparseable, which reads as the *markup* being wrong.
- **The parsed nodes become children of the wrapping element rather than a `Fragment`**, and that is
  what keeps a document coming back out as it went in. A parse keeps the source's own whitespace as
  `Text`, and a `Text` among the children is what puts `renderChildren()` on its single-line branch —
  where nothing is re-indented and, more to the point, no newline is invented between inline content.
  The rendered text is identical to the source; the *bytes* are not, because a character reference
  comes back as the character it names.

**What it costs**, per half, with no Xdebug loaded: 0.071 ms to parse, 0.242 ms to walk into the
tree, 0.262 ms to render it back out — so `/privacy` pays about **+1.14 ms** for both halves. That is
the largest single price this site pays for a guarantee, and it is affordable only because it is one
route out of ten and the least-visited page on the site. Under Xdebug the walk is three times that
and the parse is unchanged, which is why [performance.md](performance.md) records +3.4 ms for the same
work: it measures in the environment that has it loaded.

`HtmlTest` pins the call sites, so a second one has to be argued for in a test named for the fact.
**Never parse anything a request can influence** — not because it would be an injection, which is
what the refusals are for, but because the vocabulary is this site's own, so a visitor would
otherwise get to choose which of our elements to build.

---

## Language

The imprint and the privacy policy are the only bilingual pages here — each carries a German half and
an English half, one after the other — and the order is the visitor's: `Accept-Language` decides which
half they meet first.

Four things are worth knowing before touching any of it.

- **Both halves are always sent. Only the order changes.** The German imprint is what discharges
  § 5 DDG and § 18 Abs. 2 MStV, so it is never the half left out; a wrong guess costs a visitor one
  scroll, where a wrong *omission* would cost rather more than that. `PageTest` asserts both halves
  are present under either language, which is the property worth pinning rather than the ordering.
- **Nothing here is translated.** The two halves are written already; the German titles
  (`Impressum`, `Datenschutzerklärung`) are words in those documents. `AcceptedLanguages` chooses
  between things that exist and never invents one.
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

---

## Recipes

### Add a route

1. A `SitePath` case — its value is the pattern, static segments and `{param}` placeholders.
2. An `addRoute()` line in
   [`RouteInitialization::routes()`](../src/NeuroSYS/Support/RouteInitialization.php) — the case plus
   a factory closure. Order is match order; captures reach the factory positionally.
3. A `Controller` implementation.
4. A `View` subclass, if it renders a page.
5. Add it to the URL table in [README.md](README.md#url-structure).

Links to it are `SitePath::YourCase->to(...)`, never a concatenated string.

### Add a page

```php
final class ThingView extends View
{
    public function pageTitle(): string { return self::title('thing'); }

    public function content(): Node
    {
        return new Element(HtmlTag::Section)
            ->attr(HtmlAttribute::ClassName, CssClass::PageSection)
            ->containing(/* … */);
    }
}
```

If you need an element or attribute that has no case yet, add it to `HtmlTag` / `HtmlAttribute` —
and read [contracts.md](contracts.md) first if the tag is one of ours, because that is a fact stated
on both sides.

### Add a file host

Implement `FileLink`:

```php
final readonly class SomeHostLink implements FileLink
{
    public function __construct(public string $id) { $this->verify(); }
    public function url(): string { /* … */ }
}
```

Then use it in `data/releases.php`. `Release`, `Format`, `DownloadController` and `ReleaseView` need
no change — they all hold the interface. Add the host's origin to `Config` and to `img-src` if
covers load from it.

### Add an embed provider

Server half:

1. Implement `Embed` — `platform()`, `height()`, `toElement(string $title)`.
2. A `Platform` case, if the provider is new.
3. A `Tag` case for the custom element.
4. An attribute enum for the provider's own attributes. The height is **not** one of them: it goes
   under `EmbedAttribute::Height`, because the gate that reserves it is every provider's.

Client half: see [frontend.md](frontend.md#add-an-embed-provider). Nothing else changes — a release
still just holds an `Embed`.

### Add a response header

1. A case in `ResponseHeader` (or `SecurityHeader` if `SecurityHeaders` will send it — that enum is
   exhaustive and a test will fail until the two agree).
2. A `HeaderValue` for its value — an existing one, or a class that knows the header's grammar, added
   to `SecurityPolicyTest`'s table.
3. Pass a `Header` in the response's `Collection<Header>` of extra headers. `ViewResponse` and
   `PlainTextResponse` take it in the same position.

### Add a validated value

Constructor promotion, a private `const` pattern, a private `verify()`, and one of the existing
exceptions:

| Exception | For |
|---|---|
| `ReleaseVerificationException` | anything the `data/` files declare |
| `MimeTypeException` | a malformed media type |
| `SecurityPolicyException` | anything under `Http\Security`, and any header value |
| `ElementException` | an element asked to be something no element can be |
| `RouteException` | a `SitePath` given the wrong values |
| `ApiException` / `UpdateException` | a signed request, or a payload, that cannot be trusted |

Write the message so it names the offending value **and** says what the right shape looks like —
every existing one does, and that is what makes the failure self-service. Anchor the pattern with
`\z`, not `$` (see below).

---

## Traps worth knowing

- **`$` is not "the end" in PCRE.** It also matches immediately before a trailing newline.
  `Route::matches()`, `Profile::URL_PATTERN` and `HiDriveLink::ID_PATTERN` all use `\z`.
- **`parse_url()` signals failure with `false`, not `null`.** That is why `Request::path()` uses
  `Uri\Rfc3986\Uri::parse()` — `??` reads as a guard and only *is* one against null.
- **`file()` and `file_get_contents()` fail in two ways each.** `file()` returns `false` on an
  unreadable file and a `foreach` over it would `TypeError`; `file_get_contents()` *warns* before it
  returns false, and by then the headers are gone, so the warning prints into the page ahead of the
  doctype. Read `data/` through `File::lines()` and `File::read()`, which answer `[]` and `null`.
- **`!== []` is true of every `Collection`.** Ask `isEmpty()`.
- **`fn()` captures by value.** `SitePath::to()`'s filler must be a `function` with
  `use (&$values)`, or every placeholder takes the first value.
- **A discarded builder result does nothing.** `#[\NoDiscard]` catches it, but the shape is still
  worth recognising: `$collection->with($x);` on its own line is always a bug.
- **`data/logs/` is not auto-created**, and `File` will not create it. `fopen(…, 'ab')` makes the
  file, not its directory, and `deploy.sh` excludes it. Latent while logging is off.
- **A misspelled `DataFile` is not an error** — it is an empty catalogue, or, for `site_auth.php`, a
  gate switched off. Name data files through the enum only.

---

## Further reading

- [collections.md](collections.md) — `Collection` and `SearchableCollection`: laziness, what they may
  hold, what they cost, and where they stop
- [guidelines.md](guidelines.md) — the five habits `GuidelineTest` keeps out, and the three excuse
  attributes
- [frontend.md](frontend.md) — the TypeScript and CSS sources, and the element model
- [contracts.md](contracts.md) — every fact this side states that the client states too
- [testing.md](testing.md) — the two suites, and the invariants that pin the above
- [security.md](security.md) — the policies `SecurityHeaders` sends, the request's hardening, the API
- [history/types.md](history/types.md) and [history/markup.md](history/markup.md) — how the above got
  this way
