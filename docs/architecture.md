# Architecture — the PHP side

How `src/NeuroSYS/` is arranged, what each layer is allowed to know, and what to do when you need to
add something. The front end has its own doc ([frontend.md](frontend.md)), and the facts the two
sides state twice have a third ([contracts.md](contracts.md)).

This is the map. `CLAUDE.md` is the long-form rationale — where a decision here has a paragraph
arguing for it, this doc links rather than restates.

---

## The shape in one screen

```
request
  │
  ├─ public/.htaccess          rewrite everything to index.php; http:// → https://
  │
  └─ public/index.php          five statements, in this order:
       │
       ├─ SecurityHeaders::send()      ① headers first, so they cover every exit below
       ├─ Request::fromGlobals()       ② $_SERVER → a typed, readonly Request
       ├─ Auth::requireSiteAuth()      ③ pre-launch gate; may exit 401
       ├─ Router::dispatch()           ④ 405 gate, then URL → Controller → Response
       └─ Response::send()             ⑤ headers + body, or a redirect, or plain text
```

Everything below the front controller is plain classes. There is no framework, no container, no
config file that wires anything up: a controller constructs what it needs, and the one thing
assembled up front is the route table.

```
src/NeuroSYS/
├── Http/           the wire — what arrived, what goes back, and every header on it
│   └── Security/   the response security policies, as typed objects
├── Controller/     one class per route group; fetches data, returns a Response
├── Service/        the things that talk to the outside — data files, credentials, the log
├── Model/          the domain: a release and everything it is made of
│   ├── Embed/      third-party players
│   └── Link/       off-site files
├── View/           one class per page; each returns a Node, never a string
│   ├── Html/       the markup tree
│   └── Terminal/   the terminal component's declared form
├── Support/        the shapes everything else is built out of
├── Config.php      the facts about this site
├── Layout.php      the shell every full page is wrapped in
└── Router.php      URL → Controller, and nothing else
```

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
  knows about `Support\Charset` and no other part of the application.
- **`Model` never renders itself into markup — but it does build elements.** `SoundCloudEmbed`
  returns an `Element`, which looks like a violation and is not: it emits *one custom tag with
  typed attributes*, and the tag's contents are built client-side. The model declares; the view
  arranges; `View\Html` renders.

---

## The request, traced

Follow one request all the way through. Everything below happens for `GET /releases/ill`.

### ① Security headers, before anything can fail

[`SecurityHeaders::send()`](../src/NeuroSYS/Http/SecurityHeaders.php) runs as the first statement in
[`index.php`](../public/index.php), *before* the request is even parsed. That ordering is the whole
design: the 401 that `Auth` exits with, the 405 the router refuses a POST with, and the 303 a
download redirects with all get the full header set, because none of them can run before this line.

It also *removes* one header — `X-Powered-By`, which PHP appends with its exact patch version before
any of our code runs. See [security.md](security.md) for the policies themselves.

### ② `$_SERVER` becomes a `Request`

[`Request::fromGlobals()`](../src/NeuroSYS/Http/Request.php) is the only place the superglobals are
read. What comes out is `readonly` and typed, and three of its decisions are deliberate:

| Field | Decision |
|---|---|
| `method` | `HttpMethod::tryFrom()` — **nullable**. An unrecognised verb is `null`, and null is not read-only. Never guessed as GET. |
| `path` | Parsed with `Uri\Rfc3986\Uri::parse()`, which returns `null` on failure. A target that will not parse is passed through **verbatim**, so it matches no route and 404s. |
| `authUser` / `authPassword` | Read from `PHP_AUTH_*`, falling back to decoding `HTTP_AUTHORIZATION` — Strato strips the former. See [deployment.md](deployment.md). |

The path is **raw, not decoded**: a route matches the target as it was sent.

### ③ The pre-launch gate

[`Auth::requireSiteAuth()`](../src/NeuroSYS/Service/Auth.php) checks for `data/site_auth.php`. If
the file is absent it returns immediately — *that absence is how the gate is switched off*, and the
file is gitignored precisely so the repo copy cannot switch it on.

