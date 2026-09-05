# Security

This document is the security posture of the site in one place: the philosophy, the layered
hardenings, the invariants each layer enforces, and the findings of the assessment that this
document also came out of. It describes what the code does and, more usefully, *why* — an absence is
worth writing down when it is a decision rather than an oversight, and most of the security here is
absences.

The short version: **the shape of the site is its first defense.** It is read-only, static, has no
database, sets no cookie, starts no session, has no `<form>`, accepts no upload, persists nothing a
request sends, and has no runtime dependencies. Whole classes of vulnerability are not mitigated
here — they are structurally absent. What remains is enforced at type boundaries and at the single
place markup is rendered, so the failure mode of a mistake is a build error or a thrown exception,
not a silently shipped hole.

## The attack surface

Everything an attacker can reach:

- **Seven routes**, all `GET`/`HEAD`: `/`, `/releases`, `/releases/{slug}`,
  `/releases/{slug}/{format}`, `/admin/stats` (behind HTTP Basic), `/imprint`, `/privacy`.
- **Static assets** under `/assets/`, served by the web server, never by PHP.
- Everything else answers `404` or `405`.

Everything an attacker controls: the **request target** (the path), the **method**, and a few
**request headers** the app reads — `Authorization`, `X-Requested-With`, and (only when download
logging is on, which it is not) `Referer`. Nothing a request carries is written anywhere; there is
no state-changing verb the site honours.

What is *not* in the surface, and the bug class each absence removes:

| Not present | Class it removes |
|---|---|
| No database, no SQL | SQL injection |
| No cookie, no session | session fixation/hijack; the ambient credential CSRF rides |
| No `<form>`, no state-changing route | CSRF target; mass-assignment |
| No file upload, no user content | stored XSS; upload/path abuse |
| No `unserialize()` of request data | object injection |
| No shell-out, no `eval`, no dynamic include of request data | command injection; LFI/RFI |
| No third-party script, no CDN | supply-chain script injection |

These are the reason the rest of this document is short. You cannot exploit a feature that isn't
there.

## Defense in depth, following `index.php`

The front controller is five statements, and they are the spine of the request:

```php
SecurityHeaders::send();                 // 1. headers first — cover every response
$request = Request::fromGlobals();       // 2. parse the request defensively
Auth::requireSiteAuth($request);         // 3. the pre-launch gate
new Router(RouteInitialization::routes())->dispatch($request)->send($request);  // 4 + 5. route, respond
```

### 1. Transport — HTTPS and HSTS

`public/.htaccess` redirects `http://` to `https://` before any PHP runs, and
`Strict-Transport-Security` (one year, `includeSubDomains`) tells the browser never to try plaintext
again. Both halves are load-bearing and neither is optional: both auth gates are HTTP Basic, Basic
is base64 rather than encryption, and the pre-launch gate runs on **every request that reaches
PHP**. A request that arrives in plaintext has already put its credentials on the wire — the
redirect fixes the *next* request, and HSTS removes there being a next plaintext one at all.

Read "every request that reaches PHP" literally, because `.htaccess` passes real files through
before the rewrite to `index.php`: **static assets are served without either gate**. So while the
pre-launch gate is up it covers the documents and not `/assets/**` — including the source maps,
which carry the whole commented TypeScript because `tsconfig` sets `inlineSources`. That is a
non-issue here, since the source is public regardless; it is written down because "the gate runs on
every request" is the kind of sentence that gets relied on later. `SecurityHeaders` records the same
fact for its own half: static assets never reach PHP, so they get no security headers either.

Two subtleties live here. Strato terminates TLS at its proxy, where `%{HTTPS}` can read `off` on a
request that was encrypted the whole way; `X-Forwarded-Proto` is the header telling the truth, so the
redirect asks both and fires only when both say plaintext. And `preload` is deliberately **not**
offered — it is a one-way commitment for the whole apex domain that is hard to walk back; the class
`StrictTransportSecurity` documents why, and ships a `ONE_DAY` value for ramping an estate you have
not yet checked.

### 2. Response headers — typed, and sent before anything can fail

`SecurityHeaders::send()` is the first statement, so the policy covers **every** response, including
the `401` the auth gate exits with, the `303` a download redirects with, and the `405` the method
gate refuses with. Every header is a typed object — `CspDirective`, `CspKeyword`/`CspScheme`/`CspHost`
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
  handler (a test enforces it); the SoundCloud player sets its accent through the CSSOM rather than a
  `style` attribute, so the one thing `'unsafe-inline'` used to cover no longer needs covering.
- **No `data:` on `img-src`.** It was allowed on the strength of a comment about a cover placeholder
  that references nothing at all. A `data:` image is cheap; a `data:text/html` document runs script
  in the navigating origin, and an allowlist that has said `data:` once is easy to widen by accident.
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

### 3 + 4. The read-only method gate

`Router::dispatch()` answers anything but `GET`/`HEAD` with a `405`, *before* it looks at the route.
The `Allow` header is built from `HttpMethod::allowed()`, which filters the cases by `isReadOnly()`,
so the header cannot advertise a verb the gate does not honour — a hand-written `Allow: GET, HEAD`
could drift; this cannot. An unrecognised method (`REQUEST_METHOD` is whatever the client sent)
parses to `null` rather than a guessed `GET`, and `null` is not read-only — so a `PROPFIND` or a typo
is refused, not silently treated as a read.

### 2 (again). Parsing the request defensively

