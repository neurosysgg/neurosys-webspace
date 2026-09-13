# Architecture — the PHP side

How `src/NeuroSYS/` is arranged, what each layer is allowed to know, and what to do when you need to
add something. The front end has its own doc ([frontend.md](frontend.md)), and the facts the two
sides state twice have a third ([contracts.md](contracts.md)).

Two companions go deeper than this map: [collections.md](../phpanta/docs/collections.md) for the only shape a group
of values takes, and [guidelines.md](../phpanta/docs/guidelines.md) for the five habits `GuidelineTest` keeps out.
The response policies and the hardening of the request are argued in [security.md](security.md).
How any of it got this way is in [history/](history/README.md).

---

## The shape in one screen

```
request
  │
  ├─ public/.htaccess          rewrite everything to index.php; http:// → https://
  │
  └─ public/index.php
       │
       ├─ set_exception_handler(…)     ⓪ the last resort, for when nothing else worked
       └─ Site::current()->run()       the app autoload.php booted — Phpanta's App::run(), in this order:
            │
            ├─ ErrorLog::install(…)         ⓪ every diagnostic, at E_ALL, into data/logs/php-YYYY-MM.log
            ├─ SecurityHeaders::send()      ① headers first, so they cover every exit below
            ├─ Request::fromGlobals()       ② $_SERVER → a typed, readonly Request
            ├─ Auth::requireSiteAuth()      ③ pre-launch gate; may exit 401
            ├─ Router::dispatch()           ④ URL → Controller, then the method gate → Response
            └─ Response::send()             ⑤ headers + body, or a redirect, or plain text
```

Everything below the front controller is plain classes on **Phpanta**, the framework this site grew
and now vendors at `phpanta/` — small enough to read, and edited in place. There is no container and
no config file that wires anything up: a controller constructs what it needs, and the one thing
assembled up front is the route table, the site's routes from `Site::routes()` with the framework's
API route after them.

```
src/NeuroSYS/
├── Controller/     one class per route group; fetches data, returns a Response
├── Service/        the site's data files, the demo gate, the download log
├── Model/          the domain: releases, demos, and everything they are made of
│   ├── Embed/      third-party players
│   ├── Link/       off-site files
│   └── Production/ what the .flp knows: arrangement, time spent, plugins
├── Text/           every word the site says, in both languages
├── View/           one class per page; each returns a Node, never a string
│   ├── Html/       the tags, attributes and classes this site adds to the markup tree
│   └── Terminal/   the terminal component's declared form
├── Support/        SitePath and the route table
├── Exception/      the two conditions only this site can be in
├── AssetManifest.php  GENERATED — the build's stamp, stylesheet and script
├── DataFile.php    every file the site reads out of data/, named rather than spelled
├── Layout.php      the shell every full page is rendered inside — the app's Shell
└── Site.php        this site as the framework's app
```

Everything else — the wire, the markup tree, collections, the API, health, the router — is
Phpanta's, under `phpanta/src/`, and described in
[its architecture](../phpanta/docs/architecture.md).

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

- **The markup tree depends on nothing above it.** `Phpanta\View\Html` knows about
  `Support\Charset` and `Support\UrlScheme` and nothing else; this site's `View\Html` holds only the
  names it adds to it — its tags, its attributes, its classes.
- **`Model` never renders itself into markup — but it does build elements.** `SoundCloudEmbed`
  returns an `Element`, which looks like a violation and is not: it emits *one custom tag with
  typed attributes*, and the tag's contents are built client-side. The model declares; the view
  arranges; `View\Html` renders.

---

## The request, traced

The framework's — see [phpanta/docs/architecture.md](../phpanta/docs/architecture.md#the-request-traced).

## The layers

### `Http/` — the wire

The framework's — see [phpanta/docs/architecture.md](../phpanta/docs/architecture.md#http--the-wire).

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
| `DemoGate` | each demo's password hash, checked on the framework's `Auth` primitives |
| `DownloadLogger` | `data/logs/downloads.log` — returns before doing anything, see below |
| `Auth`, `ApiGate`, `UpdateApplier` | Phpanta's: `data/site_auth.php` and `data/admin.php`; every check a signed call passes, and the writing a push does — see [security.md](security.md) |

The repositories load lazily and cache, and take an optional path so a test can point them somewhere
else. Each reads a PHP file that `return`s typed objects — there is no parser, no schema, no
serialisation format. A bad release is a `ReleaseVerificationException` thrown while the file loads.

**`Auth::accepts()` is public and returns a bool; the 401 is separate.** A method that ends the
request cannot be asserted against, so the decision lives beside the challenge rather than inside
it. Both credential comparisons run every time and are combined afterwards — chaining them with
`&&` would leak which half was wrong through timing, since bcrypt is deliberately slow and
`hash_equals()` is not.

**Download logging is deliberately off, for legal reasons.** `Site::DOWNLOAD_LOGGING` is `false`,
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
`Update/` and `Health/` are what a signed call, a push and a health check are made of — see
[security.md](security.md) and [health.md](../phpanta/docs/health.md). `Health/` is the one namespace that imports
nothing of this site's, so it can be lifted out whole. The requirements that do know the site
live in `Service/Health/`, and are declared in `Support/RequirementInitialization`.

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

The framework's — see [phpanta/docs/architecture.md](../phpanta/docs/architecture.md#support--the-shapes).

### Data files

**A path is a `File`, not a string.** `Site::current()->dataFile()` hands back one, and every class that reads
`data/` goes through it rather than asking `is_file()` in its own words. `is_file()` guards a file
that is absent and does nothing about one that is present and unreadable, so a following
`file_get_contents()` would warn — and the headers have already gone out by then, so the warning
would print into the page ahead of the doctype. `File::read()` answers `null` for both causes, and
`File::lines()` answers `[]`.