### ④ Routing

[`Router::dispatch()`](../src/NeuroSYS/Router.php) does two things, in order:

1. **The read-only gate.** `$request->isReadOnly()` or a 405 with an `Allow` header built from
   `HttpMethod::allowed()` — derived by filtering the cases, so the header cannot advertise
   something the gate does not do.
2. **The match.** Each [`Route`](../src/NeuroSYS/Support/Route.php) is a pattern and a factory
   closure. `{param}` compiles to `([^/]+)`, captures are passed positionally to the factory, and an
   unmatched path falls through to `NotFoundController`.

The route table is built in
[`RouteInitialization::routes()`](../src/NeuroSYS/Support/RouteInitialization.php) — seven entries,
in match order.

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
| `Header` | a `HeaderName` and a `HeaderValue`; formats `Name: value` in one place |
| `MimeType` | a `TopLevelType`, a validated subtype, and a `Charset` |
| `HttpStatusCode` | every status code, backed by its number |
| `HttpMethod` | the eight methods, and which of them only read |

Two responses `exit` and one does not. That is not an inconsistency: a redirect and a plain-text
refusal are terminal, and a `ViewResponse` returns so that `echo` is the last thing that happens.
The consequence is that **PHPUnit cannot observe either of the exiting ones**, which is why the
verify script exists — see [testing.md](testing.md).

**Header names live in two enums on purpose.** `SecurityHeader` is exhaustive and tested as such —
`SecurityHeaders::headers()` sends exactly its cases. `ResponseHeader` is everything else. Folding
them together would make the exhaustiveness assertion meaningless. `RequestHeader` is the inbound
direction; all three implement `HeaderName`, so `Header` formats any of them.

**Header *values* are typed too.** `HeaderValue` is one method, `render()`, and its implementations
are the objects that know each header's grammar: `ContentSecurityPolicy`, `PermissionsPolicy`,
`StrictTransportSecurity`, `ReferrerPolicy`, `ContentTypeOptions` and `MimeType`, plus
`CacheControl`, `ETag`, `Vary`, `Allow`, `BasicChallenge` and `Location`. `Header` accepts nothing
else, so a value can no longer be assembled as a string at the call site. `SecurityPolicyTest` pins
the set in both directions — every implementer must be rendered by its table, and the table must
name every implementer.

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
| `Auth` | `data/site_auth.php`, `data/admin.php` |
| `DownloadLogger` | `data/logs/downloads.log` — currently returns before doing anything |

Both repositories load lazily and cache, and both take an optional path so a test can point them
somewhere else. Both read a PHP file that `return`s typed objects — there is no parser, no schema,
no serialisation format. A bad release is a `ReleaseVerificationException` thrown while the file
loads.

**`Auth::accepts()` is public and returns a bool; the 401 is separate.** A method that ends the
request cannot be asserted against, so the decision lives beside the challenge rather than inside
it. Both credential comparisons run every time and are combined afterwards — chaining them with
`&&` leaked which half was wrong through timing, since bcrypt is deliberately slow and
`hash_equals()` is not.

### `Model/` — the domain

Typed value objects and enums. `Release`, `Format`, `Profile`, and the enums `MusicalKey`, `Genre`,
`ReleaseFormat`, `Platform`.

Two sub-namespaces exist so a release can name a thing without knowing where it lives:

- **`Link/`** — `FileLink` is an interface with one method, `url()`. `HiDriveLink` builds a
  direct-download URL from a 9-character share id. Another host is a new class and no change
  anywhere else. Deliberately **not** `Stringable`: an implicit conversion would let a link slip
  into a string unnoticed.