`Request::path()` is the one place a malformed request target is dealt with, and it does two things
worth knowing. It uses `Uri\Rfc3986\Uri::parse()`, which returns **null** on a target it cannot read
— so a genuinely unparseable target falls back to the target **verbatim**, matches no route, and
`404`s, rather than being answered with the home page (the quieter wrong). This replaced a
`parse_url()` that signalled failure with `false`, where a `?? '/'` read as a guard and was not one,
and `GET ///` reached `rtrim()` as an uncaught `TypeError` — a `500` ahead of the router and ahead of
the method gate. That trap is gone rather than guarded against.

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

Two gates, both HTTP Basic, both asking the same question of the same shape of credentials file, so
they ask it in one place — `Auth::accepts()`, which is public and returns a `bool`. The gate's
*decision* and the `401` it exits with are separate, the way `SecurityHeaders::headers()` is separate
from `send()`, because a method that ends the request cannot be asserted against; everything worth
testing lives in `accepts()`.

- **Every comparison is constant-time, and neither is skipped when the other fails.** The password is
  `password_verify()`; the user name is `hash_equals()`, compared on every request just the same.
  Chaining the two with `&&` leaked what each individually does not: bcrypt is deliberately slow, so a
  wrong user name returned in microseconds while a right one paid the full cost — a difference
  measurable across a network that tells an attacker which half of the credential they already have.
  Both run every time; the results are combined afterwards.
- **An empty hash is an unconfigured gate, not one that accepts an empty password.** The repo ships
  `data/admin.php` with an empty `pass_hash`, and the guard refuses it out loud rather than relying on
  `password_verify('', '')` happening to be false.
- The **pre-launch** gate is switched off by the *absence* of `data/site_auth.php`, and that file is
  gitignored precisely so the repo copy cannot switch it on. The **admin** gate has no absent-file
  case: a missing `data/admin.php` is a broken deployment, and `require` says so loudly rather than
  leaving `/admin/stats` open.

**There is no CSRF surface, and that is a property rather than an oversight.** It rests on three
independent facts, any one of which would be enough: the site sets no cookie and starts no session,
so there is no ambient credential for a cross-site request to ride; there is no `<form>` and the only
state-changing verb is refused by the `405` gate; and the one authenticated route is Basic, where the
browser sends credentials because of the realm rather than the origin. So there is no token, no
`SameSite` attribute, and nothing for them to protect.

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

`RawHtml` is the single audited hole. It exists for `data/privacy.html`, a hand-authored document,
its call sites are pinned by a test named for the fact, and it is never constructed from anything a
request can influence.

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

All four anchor with `\z`, not `$` — the same "a trailing newline is not the end of the string"
lesson the router already learned (see the finding below).

### Privacy at rest

Download logging is **deliberately off**: `Config::DOWNLOAD_LOGGING` is `false`, and `log()` returns
on it *before* the log entry is built, so the referrer is never even read. There is no `report-uri`,
and no personal data is ever placed in a URL or query string. Turning logging on is a
privacy-policy decision before a code one — `data/privacy.html` makes no download-tracking claim, so
it would have to be amended first.

## The assessment (2026-09)

This document was written alongside a security assessment of the site — a deliberate attempt to break
a local copy with malformed and fuzzed requests, weird `cURL` and raw-socket traffic, and direct
fuzzing of the application logic. The pass covered: path and encoding fuzzing (traversal, null bytes,
overlong targets, the `///` regression, encoded slashes and dot-segments); reflected-XSS attempts on
the `404` page; the method gate; the Basic-Auth header parser (malformed base64, missing/extra
colons, null bytes, wrong scheme); the URL-scheme guard (`javascript:`/`data:`/`vbscript:` in every
case and whitespace spelling, and the WHATWG authority tricks); the log parser; and raw malformed
HTTP at the wire (missing `Host`, absolute-form targets, header bombs, `CL`/`TE` smuggling probes).

**No exploitable issue was found.** The defenses above held on every axis: the escaping and the
`textContent` sink defeated the reflected-XSS attempts, the scheme guard blocked every dangerous URL
and whitespace bypass, the parser refused every malformed target without a `500`, the method gate
returned the correct `405` (and refused a smuggling probe outright), the auth parser survived every
malformed `Authorization` header, and the log parser returned `null` on junk rather than throwing.
Traversal toward `data/` was refused with a `400` by the web server, and the credentials sit outside
the webroot regardless.

One **finding**, low severity and now fixed: `HiDriveLink`, `CspHost` and `MimeType` anchored their
validation regexes with `$` rather than `\z`, so a value with a **trailing newline** passed a check
that claims an exact shape (`"BXRsy9S7d\n"` satisfied "exactly nine alphanumerics"). This contradicted
an invariant the codebase documents twice — `Route` and `Profile` already used `\z` for exactly this
reason. It was not request-reachable (all three inputs come from trusted data/config, never from a
request) and was bounded downstream (PHP's `header()` refuses a value containing a newline, so the
`CspHost` case could not have become header injection), but a validator that does not mean what it
says is worth closing. All end-anchors in the source are `\z` now, and each of the three bad-input
test providers gained a trailing-newline case so it cannot regress.

## What is deliberately not here

- **No web-application firewall, no rate limiting layer.** This is a static site on shared hosting;
  the perimeter is the host's.
- **No CSRF tokens, no `SameSite` cookies.** There is no ambient credential and no state-changing
  request for them to protect.
- **No cookie or consent banner** for the site itself — it sets no cookie. The one consent gate is on
  the SoundCloud embed, which contacts no third party until the visitor clicks: the served HTML
  contains no SoundCloud address at all for a browser to preconnect or prefetch, and the notice is
  written by the element that would do the loading.
- **No `data:` URLs, no inline scripts or styles, no third-party script.**

Each of these is a property that falls out of the site's shape, not a control that was considered and
skipped. That is the whole posture in one sentence: **make the class impossible, then enforce the few
remaining invariants where they are written and where they are rendered.**
