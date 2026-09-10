# Security

This document is the security posture of the site in one place: the philosophy, the layered
hardenings, the invariants each layer enforces, and what is known and accepted. It describes what
the code does and, more usefully, *why* — an absence is worth writing down when it is a decision
rather than an oversight, and most of the security here is absences. The assessments this came out
of, and the findings they closed, are in [history/security.md](history/security.md).

The short version: **the shape of the site is its first defense.** It is static, has no database,
sets no cookie, starts no session, has no `<form>`, and has no runtime dependencies. Whole classes
of vulnerability are not mitigated here — they are structurally absent. What remains is enforced at
type boundaries and at the single place markup is rendered, so the failure mode of a mistake is a
build error or a thrown exception, not a silently shipped hole.

**One address family writes.** `/api` accepts a signed `POST` carrying a gzipped tarball and writes
it into `src/` and the webroot — it is the deploy path, and it is described in full under
[The API](#the-api). Everything else is read-only, accepts no upload and persists nothing a request
sends.

## The attack surface

Everything an attacker can reach:

- **Ten routes.** Nine are `GET`/`HEAD`: `/`, `/releases`, `/releases/{slug}`,
  `/releases/{slug}/{format}`, `/demos/{slug}` and `/demos/{slug}/{label}` (each behind that demo's
  own HTTP Basic password), `/admin/stats` (behind HTTP Basic), `/imprint`, `/privacy`.
  There is deliberately no `/demos` index — see [demos.md](demos.md).
- **The tenth is `/api/{service}/{version}/{action}`**, which accepts a `POST` and answers every
  method exactly as an address that does not exist, unless the request carries an ECDSA signature
  this deployment's public key verifies. It is unreachable without the private key and invisible
  without it — at every depth: `/api`, `/api/update`, `/api/update/v1`, `/api/health` and
  `/api/health/v1` match no route at all, because the pattern is four segments. **Two services
  answer under it** — `update`, which writes, and `health`, which only reads — and that difference
  is deliberately not observable: `ApiController` hands anything it will not verify to
  `UnroutedController` *before* it has resolved a service at all, so a read-only service is exactly
  as invisible as the writing one. Both suites sweep both.
- **Static assets** under `/assets/`, served by the web server, never by PHP. The one exception is a
  demo's audio, which PHP serves itself precisely so that it is *not* static — see below.
- Everything else answers `404` or `405`.

Everything an attacker controls: the **request target** (the path), the **method**, the request
headers the app reads — `Authorization`, and the four `RequestHeader` cases: `X-Requested-With`,
`If-None-Match`, `Range` (demo audio only) and `Accept-Language` (`/imprint` and `/privacy` only,
where it picks which language leads) — plus `Referer` only when download logging is on, which it is
not; and, under `/api` alone, a **request body**.

That body is read at one call site, and **it is not read at all until a signature has verified**.
The credential arrives in `Authorization` rather than framed into the body, so an unsigned caller is
refused before `php://input` is touched, and the read that does happen is bounded by the *signed*
length — which `ApiGate::MAX_BODY` (8 MiB) caps — rather than by `post_max_size`.

What is *not* in the surface, and the bug class each absence removes:

| Not present | Class it removes |
|---|---|
| No database, no SQL | SQL injection |
| No cookie, no session | session fixation/hijack; the ambient credential CSRF rides |
| No `<form>`, no ambient credential | CSRF target; mass-assignment |
| No user-facing upload, no user content | stored XSS |
| No path built from a request | traversal — a demo's audio is addressed by a declared label, and an update's members are matched against an allowlist of three roots |
| No `unserialize()` of request data | object injection |
| No shell-out, no `eval`, no dynamic include of request data | command injection; LFI/RFI |
| No third-party script, no CDN | supply-chain script injection |
| No outbound request from the server | SSRF; a visitor's address reaching a third party server-side |

These are the reason the rest of this document is short. You cannot exploit a feature that isn't
there.

## Defense in depth, following `index.php`

The front controller is six statements, and they are the spine of the request:

```php
set_exception_handler(...);              // 0. the last resort — logs, and answers a bare 500
SecurityHeaders::send();                 // 1. headers first — cover every response
$request = Request::fromGlobals();       // 2. parse the request defensively
Auth::requireSiteAuth($request);         // 3. the pre-launch gate
new Router(RouteInitialization::routes())->dispatch($request)->send($request);  // 4 + 5. route, respond
```

The handler comes first because it is the one that has to work when nothing else did: an uncaught
throwable is otherwise a blank page or a stack trace naming absolute paths, decided by a php.ini this
repository does not own. It depends on nothing — no `Response`, no view — so it cannot throw inside
itself.

### 1. Transport — HTTPS and HSTS

`public/.htaccess` redirects `http://` to `https://` before any PHP runs, and
`Strict-Transport-Security` (one year, `includeSubDomains`) tells the browser never to try plaintext
again. Both halves are load-bearing and neither is optional: both auth gates are HTTP Basic, Basic
is base64 rather than encryption, and the pre-launch gate runs on **every request that reaches
PHP**. A request that arrives in plaintext has already put its credentials on the wire — the
redirect fixes the *next* request, and HSTS removes there being a next plaintext one at all.

Read "every request that reaches PHP" literally, because `.htaccess` passes real files through
before the rewrite to `index.php`: **static assets are served without either gate**. So while the
pre-launch gate is up it covers the documents and not `/assets/**`. It is written down because "the
gate runs on every request" is the kind of sentence that gets relied on later. `SecurityHeaders`
records the same fact for its own half: static assets never reach PHP, so they get no security
headers either.

**The prod build deletes every source map** and the verify script asserts none ships and no shipped
module names one, so there is nothing on the live host for that gap to expose. `public/` still has
them — `tsconfig` sets `inlineSources`, so each carries the whole commented TypeScript — and that is
what the dev server serves: on a dev server bound to anything but localhost they are readable, and
the fix there is dropping `inlineSources` rather than relying on a strip that only happens at the
edge. ([history](history/security.md))

Two subtleties live here. Strato terminates TLS at its proxy, where `%{HTTPS}` can read `off` on a
request that was encrypted the whole way; `X-Forwarded-Proto` is the header telling the truth, so the
redirect asks both and fires only when both say plaintext. And `preload` is deliberately **not**
offered — it is a one-way commitment for the whole apex domain that is hard to walk back; the class
`StrictTransportSecurity` documents why, and ships a `ONE_DAY` value for ramping an estate you have
not yet checked.

### 2. Response headers — typed, and sent before anything can fail

`SecurityHeaders::send()` is the first statement after the handler, so the policy covers **every**
response, including the `401` the auth gate exits with, the `303` a download redirects with, and the
`405` the method gate refuses with. Every header is a typed object on both halves — a `HeaderName`
and a `HeaderValue`, so neither the name nor the grammar of the value is assembled as a string at a
call site. On the value side that is `CspDirective`, `CspKeyword`/`CspScheme`/`CspHost`
behind a `CspSource` interface, `ReferrerPolicy`, `PermissionsPolicyFeature`,
`StrictTransportSecurity`, `MimeType` — so a misspelled directive or an unquoted `'self'` is a parse
error at build time, not a header the browser silently drops.

The headers, as sent:

```
Strict-Transport-Security: max-age=31536000; includeSubDomains
Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self';
    img-src 'self' https://my.hidrive.com; frame-src https://w.soundcloud.com;
    base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'
Referrer-Policy: strict-origin-when-cross-origin
X-Content-Type-Options: nosniff
Permissions-Policy: geolocation=(), camera=(), microphone=(), payment=(), usb=(), midi=(), interest-cohort=()
```

Those five are `SecurityHeader`'s whole set, and a test asserts the enum and what is sent match
exactly. A document carries three more, which are about caching rather than security and so live in
`ResponseHeader`:

```
Cache-Control: no-cache
ETag: "…"
Vary: X-Requested-With
```

`no-cache` is not `no-store` — it means keep the copy and revalidate before reusing it. The one page
behind the admin gate says `no-store, private` instead, and opting out that way is also what stops
`ViewResponse` giving it a validator at all. See `ViewResponse::cacheHeaders()`.

The CSP's **absences** are the interesting part, because each is a scheme source someone debugging a
broken asset would paste straight back in:

- **No `'unsafe-inline'` on `script-src` or `style-src`.** No view emits an inline style or an event
  handler (a test enforces it), and the SoundCloud player sets its accent and attribution styling
  through the CSSOM rather than a `style` attribute, so there is nothing for the allowance to cover.
- **No `data:` on `img-src`.** Nothing references one — the cover placeholder is a file. A `data:`
  image is cheap; a `data:text/html` document runs script in the navigating origin, and an allowlist
  that has said `data:` once is easy to widen by accident. Both suites assert the absence.
  ([history](history/security.md))
- **No `report-uri`/`report-to`.** A report is a `POST`, which the `405` gate refuses; a third-party
  collector is a third-party origin receiving a request from every visitor before any consent; and a
  report's `document-uri`/`blocked-uri` is data the privacy policy does not claim. The policy is
  asserted at **build time** — both test suites pin the directive set — rather than observed at run
  time, which is the job a `report-uri` would otherwise do.
- `object-src 'none'`, `frame-ancestors 'none'`, `base-uri 'self'`, and `form-action 'self'` round it
  out. `form-action` on a site with no form is belt over braces, and stays because the day a form
  appears is not the day anyone will remember to add it.

`SecurityHeaders::send()` also **removes** a header. PHP appends `X-Powered-By` with its exact patch
version before any of this code runs, so `expose_php` in php.ini (not ours, on shared hosting) is
only half the switch; `header_remove()` is the half we have. It is invisible under CLI and the
built-in dev server — `header()`/`header_remove()` are no-ops there — and only observable under a
real SAPI, where the header is confirmed gone.

### 3 + 4. The method gate

`Router::dispatch()` matches the path first and then asks that route whether it answers the method,
refusing with a `405` before any controller is built. The `Allow` header is `Allow::readOnly()`,
derived by filtering `HttpMethod::cases()` on `isReadOnly()`, so it cannot advertise a verb the gate
does not honour — a hand-written `Allow: GET, HEAD` could drift; this cannot. An unrecognised method
(`REQUEST_METHOD` is whatever the client sent) parses to `null` rather than a guessed `GET`, and
`null` is not read-only — so a `PROPFIND` or a typo is refused, not silently treated as a read.

**The method question lives on the route, as a `MethodPolicy` rather than a set of methods.** That
is not a stylistic choice. A route carrying its own set would make the `405` name it, so
`PUT /api/update/v1/patch` would answer `Allow: GET, HEAD, POST` — and that `POST` is precisely the
fact the endpoint exists to hide; an unrecognised verb, being in no set, would make the refusal name
the whole set. So there are two policies, not ten sets: nine routes are `ReadOnly`, and `/api` is
`Delegated`, which means the router forms **no opinion at all** and the controller answers every
method itself. The only `Allow` the router ever sends is `GET, HEAD`.
([history](history/api.md))

**That `null` has a second job at the gate**, and it is the sharper half. `ApiGate::accepts()`
refuses an unrecognised method on its first line, before anything else — because the envelope binds
a method, and comparing `null->value` against it would be an uncaught `TypeError`: a `500` where an
absent address sends a `405`. One differing status code and the whole property is gone, to anybody
who types `BREW`. The verify script sweeps `BREW` alongside every real verb, at every depth.

### 2 (again). Parsing the request defensively

`Request::path()` is the one place a malformed request target is dealt with. It uses
`Uri\Rfc3986\Uri::parse()`, which returns **null** on a target it cannot read, so `??` is a real
guard. A genuinely unparseable target falls back to **its own path** — everything up to the first
`?` or `#`, which is what the parser would have answered had it succeeded — rather than to the home
page, which would be the quieter wrong.

That fallback **still matches placeholder routes**: `Route::matches()` compiles `{slug}` into
`([^/]+)`, which matches anything, so a malformed target reaches a controller like any other.
`RoutingTest` pins that it does and `RequestTest` pins the cut. What keeps a hostile slug out of a
response header is the realm handling under [Authentication](#3-again-authentication), not the
router.

Do not reintroduce `parse_url()`: it signals failure with `false` rather than null, which `??` does
not guard, and `GET ///` then reaches `rtrim()` as a `TypeError` — a `500` ahead of the router and
the method gate. ([history](history/security.md))

The path is matched **raw, not decoded**: `%2f` stays `%2f` and `%2e%2e` stays `%2e%2e`, so an
encoded slash cannot split a segment into an extra route parameter, and encoded dot-segments cannot
walk anywhere. Directory traversal toward the credentials is in any case structurally impossible —
`data/` lives **outside** the webroot, and the web server itself refuses `..` in a request path with
a `400` before the app is even reached.

### 4 (again). Routing

A route pattern compiles to a regex anchored with `\z`, not `$`: `$` also matches immediately before
a trailing newline, so `\z` is what actually means "the end of the string". Placeholders capture
`[^/]+`, matching is case-sensitive, and there is no dot-segment normalisation that could resolve a
decorated path onto a gated route.

### 3 (again). Authentication

Four gates. Three are HTTP Basic; the fourth is a signature, described under [The API](#the-api).

Two of the Basic gates — the pre-launch site gate and the admin gate — ask the same question of the
same shape of credentials file, so they ask it in one place: `Auth::accepts()`. The third is a
demo's, whose credential is a `PasswordHash` on the `Demo` object itself rather than in a file; it
is `Auth::admits()`. Both are public and return a `bool`, and both are the *decision* separated from
the `401` that follows it, the way `SecurityHeaders::headers()` is separate from `send()` — a method
that ends the request cannot be asserted against, so everything worth testing lives in the pair that
does not. All three end up in one private comparison.

- **Every comparison is constant-time, and neither is skipped when the other fails.** The password is
  `password_verify()`; the user name is `hash_equals()`, compared on every request just the same.
  Chaining the two with `&&` would leak what each individually does not: bcrypt is deliberately
  slow, so a wrong user name would return in microseconds while a right one paid the full cost — a
  difference measurable across a network that tells an attacker which half of the credential they
  already have. Both run every time; the results are combined afterwards.
- **An empty hash is an unconfigured gate, not one that accepts an empty password.** The repo ships
  `data/admin.php` with an empty `pass_hash`, and the guard refuses it out loud rather than relying on
  `password_verify('', '')` happening to be false.
- **The token is spelled once, at both ends.** `BasicChallenge` writes `Basic realm="…"` into the
  `401` and `Request::fromGlobals()` reads `Basic ` on the way back in, and both say
  `AuthScheme::Basic`. A mismatch there would make both gates refuse everything, identically, with
  nothing in any log. A payload with no colon is a user name and an empty password rather than a
  refusal, which costs nothing because an empty password matches no bcrypt digest.
- **A realm is checked, and a visitor's slug is encoded before it becomes one.** `BasicChallenge`
  refuses anything but RFC 9110's `qdtext` — no `"`, no `\`, never empty — which reports a realm
  built wrong *in this repository*. A demo's realm is named after its slug, which comes out of the
  URL, so `Auth::demoRealm()` `rawurlencode`s it first: a no-op for every real slug, and what keeps a
  hostile target a `401` rather than the `500` a throw would make of it. `header()` refuses a value
  containing CR or LF, so no second header is reachable either way. Do not rely on Strato's proxy
  percent-encoding a hostile target before PHP sees it — a bare Apache 2.4 does not.
  ([history](history/security.md))
- The **pre-launch** gate is switched off by the *absence* of `data/site_auth.php`, and that file is
  gitignored precisely so the repo copy cannot switch it on. The **admin** gate has no absent-file
  case: a missing `data/admin.php` is a broken deployment, and `require` says so loudly rather than
  leaving `/admin/stats` open. A **demo** gate has no absent case at all — a `Demo` cannot be
  constructed without a `PasswordHash`, so a demo that is reachable is a demo that is gated.
- **A demo that does not exist is refused identically to one whose password is wrong**, in status
  code *and* in elapsed time. A `404` for an unknown slug and a `401` for a known one is a catalogue
  of unreleased tracks, readable one guess at a time; and returning early on the unknown one would
  answer in microseconds where a real comparison pays bcrypt, so the uniform `401` would be undone by
  a stopwatch. `Auth::requireDemoAuth()` verifies against `PasswordHash::unmatchable()` — a real
  digest with no preimage — and then refuses. Same reasoning as the no-short-circuit rule above, one
  level out.
- **A demo's password gates the bytes, not only the page.** Its audio is under `data/`, which the web
  server does not serve, so `DemoAudioController` is the only route to it and it asks the same
  question. That is deliberately unlike a release, whose download is a `303` to a HiDrive share URL —
  a capability that can be forwarded and that outlives any password change. The gated responses also
  carry `no-store, private`, no `ETag` (so no `304` on a guessed validator) and
  `X-Robots-Tag: noindex, nofollow, noarchive`. See [demos.md](demos.md).
- **Only one credential fits in a request.** While `data/site_auth.php` exists, the pre-launch gate
  claims the `Authorization` header and no request can satisfy a demo gate — or a signed API call —
  as well. See [Known and accepted](#known-and-accepted).

**There is no CSRF surface, and that is a property rather than an oversight.** It rests on two
facts, either of which would be enough: the site sets no cookie and starts no session, so there is
no ambient credential for a cross-site request to ride; and there is no `<form>` anywhere, while the
Basic-authenticated routes are ones the browser sends credentials to because of the realm rather
than the origin. `/api` does accept a `POST`, and a cross-site `POST` to it cannot forge an ECDSA
signature. So there is no token, no `SameSite` attribute, and nothing for them to protect.
([history](history/security.md))

### 5. The response — output safety in the markup tree

**Nothing on the site builds HTML from a string.** A view returns a `Node`; a page is a tree of them;
the only code that writes a `<` is `Element` and `Doctype`, and a verify check fails the build if a
heredoc or a `'<tag'` literal appears anywhere else under `src/`. Two guarantees are enforced in
`Element::render()` — the *only* code that turns a node into markup — so they hold for any element
however it was built, including one assembled from an array:

- **Escaping happens in exactly one place.** An attribute value is escaped by rendering it as a
  `Text` node, so `htmlspecialchars` is called once on the whole site, with one stated set of flags.
  A test pins that call site.
- **A URL attribute is asked what scheme it names**, because escaping is the wrong tool for a URL and
  always was — `javascript:alert(1)` contains nothing `htmlspecialchars` touches. The allowlist is
  `https:`, `mailto:`, and site-relative. `http:` is absent because HSTS means we do not emit one;
  `data:` is absent because a `data:text/html` document runs script in the origin that navigated to
  it. A leading slash is **resolved**, not assumed to be local: PHP 8.5's WHATWG URL parser strips
  tab, CR and LF from a URL before parsing, so `//host`, `/\host` and `/\t\n/host` are all
  `https://host` to a browser — the value is resolved the way a browser would and accepted only if it
  lands back on the origin it started from. Every spelling is pinned by `HtmlTest`.

**Hand-authored markup goes through the same rules.** `MarkupParser` reads the two halves of the
privacy policy (`data/privacy.de.html`, `data/privacy.en.html`) into the tree, so a hand-authored
document is subject to every rule above rather than exempt from them — its element and attribute
names have to be ones this site emits, its text is escaped by `Text`, and its `href`s go through the
same scheme allowlist. That is what refuses an `onerror=`, and it is checked when the file loads
rather than trusted. `Element::containingHtml()` is the only way in, its call sites are pinned by a
test named for the fact, and it is never handed anything a request can influence. There is no node
that emits markup verbatim. ([history](history/security.md))

Two things follow from this that are worth stating plainly:

- **No request data reaches a URL attribute.** Release and profile pages render trusted data; the one
  place request input is reflected — the `404` page echoing the path into a terminal's `command` — is
  a *text* attribute, escaped by the rule above, and the client-side element sinks it via
  `textContent`, never `innerHTML`. So neither the HTML context nor the DOM context can break out.
- The **client** enforces the same origin rule as the server. `Navigation` intercepts internal link
  clicks by matching the `href` *attribute* but then uses the *resolved* `href`, reconciling the two
  so a protocol-relative or cross-origin URL is handed back to the browser rather than fetched and
  written into `#content`. Nothing the server emits is protocol-relative — `Element` refuses to write
  one — so both halves are the same check from opposite sides.

### Input validation at the boundary it is written

Values that come from the site's own data and config are validated at **construction**, so a bad
paste throws when the data file loads — where the mistake actually is — rather than `404`ing from a
file host, breaking a header, or rendering a dead link when a visitor arrives:

| Type | Invariant |
|---|---|
| `HiDriveLink` | share id is exactly nine alphanumerics |
| `CspHost` | a bare origin — scheme + host (+ optional port), no path or trailing slash |
| `MimeType` | a well-formed subtype token |
| `Profile` | an absolute `https://` URL |
| `Location` | an absolute `https://` URL — the one address the site emits in a header |

All of them anchor with `\z`, not `$`, because `$` also matches before a trailing newline — the same
rule the router's patterns follow. Each bad-input test provider carries a trailing-newline case.
([history](history/security.md))

### Privacy at rest

Download logging is **deliberately off**: `Config::DOWNLOAD_LOGGING` is `false`, and `log()` returns
on it *before* the log entry is built, so the referrer is never even read. There is no `report-uri`,
and no personal data is ever placed in a URL or query string. Turning logging on is a
privacy-policy decision before a code one — neither half of the policy (`data/privacy.de.html`,
`data/privacy.en.html`) makes a download-tracking claim, so both would have to be amended first.

## The API

`/api/{service}/{version}/{action}` is the one address family that writes. It exists because
deploying means `rsync -c` over a GVFS SFTP mount where a single `stat` costs **480 ms** and walking
`src/` alone costs **3.7 s**, across 269 files, for a payload that is **250 KB gzipped** — a figure
that grows with the codebase, so `php tools/push-update.php --dry-run` re-derives it. It replaces
minutes with one request.

**One `SitePath` case matches the whole family**, so a new service is an `ApiService` case and its
handlers, with no route to register, and it inherits the silence, the method policy, the key, the
serial rule and the indistinguishability sweep without a line arranging any of them.
`tools/api.php` resolves an address through the site's own `ApiService` and `ApiAction`, so a new
service needs no change there either. There are no aliases: an endpoint whose design is to be
unfindable does not want two doors. ([history](history/api.md))

`health` is the second service: an `ApiService` case, an action enum and a handler. What it reports
— the SAPI, the ini limits, whether each declared extension is present *and working*, where a PHP
diagnostic goes and the last one that got there, the host's own software and clock, and whether
every `data/` file is in place — is reconnaissance in anyone else's hands, which is exactly why it
is a service behind this signature rather than the public `/health` a monitor would ping. See
[deployment.md](deployment.md).

### It answers as though it is not there

An unsigned request gets **exactly** what the site gives for an address that does not exist: the
rendered `404` for a read method, the `text/plain` `405` with `Allow: GET, HEAD` for anything else,
and the same for a verb the site does not recognise. Not a `401`, which would prompt; not a `403`,
which would confirm; not a `405` naming `POST`, which would confirm more precisely.

That is a property of the structure rather than of two implementations kept in step: both responses
come from `UnroutedController`, the very object `Router` delegates to when no route matches at all.
`ApiController` hands it anything it will not verify. The verify script checks the claim over real
HTTP, per method **and per depth**, against `/no-such-page` — because the claim is about status
codes, headers and bodies, and only a real server has those.

**`public/api/` must never exist.** The webroot passes real files and directories straight through
(`RewriteCond !-f` / `!-d`), so a directory there would be answered by Apache — a listing or a
`403` — and `/api` would stop looking like a typo without a line of PHP being involved. The verify
script asserts nothing is there. A push cannot be what creates it either way: `public/` is a root,
so a member named `public/api/...` would be written — the assertion is about what is in the
repository, not about what a signed caller can do.

**That scope is deliberate: status, headers and bodies, and not timing.** A request carrying an
`NS1` credential reaches `ApiGate`, which reads the key and — once the frame parses — runs
`openssl_verify`; a path that matches no route never does either, because it never leaves
`UnroutedController`. The 2026-09-09 pentest measured the gap at about **180 µs** on localhost
between the `/api` shape and a typo of the same length, both carrying a well-formed-but-bogus `NS1`
header. It is not a usable oracle, and the reason is its precondition rather than its size: the gap
appears only for a caller already sending an `NS1`-framed `Authorization`, and knowing that scheme
exists — the source is public — already implies knowing `/api` does. It is well below WAN jitter,
and it vanishes entirely on a deployment holding no key, which is the one place the silence has to
be perfect. It is written down because the "same `null` reaching the same line" phrasing below
reads as a timing identity it does not claim; closing the axis for real would mean a constant-time
dummy verify on every unrouted path, which protects nothing a reader of this repository could not
already know.

**The gate verifies before it resolves**, which is what keeps that structural. Asking "does this
service exist" first would answer an unsigned caller through a different path depending on what they
guessed, and two paths that agree today are two paths free to stop agreeing. Verified first, a
service that does not exist and a signature that does not verify are the same `null` reaching the
same line. Past the gate the posture inverts: an unknown action is a real `404` with a sentence, a
verb that is not the action's is a real `405` naming the one that is, and only the key holder ever
sees either.

### The credential is a key the server cannot use

`data/update.pub` holds an **ECDSA P-256 public key**. The private half lives at
`~/.config/neurosys/update.key`, outside the repository entirely, and is the same arrangement the
SoundCloud refresh token has: no `.gitignore` entry and no rsync flag is what stands between it and
a webroot, because it was never in reach of either.

The server therefore holds nothing replayable. A full compromise of the account yields the public
half and no ability to push anything. That asymmetry is the reason this gate is a signature rather
than a fourth bcrypt digest — the other three gates protect pages, and this one protects the code
that serves them.

**P-256 rather than Ed25519, by measurement rather than taste.** `ext/sodium` is absent on the
development machine, and Ed25519 does not work through PHP's openssl binding at all — it fails with
`Provider routines::invalid digest`, because the binding drives the digest-based API and Ed25519 is
one-shot. P-256 was verified end to end on the live host before it was relied on.

**Its absence is the off switch, with the opposite polarity to `data/site_auth.php`.** No key file,
no endpoint, for everyone, forever. So a fresh clone and every machine that has not deliberately
been given a key are closed rather than open — worth reading twice, because the two files look
alike and mean opposite things.

`PublicKey` is the only `openssl_*` call site under `src/`, which the verify script pins the way it
pins `curl_` to one file under `tools/lib/`. It asks `=== 1`, because `openssl_verify()` returns
`1`, `0` **or `-1`**, and a call site written `if (openssl_verify(...))` would read the error case
as a pass. A second check asserts that nothing under `src/` names a signing or key-minting call at
all, so a private key arriving on the server would have nothing to use it.

### What a signature covers, and why replay is closed

The credential rides in `Authorization` as `NS1 <base64>`: a length-prefixed manifest and the
signature over it. The signature covers the manifest; the manifest covers the body by SHA-256. One
signature over a couple of hundred bytes therefore protects a payload of any size — **and a payload
of no size at all**, which is why it rides in a header: a `GET` has nothing to frame a credential
into, and every action after the first one is a read.

`ApiCredential::parse()` bounds every length in the frame against what is actually present before
using it as an offset, and each failure is an exception the gate turns into the same silence as any
other — a malformed credential is never a `500`. The verify script carries six malformed-credential
probes; the one over the header size limit is refused by Apache with a `400` before PHP sees it,
which is a refusal from the wrong layer but not one that says `/api` is there.

**The manifest binds the request, not just the payload.** It carries `method` and `path` beside the
digest, and both are checked against the request carrying them. Without them a credential would
authenticate *some* request rather than one: a credential minted for a read would replay as a
write, and one minted for one action would verify at another. ([history](history/api.md))

Three details of that binding are worth stating, because each is the kind of thing that is
discovered rather than read:

- The digest is checked for **every** method, never skipped for one "with no body". A read signs
  `sha256('')` and a size of zero, so no branch is needed — and a signed `GET` cannot smuggle a body
  past it for some later action to read unsigned.
- What `path` binds is `Request::path()`'s output, not the wire target: normalised, so a trailing
  slash is the same signed path. It is compared against that string directly and never against one
  rebuilt from the router's captures, because `SitePath::to()` `rawurlencode`s each value and is
  therefore **not** the inverse of `Route::matches()`.
- The **query string is not covered**, because nothing under `src/` reads one — no code touches
  `$_GET` or `QUERY_STRING`. An API action must therefore never read a query parameter: it would be
  the one input reaching a verified caller's handler unsigned. A parameter belongs in the manifest
  or in the body. `health` has an obvious temptation here — a `?verbose` or a `?section` — and takes
  none: it reports everything it reports, always, and the note saying so is on `HealthReport` itself
  rather than only here.

**Cross-deployment replay is closed by key separation rather than by an audience field.**
`data/update.pub` is gitignored, per-deployment and uploaded by hand, so no two deployments hold the
same key and a credential minted for one verifies nowhere else. An `aud` field would have to be
checked against something the server knows independently of the request, and `Host` is whatever the
caller sent — so it would bind nothing. If a second deployment ever shares this key, that is the
field to add.

The manifest also carries a `serial` doing double duty: it must be within **±300 s** of the server's
clock *and* strictly greater than the highest serial already accepted, which is recorded at
`cgi-bin/.update-serial` — above the webroot, in neither mirrored tree, and in no rsynced one. The
monotonic half alone would accept a credential signed long ago and never sent; the skew half alone
would leave a five-minute replay window. A **dry run deliberately does not advance the serial**, and
neither does a **read**, so a captured one replays to nothing and a real push of the same payload is
still possible. A read not spending one is also what lets two calls be made in the same second,
since a serial is `time()` and the rule is strictly greater.

The accepted cost of that is narrow and stated rather than left implicit: a captured **read**
credential is replayable for the remainder of the skew window. What it yields is the answer to a
read — the deployed serial, build stamp and PHP version — to somebody who has already broken TLS.
Any write landing in the meantime kills it, since the monotonic half moves.

**A write spends its serial before the action runs, not after.** So a failure to record it is a
refusal with nothing written, rather than a deployment that has been updated by a credential that
could update it again. It also means a push that fails partway has still spent its serial, which is
correct: the bytes that produced it must never be accepted twice, and a corrected payload is
different bytes with a fresh `time()` on them anyway. ([history](history/api.md))

**Why a header is acceptable here.** `Authorization` is a `ServerVariable`, not a `RequestHeader`,
so it has no TypeScript mirror and puts nothing in the browser's bundle. The live risk is that a
header is the part of a request most likely to be rewritten in transit, which is exactly why
`public/.htaccess` puts this one back with `E=HTTP_AUTHORIZATION` and `Request::authorization()`
reads both spellings. Both Basic gates already depend on this header surviving Strato, which is the
strongest evidence available that it does — and it is the one thing here that should be re-checked
on the live host rather than reasoned about, because a proxy that strips it fails **closed and in
silence**, looking exactly like a bad key. ([history](history/api.md))

The size is arithmetic against a real limit rather than a guess. `LimitRequestFieldSize` is 8190
bytes for the whole field line; `Authorization: NS1 ` is 19 of them; base64 is 4 out for every 3 in;
the frame carries a 4-byte length and at most 256 bytes of signature. A 2048-byte manifest cap
therefore costs at most **3099 bytes**, leaving 62% of the line spare. Measured against a real P-256
pair, a push's header is **367 bytes** and a read's **331**, and the DER signature is **71**.

### What a verified payload may write

Three roots, and `data` is conspicuously not among them: `public/`, `src/`, and the single file
`autoload.php`. That one rule is what keeps `data/admin.php`, `data/site_auth.php`, `data/demos.php`
and 8.6 MB of unreleased audio out of reach of any push, however well signed — there is no
destination to compute for them rather than a destination computed and then rejected.

Every member of the archive is checked **before anything is written**, so a payload with one bad
name writes nothing at all:

- the tar reader is hand-rolled rather than `PharData`, because `PharData::extractTo()` decides for
  itself what a member name means and what a link points at, and those are exactly the decisions
  that must not be delegated when the names came off the network;
- only a **regular file or a directory** survives. A symlink, a hardlink, a device node, a fifo, a
  GNU long-name record and a pax header are each refused **by name** — not skipped, refused, since an
  archive containing one is not an archive this site produced;
- a name must match `[A-Za-z0-9._-]` segments separated by `/`, with no leading slash, no backslash,
  no empty segment and no `.` or `..`. There is **no `..` handling and no `realpath()` fallback**: a
  name that would need either is refused outright, which is why nothing downstream carries a
  traversal guard;
- the ustar header checksum is verified, because a signature says the bytes are ours and the
  checksum says they are a tar — and in a format that is nothing but offsets, bad framing means every
  name after it is read out of the middle of somebody's file.

Each file then lands through `File::write()`, which writes beside the target and renames over it, so
every file appears atomically and within one filesystem. A file whose bytes are already there is
left alone — see [deployment.md](deployment.md) for why that matters on NFS. The mirror that removes
what a payload omits is an **enumerated delete**: the tree is walked, diffed, and each surplus path
is checked by the same rules an added path passes before `File::delete()` is called on it, one named
file at a time. `Directory::remove()` is never used for it — that method deletes the files a
directory holds, which is right for tearing down a fixture and catastrophic here.

**The walk never follows a symlink.** `UpdateApplier`'s walk and sweep ask `!is_link()` before
descending, so a stray link under a root is a leaf, and unlinking it removes the link and not what
it points at. No payload can carry one — `TarArchive` refuses a symlink member — so a link there is
the mark of a compromise that already holds the filesystem, and the one code path here that deletes
must not be a second way outside the roots. `UpdateTest` plants one and asserts the target survives.
([history](history/security.md))

### Where the roots resolve

`Deployment` maps a root to a directory; `UpdateRoot` is only the vocabulary. They are separate
because deciding whether a name is *under* `public/` must never require resolving where `public/`
**is** — the second question reaches `DOCUMENT_ROOT`, and the first is asked of names off the
network. ([history](history/api.md))

`Config::webroot()` takes only the **basename** of `DOCUMENT_ROOT` and hangs it off the derivation
every other path here uses, because on the live host the two spellings of one directory are
genuinely different strings — `/home/strato/…/cgi-bin/neurosys` against `/mnt/web505/…/cgi-bin/neurosys`
— and a path built from the wrong one compares equal to nothing. A basename grafted onto a different
tree names a real directory somewhere else, so it **refuses** rather than guesses, because every
candidate guess is a directory something would then be willing to delete:

- a `DOCUMENT_ROOT` that is unset or blank after trimming;
- one that is not absolute — for a bare relative name `dirname()` is `.`, whose realpath is the
  working directory, which under the test runner is the deployment itself;
- one that is not a directory inside the deployment, comparing `realpath()` on both sides;
- one that names no directory, even when its parent is the deployment — otherwise the first push
  would create a second webroot beside the real one and write the whole site into it.

And `UpdateApplier` takes its `Deployment` as a constructor argument, so a test is not merely
unlikely to reach the live tree — it cannot.

## Known and accepted

What is open, or accepted as a decision, with the reason each is where it is. Everything else the
assessments turned up is fixed — see [history/security.md](history/security.md).

- **Static assets reach neither gate and carry no security headers.** `.htaccess` passes real files
  through before the rewrite to `index.php`, so while the pre-launch gate is up it covers documents
  and not `/assets/**` — see [Transport](#1-transport--https-and-hsts). The same is true of the
  debug tree's source maps on a dev server bound beyond localhost.
- **`TRACE` is answered by the server, not the site — low severity, and not reachable through this
  code at all.** Apache's default `TraceEnable On` answers a `TRACE` before the request reaches
  `index.php`, echoing the request's own headers back in a `message/http` body — and because it never
  reaches PHP, that response carries none of the security headers a real one does. It is the same
  "static assets never reach PHP" gap, one step worse because the echoed body is attacker-shaped. It
  is bounded on every side that matters: a browser refuses to issue a cross-origin `TRACE`, so the
  classic cross-site-tracing route to a stored credential is closed in the client; the site sets no
  cookie to harvest; and the `Authorization` header both gates read is not one a cross-site `TRACE`
  could originate. It cannot be fixed where the rest of this repository's Apache config lives —
  `TraceEnable` is a server-level directive, invalid in `.htaccess`, so the only lever on shared
  hosting is a `RewriteRule` that refuses the method at the edge. **Open:** a `curl -X TRACE`
  against the live host would say which way Strato has it; the local rig has it on, and the local
  rig is not Strato. (An overlong target is the server's to answer too, and does: past roughly 4 KB
  Apache cannot map the path to a file and returns `AH00127`/`403`, and past its request-line limit
  a `414` — both route-independent, and neither reaching a real slug.)
- **The SoundCloud `secret-token` is public in a release page — by design.** A private or scheduled
  track embeds with a `secret-token`, and the release page that plays it is public, so the token is
  rendered into public HTML where anyone can read it. That is correct: the token is a per-track
  capability to *play an unlisted track*, not a credential to the account, and a public page whose
  whole purpose is to play the track is exactly where it belongs. It is written down because
  "secret" reads like something the page ought to be hiding and it is not — a track given one here
  is as playable as a public one to anyone who loads the page, which is the intended reach and worth
  confirming against the intent for each release.
- **`FileResponse` and `ByteRange` have been reviewed, not fuzzed.** The demo-audio byte-range path
  sits behind a demo's password, so the `Range`-header fuzzing that would belong here (suffix
  ranges, a `0-0` probe, a backwards `500-100`, offsets past the end, the `416` boundary) was read
  for correctness rather than fired at a running server: the 256 KB chunked stream never holds a
  whole file in memory, and the `min(chunk, remaining)` read cannot overshoot a range's end. It is
  the one newer parser no pass has reached without a credential.
- **About 180 µs separates `/api` from a typo for a caller already sending an `NS1` credential.**
  Not a usable oracle; see [It answers as though it is not there](#it-answers-as-though-it-is-not-there).
- **A captured read credential replays within the ±300 s skew window**, yielding the answer to a
  read to somebody who has already broken TLS. See
  [What a signature covers](#what-a-signature-covers-and-why-replay-is-closed).
- **No audience field.** Cross-deployment replay is closed by key separation; if two deployments
  ever share a key, an `aud` field is what to add.
- **While the pre-launch site gate is on, no signed API call and no demo can be reached.**
  `requireSiteAuth()` runs before the router and is HTTP Basic on the same `Authorization` header,
  so while `data/site_auth.php` exists the API credential is in the wrong scheme, the gate sees an
  empty user, and the request is a `401`. That is the interaction demos already have, and it leaks
  nothing — that gate answers `401` for *every* path alike, so `/api` is no more visible than
  `/imprint`. It is moot today, because the gate is off.

  The fix, if it is ever needed, is a decision rather than a patch, and the shape matters: standing
  the site gate down whenever an `NS1` header is merely **present** would make `/api` answer `404`
  where every other path answers `401` — a perfect oracle. It has to stand down only for a request
  whose signature has already **verified**, which means running the gate once, before
  `requireSiteAuth()`, and handing the result on.

## What is deliberately not here

- **No web-application firewall, no rate limiting layer.** This is a static site on shared hosting;
  the perimeter is the host's.
- **No CSRF tokens, no `SameSite` cookies.** There is nothing for them to protect — see the CSRF
  paragraph under [Authentication](#3-again-authentication). The CSP still carries
  `form-action 'self'`, which on a site with no forms is belt over braces and stays because the day
  a form appears is not the day anyone will remember to add it.
- **No cookie or consent banner** for the site itself — it sets no cookie. The one consent gate is on
  the SoundCloud embed, which contacts no third party until the visitor clicks: the served HTML
  contains no SoundCloud address at all for a browser to preconnect or prefetch, and the notice is
  written by the element that would do the loading.
- **No `data:` URLs, no inline scripts or styles, no third-party script.**
- **No outbound request from the server.** `index.php` answers requests and never issues one, so
  there is no URL a request can steer and nothing that carries a visitor's address to a third party
  behind their back. The verify script asserts it, by grepping `src/` for `curl_*`, `fsockopen` and
  `stream_socket_client`. The SoundCloud upload client is **tooling**: it lives under `tools/`,
  which `deploy.sh` never uploads, it runs on a laptop, and its credentials are environment
  variables with the rotating token kept outside the repository entirely.

Each of these is a property that falls out of the site's shape, not a control that was considered and
skipped. That is the whole posture in one sentence: **make the class impossible, then enforce the few
remaining invariants where they are written and where they are rendered.**
