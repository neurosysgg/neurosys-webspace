# Security

This document is the security posture of the site in one place: the philosophy, the layered
hardenings, the invariants each layer enforces, and the findings of the assessment that this
document also came out of. It describes what the code does and, more usefully, *why* — an absence is
worth writing down when it is a decision rather than an oversight, and most of the security here is
absences.

The short version: **the shape of the site is its first defense.** It is static, has no database,
sets no cookie, starts no session, has no `<form>`, and has no runtime dependencies. Whole classes
of vulnerability are not mitigated here — they are structurally absent. What remains is enforced at
type boundaries and at the single place markup is rendered, so the failure mode of a mistake is a
build error or a thrown exception, not a silently shipped hole.

**One sentence used to be longer.** This document said the site was read-only, accepted no upload
and persisted nothing a request sends. That is no longer true: `/api` accepts a POST carrying a
gzipped tarball and writes it into `src/` and the webroot. It is the deploy path, replacing minutes
of `rsync -c` over an SFTP mount where a single `stat` costs 480 ms, and it is described in full
under [The update endpoint](#the-update-endpoint) below. Everything else in this document still
holds, and the claims that changed are corrected where they appear rather than quietly reworded.

## The attack surface

Everything an attacker can reach:

- **Ten routes.** Nine are `GET`/`HEAD`: `/`, `/releases`, `/releases/{slug}`,
  `/releases/{slug}/{format}`, `/demos/{slug}` and `/demos/{slug}/{label}` (each behind that demo's
  own HTTP Basic password), `/admin/stats` (behind HTTP Basic), `/imprint`, `/privacy`.
  There is deliberately no `/demos` index — see [demos.md](demos.md).
- **The tenth is `/api/{service}/{version}/{action}`**, which accepts a `POST` and answers every
  method exactly as an address that does not exist, unless the request carries an ECDSA signature
  this deployment's public key verifies. It is unreachable without the private key and invisible
  without it — at every depth: `/api`, `/api/update` and `/api/update/v1` match no route at all,
  because the pattern is four segments.
- **Static assets** under `/assets/`, served by the web server, never by PHP. The one exception is a
  demo's audio, which PHP serves itself precisely so that it is *not* static — see below.
- Everything else answers `404` or `405`.

Everything an attacker controls: the **request target** (the path), the **method**, a few
**request headers** the app reads — `Authorization`, `X-Requested-With`, and (only when download
logging is on, which it is not) `Referer` — and, under `/api` alone, a **request body**.

That body is read at one call site, and **it is not read at all until a signature has verified**.
The credential now arrives in `Authorization` rather than framed into the body, so an unsigned
caller is refused before `php://input` is touched, and the read that does happen is bounded by the
*signed* length rather than by a fixed cap. Its predecessor read up to 8 MB before it could refuse
anybody, which this document previously named as the one thing that could tell the endpoint from a
typo — by how long it took to drain.

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
pre-launch gate is up it covers the documents and not `/assets/**`. It is written down because "the
gate runs on every request" is the kind of sentence that gets relied on later. `SecurityHeaders`
records the same fact for its own half: static assets never reach PHP, so they get no security
headers either.

That used to include the source maps, which carry the whole commented TypeScript because `tsconfig`
sets `inlineSources` — 79,354 bytes of it, ungated. **The prod build deletes every one of them** and
asserts no shipped module still names one, so on the live host there is nothing there to be ungated;
the verify script checks both halves. It was a non-issue either way, since the source is public on
GitHub, but that was a reason not to worry about it rather than a reason to serve a second copy.
The maps still exist in `public/`, which is what the dev server serves — so on a dev server bound to
anything but localhost they are exactly as readable as they ever were, and the fix there is dropping
`inlineSources` rather than relying on a strip that only happens at the edge.

Two subtleties live here. Strato terminates TLS at its proxy, where `%{HTTPS}` can read `off` on a
request that was encrypted the whole way; `X-Forwarded-Proto` is the header telling the truth, so the
redirect asks both and fires only when both say plaintext. And `preload` is deliberately **not**
offered — it is a one-way commitment for the whole apex domain that is hard to walk back; the class
`StrictTransportSecurity` documents why, and ships a `ONE_DAY` value for ramping an estate you have
not yet checked.

### 2. Response headers — typed, and sent before anything can fail

`SecurityHeaders::send()` is the first statement, so the policy covers **every** response, including
the `401` the auth gate exits with, the `303` a download redirects with, and the `405` the method
gate refuses with. Every header is a typed object on both halves — a `HeaderName` and a
`HeaderValue`, so neither the name nor the grammar of the value is assembled as a string at a call
site. On the value side that is `CspDirective`, `CspKeyword`/`CspScheme`/`CspHost`
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

### 3 + 4. The method gate

`Router::dispatch()` matches the path first and then asks that route whether it answers the method,
refusing with a `405` before any controller is built. The `Allow` header is `Allow::readOnly()`,
derived by filtering `HttpMethod::cases()` on `isReadOnly()`, so it cannot advertise a verb the gate
does not honour — a hand-written `Allow: GET, HEAD` could drift; this cannot. An unrecognised method
(`REQUEST_METHOD` is whatever the client sent) parses to `null` rather than a guessed `GET`, and
`null` is not read-only — so a `PROPFIND` or a typo is refused, not silently treated as a read.

**The question moved onto the route when the signed endpoint arrived**, and it is a `MethodPolicy`
rather than a set of methods. That is not a stylistic choice. A route carrying its own set would
make the `405` name it, so `PUT /api/update/v1/patch` would answer `Allow: GET, HEAD, POST` — and
that `POST` is precisely the fact the endpoint exists to hide. An unrecognised verb was worse:
`null` is in no set, so the refusal would have named the whole set. So there are two policies, not
ten sets: nine routes are `ReadOnly` and refuse exactly as the old global gate did, and `/api` is
`Delegated`, which means the router forms **no opinion at all** and the controller answers every
method itself. The only `Allow` the router ever sends is still `GET, HEAD`.

**That `null` has a second job at the gate**, and it is the sharper half. `ApiGate::accepts()`
refuses an unrecognised method on its first line, before anything else — because the envelope binds
a method, and comparing `null->value` against it would be an uncaught `TypeError`: a `500` where an
absent address sends a `405`. One differing status code and the whole property is gone, to anybody
who types `BREW`.

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

Four gates. Three are HTTP Basic; the fourth is a signature and is described in
[The update endpoint](#the-update-endpoint).

Three gates, all HTTP Basic. Two of them — the pre-launch site gate and the admin gate — ask the same
question of the same shape of credentials file, so they ask it in one place: `Auth::accepts()`. The
third is a demo's, whose credential is a `PasswordHash` on the `Demo` object itself rather than in a
file; it is `Auth::admits()`. Both are public and return a `bool`, and both are the *decision*
separated from the `401` that follows it, the way `SecurityHeaders::headers()` is separate from
`send()` — a method that ends the request cannot be asserted against, so everything worth testing
lives in the pair that does not. All three end up in one private comparison.

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
  claims the `Authorization` header and no request can satisfy a demo gate as well — so demos are
  unreachable, not weakly reachable. Currently moot (the gate is off), and written down in
  [demos.md](demos.md) so it is a known interaction rather than a surprise.

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

**There is no unaudited hole, and there used to be one.** `RawHtml` emitted `data/privacy.*.html`
verbatim; `MarkupParser` reads those two files into the tree instead, so a hand-authored document is
subject to every rule above rather than exempt from them — its element and attribute names have to be
ones this site emits, its text is escaped by `Text`, and its `href`s go through the same scheme
allowlist. That is what refuses an `onerror=`, and it is checked when the file loads rather than
trusted. `Element::containingHtml()` is the only way in, its call sites are pinned by a test named
for the fact, and it is never handed anything a request can influence.

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

## The API

`/api/{service}/{version}/{action}` is the one address family that writes. It exists because
deploying meant `rsync -c` over a GVFS SFTP mount where a single `stat` costs **480 ms** and walking
`src/` alone costs **3.7 s**, across 269 files, for a payload that is **209 KB gzipped**. It
replaces minutes with one request.

It was `/update`, one address with one verb. Generalising it was cheaper than adding a second
endpoint beside it: every property below would have had to hold for the next owner-only tool too,
and the choice was to arrange them once more or to arrange them once. `/update` is gone rather than
aliased — an endpoint whose design is to be unfindable does not want two doors.

It is also the one place this document's other claims had to be re-argued rather than restated, so
the argument is here in full.

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
same line. Past the gate the posture inverts as it always did: an unknown action is a real `404`
with a sentence, a verb that is not the action's is a real `405` naming the one that is, and only
the key holder ever sees either.

### The credential is a key the server cannot use

`data/update.pub` holds an **ECDSA P-256 public key**. The private half lives at
`~/.config/neurosys/update.key`, outside the repository entirely, and is the same arrangement the
SoundCloud refresh token has: no `.gitignore` entry and no rsync flag is what stands between it and
a webroot, because it was never in reach of either.

The server therefore holds nothing replayable. A full compromise of the account yields the public
half and no ability to push anything. That asymmetry is the reason this gate is a signature rather
than a tenth bcrypt digest — the other three gates protect pages, and this one protects the code
that serves them.

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
of no size at all**, which is the whole reason it moved out of the body. A `GET` has nothing to
frame a credential into, and every action after the first one is a read.

**The manifest binds the request, not just the payload.** It carries `method` and `path` beside the
digest, and both are checked against the request carrying them. Without them a credential
authenticates *some* request rather than one: a credential minted for a read would replay as a
write, and one minted for one action would verify at another. That was sound while `/update` was
the only address there was to sign for and stops being sound the moment there are two.

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
  or in the body.

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

**A write spends its serial before the action runs, not after**, which is the one ordering this
endpoint changed. The old order wrote the whole tree and then discovered it could not record the
serial, leaving a deployment that had been updated and a credential that could update it again —
and nothing useful to do but say so in the report. Arming first turns that into a refusal with
nothing written. It means a push that fails partway has still spent its serial, which is correct:
the bytes that produced it must never be accepted twice, and a corrected payload is different bytes
with a fresh `time()` on them anyway.

**Why a header is acceptable here**, given this document's earlier objection to one: that objection
had two halves and only one survives. The first was that `RequestHeader` is mirrored in TypeScript
and compared case-for-case, so header-carried metadata puts cases in the browser's bundle no browser
reads — but `Authorization` is a `ServerVariable`, not a `RequestHeader`, and has no mirror. The
second, that a header is the part of a request most likely to be rewritten in transit, is still live
and is exactly why `public/.htaccess` puts this one back with `E=HTTP_AUTHORIZATION` and
`Request::authorization()` reads both spellings. Both Basic gates already depend on this header
surviving Strato, which is the strongest evidence available that it does — and it is the one thing
in this feature that should be re-checked on the live host rather than reasoned about, because a
proxy that strips it fails **closed and in silence**, looking exactly like a bad key.

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
every file appears atomically and within one filesystem. The mirror that removes what a payload
omits is an **enumerated delete**: the tree is walked, diffed, and each surplus path is checked by
the same rules an added path passes before `File::delete()` is called on it, one named file at a
time. `Directory::remove()` is never used for it — that method deletes the files a directory holds,
which is right for tearing down a fixture and catastrophic here.

### Where the roots resolve, and the mistake that is written down

`Deployment` maps a root to a directory; `UpdateRoot` is only the vocabulary. That separation exists
because it was originally one class, and the join was a real fault: deciding whether a name was
*under* `public/` meant resolving where `public/` **is**, which reaches `DOCUMENT_ROOT`.

`Config::webroot()` takes only the **basename** of `DOCUMENT_ROOT` and hangs it off the derivation
every other path here uses, because on the live host the two spellings of one directory are
genuinely different strings — `/home/strato/…/cgi-bin/neurosys` against `/mnt/web505/…/cgi-bin/neurosys`
— and a path built from the wrong one compares equal to nothing. But a basename grafted onto a
different tree names a real directory somewhere else, and during development a test that pointed
`DOCUMENT_ROOT` at a sandbox got this repository's `public/` back and the mirror emptied it.

Two changes came out of that, and both are guards rather than notes. `Config::webroot()` now
**refuses** a `DOCUMENT_ROOT` that is not a directory inside the deployment, comparing `realpath()`
on both sides, and refuses rather than guessing because every candidate guess is a directory
something would then be willing to delete. And `UpdateApplier` takes its `Deployment` as a
constructor argument, so a test is not merely unlikely to reach the live tree — it cannot.

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

## The update endpoint, reviewed (2026-09-08)

The two commits that added `/update` got their own pass, since it is the one route that writes. It
has since moved to `/api/update/v1/patch` and its credential has moved into `Authorization`; what
follows describes the endpoint as it stood, and is kept in that tense because two of the three
findings are about code that still exists. The third — the unbounded body read — is closed twice
over now: an unsigned caller's body is never read at all, and a signed one's is read to the length
the envelope declares. No
way to write, delete, or traverse outside the three roots was found without the signing key: the
signature-before-parse ordering held, the hand-rolled tar's refusals held, the name allowlist and
the enumerated mirror held, and replay stayed closed by the serial doing double duty. Three
hardenings came out of it, and the order of their severity is the finding — the one an unsigned
caller can reach is the only one that mattered.

- **The request body was read whole before its own size cap — low severity, and the one that is
  request-reachable.** `UpdateGate` bounds a push to `MAX_BODY` (8 MB), but `Request::body()` was a
  bare `file_get_contents('php://input')`, which reads to `post_max_size` — a php.ini value nobody
  in this repository owns — before the cap could reject it. Reading the body is the one step that
  *must* precede the signature check, because the signature is over the body, so any `POST /update`
  reached it regardless of the key. On a host where `post_max_size` outruns `memory_limit` that is a
  memory-exhaustion `500`, which is both a denial-of-service knob and — since an unrouted path never
  reads its body — the one response that would tell `/update` apart from a typo, undoing the
  invisibility the rest of the endpoint is built for. The read is now capped inside
  `File::read($limit)`, which `Request::body()` is given `MAX_BODY + 1`, so an over-limit body is
  refused having pulled 8 MB into memory rather than up to `post_max_size`, and the bound the class
  states is the bound it applies. `SupportTest` and `RequestTest` pin the cap; the severity is
  host-dependent, moderate only where `post_max_size` is the larger of the two.
- **The mirror's directory walk followed symlinks — informational.** `UpdateApplier::walk()` and its
  sweep decided a directory with `is_dir()`, which follows a symlink, so a symlinked directory under
  a root would have been enumerated and its target deleted *through*, a file at a time. It is not
  reachable through the endpoint — `TarArchive` refuses a symlink member, and neither the repository
  nor `deploy.sh` places one under `public/` or `src/`, so a link there is the mark of a compromise
  that already holds the filesystem — but the one code path here that deletes must not be a second,
  weaker way outside the roots. Both now ask `!is_link()` before descending: a stray link is a leaf,
  and unlinking it removes the link and not what it points at. `UpdateTest` plants a symlink a
  payload cannot carry and asserts the target survives the mirror.
- **`Config::webroot()` could resolve a relative `DOCUMENT_ROOT` against the working directory —
  informational.** The containment guard the same commit added compares `realpath(dirname($root))`
  against the deployment, and for a bare relative name `dirname()` is `.`, whose realpath is the
  process's working directory — which under the test runner is the deployment itself, so a relative
  value borrowed a blessing meant for an absolute one. `DOCUMENT_ROOT` is server-set and always
  absolute on the live host, so this was never request-reachable; it is the same test-versus-production
  drift the commit's own guards were written against, closed the same way. A non-absolute
  `DOCUMENT_ROOT` is now refused before the containment check runs. `ConfigTest` pins it.

## The newer surfaces, reviewed (2026-09-09)

The work since the two passes above — the per-demo realm a visitor's slug names, the route claim
`Request::path()` used to make and could not keep, and the `/update` hardenings — got a live pass of
its own, run against a local Apache 2.4 rather than the built-in dev server, because that is the
version the live host runs *and* the one place the realm fix was ever needed: Strato's proxy
percent-encodes a hostile target before PHP sees it, so a bare Apache is the only environment where
the malformed realm was reachable at all. The pass fired the demo-realm reflection (raw `"`, `\`,
`%`, encoded and raw CR/LF, NUL and spaces in the slug), `/update` under every verb and with
malformed and well-framed-but-unsigned bodies, the `Accept-Language` parser the bilingual pages
added, the method, caching and download-redirect paths, and re-ran the path, method and auth fuzzing
of the first pass against the real SAPI rather than CLI.

**No exploitable issue was found, and the newer defenses held exactly as written.** The realm's
two-layer fix held front to back: `rawurlencode()` turned every `"` into `%22` and every `\` into
`%5C` before `BasicChallenge` was constructed, so its `qdtext` check never had to throw, and a raw
CR, LF, space or NUL in the target was a `400` at the wire, ahead of PHP. `/update` was
indistinguishable from a typo under GET/HEAD/POST/PUT/DELETE/PATCH/PROPFIND and an unknown verb — the
same status, `Allow` and body an unrouted path gives — and a body with the wrong magic, a truncated
length prefix, an over-limit segment or a valid frame carrying a random signature each came back the
same `405`, in about a millisecond and without a `500`; the signature-before-parse ordering is what
keeps the last of those cheap. A 10 MB unsigned `POST` was refused without exhausting memory, though
on this rig the precondition the body-cap fix targets did not even obtain — `post_max_size` and
`MAX_BODY` are both 8 MB, so it is only a host where the former outruns `memory_limit` that pays. The
demo gate's constant-time refusal was confirmed with a stopwatch rather than read: a slug that names
nothing and a known slug with the wrong password both answered `401` in 157–158 ms, a spread of about
a millisecond, because `PasswordHash::unmatchable()` is a real cost-12 digest and pays what the real
ones do. `Accept-Language` resolved every malformed value to the default rather than to a `500` and
reflected none of it — the choice is one of two `Language` cases and the raw header reaches no sink.
Security headers covered every application response including the `401`, `404`, `405` and `303`, and
`X-Powered-By` was gone under the real SAPI, which is the one place it can be seen to go.

Two observations came out of it, and neither is a code finding — the first is not the application's
to fix and the second is a decision — so both are recorded here rather than closed in a commit.

- **`TRACE` is answered by the server, not the site — low severity, and not reachable through this
  code at all.** Apache's default `TraceEnable On` answers a `TRACE` before the request reaches
  `index.php`, echoing the request's own headers back in a `message/http` body — and because it never
  reaches PHP, that response carries none of the security headers a real one does. It is the same
  "static assets never reach PHP" gap named earlier, one step worse because the echoed body is
  attacker-shaped. It is bounded on every side that matters: a browser refuses to issue a
  cross-origin `TRACE`, so the classic cross-site-tracing route to a stored credential is closed in
  the client; the site sets no cookie to harvest; and the `Authorization` header both gates read is
  not one a cross-site `TRACE` could originate. It is recorded because it is a standard hardening
  item and because it cannot be fixed where the rest of this file's Apache config lives —
  `TraceEnable` is a server-level directive, invalid in `.htaccess`, so the only lever this
  repository has on shared hosting is a `RewriteRule` that refuses the method at the edge. Worth a
  `curl -X TRACE` against the live host to learn which way Strato has it; the local rig has it on, and
  the local rig is not Strato. (An overlong target is the server's to answer too, and does: past
  roughly 4 KB Apache cannot map the path to a file and returns `AH00127`/`403`, and past its
  request-line limit a `414` — both route-independent, and neither reaching a real slug.)
- **The SoundCloud `secret-token` is public in a release page — by design, and noted because the
  name argues otherwise.** A private or scheduled track embeds with a `secret-token`, and the release
  page that plays it is public, so the token is rendered into public HTML where anyone can read it.
  That is correct: the token is a per-track capability to *play an unlisted track*, not a credential
  to the account, and a public page whose whole purpose is to play the track is exactly where it
  belongs. It is written down because "secret" reads like something the page ought to be hiding and
  it is not — a track given one here is as playable as a public one to anyone who loads the page,
  which is the intended reach and worth confirming against the intent for each release.

One surface was reviewed rather than exercised, and the gap is named rather than papered over.
`FileResponse` and `ByteRange` — the demo-audio byte-range path — sit behind a demo's password, so
the `Range`-header fuzzing that would belong here (suffix ranges, a `0-0` probe, a backwards
`500-100`, offsets past the end, the `416` boundary) was read for correctness rather than fired at a
running server: the 256 KB chunked stream never holds a whole file in memory, and the
`min(chunk, remaining)` read cannot overshoot a range's end. It is the one newer parser this pass
could not reach without a credential.

## The API, reviewed (2026-09-09)

The move from `/update` to `/api/{service}/{version}/{action}` changed where the credential travels
and what it binds, so the endpoint got a pass of its own. Four things came out of it, and the last
one is not fixed.

- **An unrecognised verb would have been a `500`.** `Request::method()` is null for one and
  `MethodPolicy::Delegated` passes it through on purpose, so the envelope's method comparison would
  have dereferenced null — a `500` where `/no-such-page` sends a `405`, on the one route built to be
  indistinguishable from a typo. `ApiGate::accepts()` refuses null on its first line now, and the
  verify script sweeps `BREW` alongside every real verb, at every depth.
- **A malformed credential would have been a `500` too.** `base64_decode(..., true)` answers `false`,
  and under `strict_types` a `false` reaching `strlen()` is an uncaught `TypeError`. Every length in
  `ApiCredential::parse()` is bounded against what is actually present before it is used as an
  offset, and each failure is an exception the gate turns into the same silence as any other. Six
  malformed-credential probes are in the verify script, including one over the header size limit —
  that one is refused by Apache before PHP sees it, which is a `400`: a refusal from the wrong layer,
  but not one that says `/api` is there.
- **The body is no longer read before the signature.** See the note in the 2026-09-08 section, which
  named that read as the one thing that could time-distinguish the endpoint. An unsigned caller is
  now refused before `php://input` is touched at all, and a signed one's body is read to the length
  the envelope declares rather than to a fixed 8 MB cap.
- **`public/api/` must never exist**, and this is a hazard the route shape introduced. The webroot
  passes real files and directories straight through (`RewriteCond !-f` / `!-d`), so a directory
  there would be answered by Apache — a listing or a `403` — and `/api` would stop looking like a
  typo without a line of PHP being involved. The verify script asserts nothing is there. Note that a
  push cannot create it either way: `public/` is a root, so a member named `public/api/...` would be
  written — the assertion is about what is in the repository, not about what a signed caller can do.

**The one interaction that is known and not closed** is the pre-launch site gate. `requireSiteAuth()`
runs before the router and is HTTP Basic on the same `Authorization` header, so while
`data/site_auth.php` exists no signed API call can be made: the credential is in the wrong scheme,
the gate sees an empty user, and the request is a `401`. That is the interaction demos already have,
and it leaks nothing — that gate answers `401` for *every* path alike, so `/api` is no more visible
than `/imprint`. It is moot today, because the gate is off.

The fix, if it is ever needed, is a decision rather than a patch, and the shape matters: standing
the site gate down whenever an `NS1` header is merely **present** would make `/api` answer `404`
where every other path answers `401` — a perfect oracle. It has to stand down only for a request
whose signature has already **verified**, which means running the gate once, before
`requireSiteAuth()`, and handing the result on.

## What is deliberately not here

- **No web-application firewall, no rate limiting layer.** This is a static site on shared hosting;
  the perimeter is the host's.
- **No CSRF tokens, no `SameSite` cookies.** The argument used to rest on three independent legs
  and now rests on two, which is worth stating rather than leaving as an unchanged sentence. The leg
  that went is "the only state-changing verb is refused by the 405 gate": `/api` honours a `POST`.
  What remains is that the site **sets no cookie and starts no session**, so there is no ambient
  credential for a cross-site request to ride, and that there is **no `<form>`** anywhere. Either
  alone is sufficient, and a cross-site `POST` to `/api` cannot forge a signature in any case. The
  CSP still carries `form-action 'self'`, which on a site with no forms is belt over braces and stays
  because the day a form appears is not the day anyone will remember to add it.
- **No cookie or consent banner** for the site itself — it sets no cookie. The one consent gate is on
  the SoundCloud embed, which contacts no third party until the visitor clicks: the served HTML
  contains no SoundCloud address at all for a browser to preconnect or prefetch, and the notice is
  written by the element that would do the loading.
- **No `data:` URLs, no inline scripts or styles, no third-party script.**
- **No outbound request from the server.** `index.php` answers requests and never issues one, so
  there is no URL a request can steer and nothing that carries a visitor's address to a third party
  behind their back. The verify script asserts it, by grepping `src/` for `curl_*`, `fsockopen` and
  `stream_socket_client`. The SoundCloud upload client added alongside it is **tooling**: it lives
  under `tools/`, which `deploy.sh` never uploads, it runs on a laptop, and its credentials are
  environment variables with the rotating token kept outside the repository entirely.

Each of these is a property that falls out of the site's shape, not a control that was considered and
skipped. That is the whole posture in one sentence: **make the class impossible, then enforce the few
remaining invariants where they are written and where they are rendered.**