- **`Embed/`** — `Embed` is what a `Release` holds. `SoundCloudEmbed` implements it.
  `SoundCloudProfileEmbed` deliberately **does not** — it is a different *resource*, not a different
  *provider*, and a profile player assignable to a release would be nonsense. See
  [frontend.md](frontend.md#the-embed-hierarchy) for the client half, where the two do share a base.

### `View/` — one class per page

A view has two methods: `pageTitle(): string` and `content(): Node`. It returns a tree; something
else decides when that tree becomes markup.

`View` itself carries two helpers worth knowing about, because six views would otherwise reimplement
them: `title()` (section + em dash + site name) and `accented()` (splits a trailing `!`, `.` or `?`
into a span, which is what makes `ill.` and `electronic music.` read the way they do).

[`Layout::wrap()`](../src/NeuroSYS/Layout.php) is the shell: head, header with the wordmark, `<main
id="content">`, footer with the profile links, and one `<script type="module">`.

### `Support/` — the shapes

`Collection<T>` and `SearchableCollection<T>` (int-keyed and string-keyed), `Route`,
`RouteInitialization`, `JsonDeserializable`, `Charset`.

---

## The type discipline

Almost everything here is one of four shapes. Recognising which one you need is most of the work of
adding something.

### 1. An enum, when the vocabulary is closed

`Genre`, `MusicalKey`, `ReleaseFormat`, `HttpStatusCode`, `CspDirective`, `Tag`, every attribute
name. A typo becomes a parse error instead of a value the browser silently drops.

Enums here list **what is used, not what exists** — `HtmlTag` has the elements the site emits and no
others. The exceptions are the ones where the registry really is closed (`TopLevelType`,
`HttpStatusCode`) or where exhaustiveness is the point (`SecurityHeader`).

Single-case enums are not a mistake: `Charset`, `RequestedWith` and `Doctype` exist to make a value
a *type*, not to offer a choice.

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
`CspSource`. Each has one or two methods and exists so a call site can hold the abstraction without
knowing the implementation.

### 4. An immutable collection, when a group crosses a public boundary

`Collection::with()` **copies**. That is what makes a collection safe inside a `readonly` value
object — `readonly` protects the reference, not what it points at.

The name is chosen so a dropped result reads as wrong, and PHP 8.5 now enforces what the name only
suggested: `with()`, `allow()`, `attr()`, `containing()` and `Auth::accepts()` all carry
`#[\NoDiscard]`, and `phpunit.xml.dist` sets `failOnWarning`, so a discarded result is a failing
test. `NoDiscardTest` pins the set in both directions.

**When *not* to reach for a collection:** `PermissionsPolicy::$denied` and
`ContentSecurityPolicy::$directives` are private, never escape, and are only ever built through a
variadic — which PHP already enforces. The rule is that a collection replaces a hand-rolled type
check on data crossing a public boundary; it does not replace a variadic.

---

## The markup tree

**Nothing builds HTML from a string.** The only code on the site that writes a `<` is `Element` and
`Doctype`, and the verify script fails the build if a heredoc or a `'<tag'` literal appears anywhere
else under `src/`.

| Node | Is |
|---|---|
| `Element` | a `TagName`, typed attributes, child nodes |
| `Text` | a run of text, escaped on the way out |
| `Fragment` | several nodes with no element around them |
| `Document` | a `Doctype` and the `<html>` under it |
| `RawHtml` | the one audited hole |

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
| `''` | `options=""` — a real empty value |
| `true` | `narrow` — a bare boolean attribute |
| `false`, `null` | nothing at all |

`''` and `null` are deliberately different. A public SoundCloud track has no secret token, and
`secret-token=""` is not the same thing to the client as no attribute.

`containing()` takes nodes; a bare string becomes escaped `Text`. That is the safe reading of the
ambiguous case — markup passed as a string shows up as visible `&lt;b&gt;`, which is wrong on the
page but *visibly* wrong.

### The two guarantees, and where they live

Both are enforced in **`render()`, not in the builders**, because `render()` is the only code that
turns a node into markup — so the guarantee holds for any element however it was assembled,
including one built by handing the constructor an array.

- **Escaping.** `Element` escapes an attribute value by rendering it as a `Text`, so
  `htmlspecialchars` is called in exactly one place on the whole site, with `ENT_QUOTES |
  ENT_SUBSTITUTE | ENT_HTML401` written out rather than inherited from the runtime.
- **Scheme.** An attribute the browser dereferences is asked what scheme it names, because escaping
  is the wrong tool for a URL — `javascript:alert(1)` contains not one character `htmlspecialchars`
  touches. `AttributeName::isUrl()` says which attributes those are, case by case.

  The allowlist is site-relative, `https:` and `mailto:`. **A leading slash is not the same claim as
  "somewhere on this site"**, so `Element::staysOnThisOrigin()` resolves the value the way a browser
  would — against a reserved `.invalid` base — and asks whether it landed where it started. Pattern
  matching missed a case: the WHATWG parser strips tab, CR and LF *before* parsing, so
  `/\r\n/host` is `//host` is `https://host`.

### Pretty-printing is not cosmetic

An element whose children are all elements puts each on its own line; one with any `Text` among them
stays on one line. Whitespace between inline content is content — without that rule
`<h1>ill<span>.</span></h1>` would gain a space inside the title.

### `RawHtml` is the single hole

It exists for `data/privacy.html`, a hand-authored document rather than markup a view assembles.
`HtmlTest` pins its call sites, so a second one has to be argued for in a test named for the fact.
**Never construct one from anything a request can influence.**

---

## Recipes

### Add a route

1. A case in [`RouteInitialization::routes()`](../src/NeuroSYS/Support/RouteInitialization.php) —
   pattern plus a factory closure. Order is match order.
2. A `Controller` implementation.
3. A `View` subclass, if it renders a page.
4. Add it to the URL table in [README.md](README.md#url-structure).

Patterns take static segments and `{param}` placeholders; captures reach the factory positionally.

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
2. Pass a `Header` in the response's `$headers` array. Both `ViewResponse` and `PlainTextResponse`
   take it in the same position.

### Add a validated value

Constructor promotion, a private `const` pattern, a private `verify()`, and one of the existing
exceptions:

| Exception | For |
|---|---|
| `ReleaseVerificationException` | anything the `data/` files declare |
| `MimeTypeException` | a malformed media type |
| `SecurityPolicyException` | anything under `Http\Security` |
| `MarkupException` | an element asked to be something no element can be |

Write the message so it names the offending value **and** says what the right shape looks like —
every existing one does, and that is what makes the failure self-service.

---

## Traps worth knowing

- **`$` is not "the end" in PCRE.** It also matches immediately before a trailing newline.
  `Route::matches()`, `Profile::URL_PATTERN` and `HiDriveLink::ID_PATTERN` all use `\z`.
- **`parse_url()` signals failure with `false`, not `null`.** That is why `Request::path()` uses
  `Uri\Rfc3986\Uri::parse()` — `?? ` reads as a guard and only *is* one against null.
- **`file()` returns `false` on an unreadable file** and `foreach` would `TypeError`.
  `StatsController` guards with `?: []`.
- **`file_get_contents()` warns before it returns false**, and by then the headers are gone — so
  the warning prints into the page ahead of the doctype. `PrivacyController` checks `is_file()`
  first, the way every other data-file read does.
- **A discarded builder result does nothing.** `#[\NoDiscard]` catches it now, but the shape is
  still worth recognising: `$collection->with($x);` on its own line is always a bug.
- **`data/logs/` is not auto-created.** `fopen(…, 'ab')` makes the file, not its directory, and
  `deploy.sh` excludes it. Latent while logging is off.

---

## Further reading

- [frontend.md](frontend.md) — the TypeScript and CSS sources, and the element model
- [contracts.md](contracts.md) — every fact this side states that the client states too
- [testing.md](testing.md) — the two suites, and the invariants that pin the above
- [security.md](security.md) — the policies `SecurityHeaders` sends, and why each one
- `CLAUDE.md` — the long-form rationale behind most of the decisions summarised here