**What silences that warning is `Diagnostics`, not `@`.** Every read and write in `File` and
`Directory` runs through `Diagnostics::muted(…)`, which installs an error handler for the closure and
takes it down again in a `finally`. The difference from `@` is not tidiness: `@` silences every
diagnostic raised anywhere in the expression at any severity, where this names the severities it
claims and hands the rest back to PHP untouched. See [guidelines.md](../phpanta/docs/guidelines.md#the--rule).

**And what it is handed is a `DataFile`, not a path.** A name nothing recognises would resolve to a
`File` like any other, `read()` would answer null for it, and each repository turns that null into an
empty collection *on purpose* — because a clone that has never staged a demo has to be a site rather
than a fatal. So the guard that makes a fresh checkout work is the guard that would swallow a typo:
`releaes.php` would be an empty catalogue with a 200 and nothing in any log. Nine cases, three of
them where a credential lives — and `site_auth.php` is worse than quiet: its **absence is the off
switch**, so a misspelling there would not fail, it would stand the pre-launch gate down. `AppTest`
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

The framework's — see [phpanta/docs/architecture.md](../phpanta/docs/architecture.md#the-type-discipline).

## Exceptions

The framework's — see [phpanta/docs/architecture.md](../phpanta/docs/architecture.md#exceptions).

## Site

`Site` is this site as the framework's app — `NeuroSYS\Site extends Phpanta\App` — and it holds the
facts about *this site* rather than about any of its code. It is deliberately narrow, as `Config`
was before it: a constant earns a place only by being **identity** (name, handle, address, origin),
**environment** (reachable origins, the update key's path, switches), or **already stated twice** —
which is why it holds the HiDrive origin (`HiDriveLink` and the CSP both need it; drift and covers
load right up until the policy blocks them), the SoundCloud player host (the CSP and
`SoundCloudPlayer.ts`; drift and our own policy blocks the player with nothing on the page to explain
it), and the site's name. Identity stays constant, so a `php -r` line and the client's parity test
read `Site::NAME` without booting anything; the paths hang off the deployment, which is the app's.

What the framework asks of it — its routes, its 404 page, its languages, its shell, its vocabulary,
its build, its data files, its CSP hosts — is answered here too. The framework's side of that
contract is [the app](../phpanta/docs/architecture.md#the-app).

`assets/ts/Config.ts` mirrors the three the client reads — `NAME`, `HANDLE`, `PLAYER_HOST` — under the
same parity test as the enums. Not the rest: the paths and the logging switch are the server's
business.

**What stays put**, because it means nothing outside the file that owns it: `CspHost`'s origin
pattern, `HiDriveLink`'s share-id pattern, SoundCloud's accent and attribution styling,
`Navigation`'s event name. Moving those here would only make them reachable from everywhere.

---

## The markup tree

The framework's — see [phpanta/docs/architecture.md](../phpanta/docs/architecture.md#the-markup-tree).

## Language

Every page is written in English and German, at the same address, and `Request::language()` decides
which: the visitor's `lang` cookie where it names a language this site has, else their
`Accept-Language`, else English. The cookie outranks the header because it is a choice made on this
site, where the header is a setting made once for every site; a cookie naming anything else is no
choice at all and falls through. How a word on a page finds that language, the catalogs it is
written in and the switch that sets the cookie are [language.md](../phpanta/docs/language.md)'s.

What is left here is the legal pages' own arrangement. The imprint and the privacy policy are not
translated but written twice: each carries a German half and an English half, one after the other,
and the request's language decides which a visitor meets first.

Four things are worth knowing about them before touching either.

- **Both halves are always sent. Only the order changes.** The German imprint is what discharges
  § 5 DDG and § 18 Abs. 2 MStV, so it is never the half left out; a wrong guess costs a visitor one
  scroll, where a wrong *omission* would cost rather more than that. `PageTest` asserts both halves
  are present under either language, which is the property worth pinning rather than the ordering.
- **The legal pages are not translated.** Their two halves are written already; the German titles
  (`Impressum`, `Datenschutzerklärung`) are words in those documents. `AcceptedLanguages` chooses
  between things that exist and never invents one.
- **A page that reads a request header owes a `Vary` naming it**, and both facts are stated in one
  place so the second cannot be forgotten: `View::varyOn()` declares the headers, `ViewResponse`
  builds the header from it — and since every page is written in a language now, `ViewResponse`
  names `Accept-Language` and `Cookie` itself, for all of them. Forget it and there is no error at all; a cache simply becomes free
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
no change — they all hold the interface. Add the host's origin to `Site`, and to `img-src` in
`Site::contentHosts()` if covers load from it.

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
| `RequirementException` | a requirement declared with something it cannot check |
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

- [collections.md](../phpanta/docs/collections.md) — `Collection` and `SearchableCollection`: laziness, what they may
  hold, what they cost, and where they stop
- [guidelines.md](../phpanta/docs/guidelines.md) — the five habits `GuidelineTest` keeps out, and the three excuse
  attributes
- [frontend.md](frontend.md) — the TypeScript and CSS sources, and the element model
- [contracts.md](contracts.md) — every fact this side states that the client states too
- [testing.md](testing.md) — the two suites, and the invariants that pin the above
- [security.md](security.md) — the policies `SecurityHeaders` sends, the request's hardening, the API
- [history/types.md](../phpanta/docs/history/types.md) and [history/markup.md](../phpanta/docs/history/markup.md) — how the above got
  this way
