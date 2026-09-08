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
and persisted nothing a request sends. That is no longer true: `/update` accepts a POST carrying a
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
- **The tenth is `/update`**, which accepts a `POST` and answers every method exactly as an address
  that does not exist, unless the request carries an ECDSA signature this deployment's public key
  verifies. It is unreachable without the private key and invisible without it.
- **Static assets** under `/assets/`, served by the web server, never by PHP. The one exception is a
  demo's audio, which PHP serves itself precisely so that it is *not* static — see below.
- Everything else answers `404` or `405`.

Everything an attacker controls: the **request target** (the path), the **method**, a few
**request headers** the app reads — `Authorization`, `X-Requested-With`, and (only when download
logging is on, which it is not) `Referer` — and, on `/update` alone, a **request body**. That body
is read at one call site, and every byte of it is discarded unless a signature over its manifest
verifies first.

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

**The question moved onto the route when `/update` arrived**, and it is a `MethodPolicy` rather than
a set of methods. That is not a stylistic choice. A route carrying its own set would make the `405`
name it, so `PUT /update` would answer `Allow: GET, HEAD, POST` — and that `POST` is precisely the
fact the endpoint exists to hide. An unrecognised verb was worse: `null` is in no set, so the
refusal would have named the whole set. So there are two policies, not ten sets: nine routes are
`ReadOnly` and refuse exactly as the old global gate did, and `/update` is `Delegated`, which means
the router forms **no opinion at all** and the controller answers every method itself. The only
`Allow` the router ever sends is still `GET, HEAD`.

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

## The update endpoint

`/update` is the one route that writes. It exists because deploying meant `rsync -c` over a GVFS
SFTP mount where a single `stat` costs **480 ms** and walking `src/` alone costs **3.7 s**, across
269 files, for a payload that is **209 KB gzipped**. It replaces minutes with one request.

It is also the one place this document's other claims had to be re-argued rather than restated, so
the argument is here in full.

### It answers as though it is not there

An unsigned request gets **exactly** what the site gives for an address that does not exist: the
rendered `404` for a read method, the `text/plain` `405` with `Allow: GET, HEAD` for anything else,
and the same for a verb the site does not recognise. Not a `401`, which would prompt; not a `403`,
which would confirm; not a `405` naming `POST`, which would confirm more precisely.

That is a property of the structure rather than of two implementations kept in step: both responses
come from `UnroutedController`, the very object `Router` delegates to when no route matches at all.
`UpdateController` hands it anything it will not verify. The verify script checks the claim over
real HTTP, per method, against `/no-such-page` — because the claim is about status codes, headers
and bodies, and only a real server has those.

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

The body is one framed stream — magic, a manifest, a signature over the manifest, then the gzipped
tar. The signature covers the manifest; the manifest covers the archive by SHA-256. One signature
over a few hundred bytes therefore protects a payload of any size, and the archive is never trusted
before it is hashed.

The manifest carries a `serial` doing double duty: it must be within **±300 s** of the server's
clock *and* strictly greater than the highest serial already accepted, which is recorded at
`cgi-bin/.update-serial` — above the webroot, in neither mirrored tree, and in no rsynced one. The
monotonic half alone would accept a payload signed long ago and never sent; the skew half alone
would leave a five-minute replay window. A **dry run deliberately does not advance the serial**, so
a captured dry run replays to nothing and a real push of the same payload is still possible.

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

The two commits that added `/update` got their own pass, since it is the one route that writes. No
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

## What is deliberately not here

- **No web-application firewall, no rate limiting layer.** This is a static site on shared hosting;
  the perimeter is the host's.
- **No CSRF tokens, no `SameSite` cookies.** The argument used to rest on three independent legs
  and now rests on two, which is worth stating rather than leaving as an unchanged sentence. The leg
  that went is "the only state-changing verb is refused by the 405 gate": `/update` honours a `POST`.
  What remains is that the site **sets no cookie and starts no session**, so there is no ambient
  credential for a cross-site request to ride, and that there is **no `<form>`** anywhere. Either
  alone is sufficient, and a cross-site `POST` to `/update` cannot forge a signature in any case. The
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
