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
  without it, at every depth. `/api`, `/api/update`, `/api/update/v1`, `/api/health`,
  `/api/health/v1`, `/api/capability` and `/api/capability/v1` match no route at all, because the
  pattern is four segments. **Three services answer under it**: `update`, which writes, and
  `health` and `capability`, which only read. That difference is deliberately not observable.
  `ApiController` hands anything it will not verify to `UnroutedController` *before* it has
  resolved a service at all, so a read-only service is exactly as invisible as the writing one.
  Both suites sweep all three.
- **Static assets** under `/assets/`, served by the web server, never by PHP. The one exception is a
  demo's audio, which PHP serves itself precisely so that it is *not* static — see below.
- Everything else answers `404` or `405`.

Everything an attacker controls: the **request target** (the path), the **method**, the request
headers the app reads — `Authorization`, and the six `RequestHeader` cases: `X-Requested-With`,
`If-None-Match`, `Range` (demo audio only), `Accept-Language` and `Cookie` (every page, which is
written in the language they pick; of the cookies only `lang` is read, and only as one of the
`Language` cases — anything else falls through), and `Referer` (`/language/{language}` alone, where
only its path is used — see [the language cookie](#the-language-cookie)) — plus the referrer once
more when download logging is on, which it is not; and, under `/api` alone, a **request body**.

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

The front controller is two statements, and the second is the spine of the request:

```php
set_exception_handler(...);   // 0. the last resort — logs, and answers a bare 500
Site::current()->run();       // the app autoload.php booted — Phpanta's App::run(), which is:

ErrorLog::install($this->errorLog());    // 0. …into a file under data/logs/ this repository can read
SecurityHeaders::send();                 // 1. headers first — cover every response
$request = Request::fromGlobals();       // 2. parse the request defensively
Auth::requireSiteAuth($request);         // 3. the pre-launch gate
new Router($this->routeTable())->dispatch($request)->send($request);  // 4 + 5. route, respond
```

The handler comes first because it is the one that has to work when nothing else did: an uncaught
throwable is otherwise a blank page or a stack trace naming absolute paths, decided by a php.ini this
repository does not own. It depends on nothing — no `Response`, no view — so it cannot throw inside
itself.

### 1. Transport — HTTPS and HSTS

The framework's — see [phpanta/docs/security.md](../phpanta/docs/security.md#1-transport--https-and-hsts).
The site's half is the redirect, and what the verify script holds the build to:

- **`public/.htaccess` redirects `http://` to `https://` before any PHP runs, and asks two
  questions to do it.** Strato terminates TLS at its proxy, where `%{HTTPS}` can read `off` on a
  request that was encrypted the whole way; `X-Forwarded-Proto` is the header telling the truth
  there, so the redirect fires only when both say plaintext. On its own, `%{HTTPS}` would be an
  infinite loop behind Strato's proxy.
- **The verify script asserts no source map ships** and no shipped module names one, on top of
  `build-prod.mjs` refusing both. `public/` keeps them — the site's `tsconfig` sets
  `inlineSources`, so each carries the whole commented TypeScript — which is fine for the local
  Apache and `npm run dev` on localhost, and is the gap [Known and accepted](#known-and-accepted)
  lists for a dev server bound wider. ([history](history/security.md))

### 2. Response headers — typed, and sent before anything can fail

The framework's — see [phpanta/docs/security.md](../phpanta/docs/security.md#2-response-headers--typed-and-sent-before-anything-can-fail).
The site widens the framework's strict policy in exactly two places, both from
`Site::contentHosts()`: its images come from HiDrive and its player is SoundCloud's. HSTS and
`Permissions-Policy` are the framework's defaults, unwidened. The policy as sent:

```
Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self';
    img-src 'self' https://my.hidrive.com; frame-src https://w.soundcloud.com;
    base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'
```

- **No `'unsafe-inline'`, because nothing needs it.** No view emits an inline style or an event
  handler — `ViewTest` and the verify script both fail on one — and `<soundcloud-player>` sets its
  accent and attribution styling through the CSSOM rather than a `style` attribute.
- **No `data:` on `img-src`, because nothing references one** — the cover placeholder is a file, a
  self-contained SVG. Both suites assert the absence. ([history](history/security.md))
- **No `report-uri`**, on the terms download logging is off on: a report's `document-uri` and
  `blocked-uri` are data neither half of the privacy policy claims. `SecurityTest` pins the hosts
  the policy names instead.
- **`/admin/stats` says `no-store, private`**, which is also what keeps an `ETag` off it; a demo's
  responses do the same — see [Authentication](#3-again-authentication).

### 3 + 4. The method gate

The framework's — see [phpanta/docs/security.md](../phpanta/docs/security.md#3--4-the-method-gate).
Here that is nine `ReadOnly` routes and `/api`, `Delegated`. The verify script sweeps `BREW`
alongside every real verb, at every depth, so the one route the router has no opinion about answers
an unknown verb exactly as `/no-such-page` does. ([history](history/api.md))

**`TRACE` never reaches the router on the live host.** Strato's Apache refuses it itself — `405`, an
empty `Allow`, Apache's own body, nothing echoed — on every path including static files (checked
2026-09-10 with `curl -X TRACE`). The local rig has `TraceEnable On`, so a local run is no evidence
either way. If the live answer ever changes, the only lever is a `RewriteRule` refusing the method.

### 2 (again). Parsing the request defensively

The framework's — see [phpanta/docs/security.md](../phpanta/docs/security.md#2-again-parsing-the-request-defensively).
`RoutingTest` pins that an unparseable target still matches a `{slug}` route and `RequestTest` pins
where the fallback cuts it. What keeps a hostile slug out of a header here is a demo's realm, under
[Authentication](#3-again-authentication). The `parse_url()` that was once here, and the `500` it
made of `GET ///`, are in [history/security.md](history/security.md).

### 4 (again). Routing

The framework's — see [phpanta/docs/security.md](../phpanta/docs/security.md#4-again-routing).

### 3 (again). Authentication

Four gates. Three are HTTP Basic; the fourth is a signature, described under [The API](#the-api).

Two of the Basic gates — the pre-launch site gate and the admin gate — ask the same question of the
same shape of credentials file, so they ask it in one place: `Auth::accepts()`. The third is a
demo's, whose credential is a `PasswordHash` on the `Demo` object itself rather than in a file; it
is `DemoGate::admits()`, the site's gate built on the framework's. Both are public and return a
`bool`, and both are the *decision* separated from the `401` that follows it, the way
`SecurityHeaders::headers()` is separate from `send()` — a method that ends the request cannot be
asserted against, so everything worth testing lives in the pair that does not. All three end up in
one comparison, `Auth::matches()`.

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
  URL, so `DemoGate` `rawurlencode`s it first: a no-op for every real slug, and what keeps a
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
  a stopwatch. `DemoGate::requireAuth()` verifies against `PasswordHash::unmatchable()` — a real
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

The framework's — see [phpanta/docs/security.md](../phpanta/docs/security.md#5-the-response--output-safety-in-the-markup-tree).
The site's side of it:

- **The verify script fails a heredoc or a `'<tag'` literal** anywhere under `src/` or
  `phpanta/src/`, and `HtmlTest` pins both the one `htmlspecialchars` call site and every spelling of
  an off-origin URL `Element` refuses.
- **The one hand-authored document is the privacy policy**, its two halves (`data/privacy.de.html`,
  `data/privacy.en.html`) read through `Element::containingHtml()` against the site's own
  vocabulary. A test named for the fact pins its call sites. ([history](history/security.md))
- **No request data reaches a URL attribute.** Release and profile pages render trusted data; the
  one place request input is reflected — the `404` page echoing the path into a terminal's
  `command` — is a *text* attribute, escaped like any other, and the client-side element sinks it
  via `textContent`, never `innerHTML`.

### Input validation at the boundary it is written

The framework's — see [phpanta/docs/security.md](../phpanta/docs/security.md#input-validation-at-the-boundary-it-is-written).
Two of the site's own types hold the same line, so a bad paste in `data/releases.php` throws when the
file loads rather than `404`ing from HiDrive or rendering a dead link:

| Type | Invariant |
|---|---|
| `HiDriveLink` | share id is exactly nine alphanumerics |
| `Profile` | an absolute `https://` URL |

Every bad-input test provider — the framework's types' included — carries a trailing-newline case.
([history](history/security.md))

### The language cookie

The site sets one cookie, `lang`, and only when a visitor clicks the language switch in the footer.
`GET /language/{language}` answers a 303 carrying `Set-Cookie: lang=de; Path=/; Max-Age=31536000;
SameSite=Lax; Secure; HttpOnly` and `Cache-Control: no-store, private`.

- **It holds a language and nothing else** — `de` or `en`. A value that is not a `Language` case is
  ignored on the next request rather than trusted.
- **`HttpOnly`**, because no script reads it: the server decides the language and states it on
  `<html lang>`, which is where the client reads it.
- **It is a GET**, so a third party can link somebody to it and switch their language. That costs one
  click to undo, and is why the route does nothing else.
- **Back is the `Referer`'s path alone.** Its host is dropped, so the redirect cannot leave the site,
  and the path is still put to `Element::staysOnThisOrigin()` — `//evil.example` is a path that names
  another host. `Location` asks the same question of every path it is given, and accepts only that or
  an absolute `https://` URL. The referrer is never stored or logged.

The privacy policy names the cookie, in both languages. See [language.md](../phpanta/docs/language.md#the-switch).

### Privacy at rest

Download logging is **deliberately off**: `Site::DOWNLOAD_LOGGING` is `false`, and `log()` returns
on it *before* the log entry is built, so the referrer is never even read. There is no `report-uri`,
and no personal data is ever placed in a URL or query string. Turning logging on is a
privacy-policy decision before a code one — neither half of the policy (`data/privacy.de.html`,
`data/privacy.en.html`) makes a download-tracking claim, so both would have to be amended first.

## The API

The framework's — see [phpanta/docs/security.md](../phpanta/docs/security.md#the-api).
What is this site's about it:

- **Why it exists here.** Deploying with `rsync -c` over a GVFS SFTP mount costs **480 ms** a
  `stat` and **3.7 s** to walk `src/` alone, across 269 files, for a payload that is **250 KB
  gzipped** — a figure that grows with the codebase, so `php tools/push-update.php --dry-run`
  re-derives it. A push replaces minutes with one request; `tools/api.php` signs every other call.
- **The private key is `~/.config/neurosys/update.key`**, the same arrangement the SoundCloud
  refresh token has: outside the repository, so no `.gitignore` entry and no rsync flag is what
  keeps it off a webroot. `data/update.pub` is gitignored, per deployment, and uploaded by hand —
  `deploy.sh` excludes it.
- **The serial is `cgi-bin/.update-serial`** on the live host: above the webroot, in neither mirrored
  tree, and in no tree `deploy.sh` rsyncs.
- **No push can reach `data/`**, which is what keeps `data/admin.php`, `data/site_auth.php`,
  `data/demos.php` and 8.6 MB of unreleased audio out of reach however well a payload is signed.
- **`Authorization` has to survive Strato**, and `public/.htaccess` puts it back with
  `E=HTTP_AUTHORIZATION`. All three Basic gates already depend on it arriving, which is the
  strongest evidence available that Strato forwards it — but a proxy that strips it fails closed and
  in silence, looking exactly like a bad key, so re-check it on the live host rather than reason
  about it. ([history](history/api.md))
- **Strato reports one directory two ways** — `/home/strato/…/cgi-bin/neurosys` against
  `/mnt/web505/…/cgi-bin/neurosys` — which is why `App::webroot()` takes only `DOCUMENT_ROOT`'s
  basename. A push leaves byte-identical files alone for the NFS reason in
  [deployment.md](deployment.md).
- **The verify script holds the framework to its claims over real HTTP**: every method, `BREW`
  included, at every depth, against `/no-such-page`; six malformed-credential probes, the one over
  the header limit refused by Apache with a `400`; nothing under `public/api/`; and `openssl_` only
  in `PublicKey`, with no signing or key-minting call under `src/` or `phpanta/src/`, the way it pins
  `curl_` to `CurlTransport` under `tools/lib/`.
- **About 180 µs** separates the `/api` shape from a typo for a caller already sending an `NS1`
  credential, measured on localhost in the 2026-09-09 pentest — see
  [Known and accepted](#known-and-accepted).

## Known and accepted

What is open, or accepted as a decision, with the reason each is where it is. Everything else the
assessments turned up is fixed — see [history/security.md](history/security.md).

- **Static assets reach neither gate and carry no security headers.** `.htaccess` passes real files
  through before the rewrite to `index.php`, so while the pre-launch gate is up it covers documents
  and not `/assets/**` — see [Transport](#1-transport--https-and-hsts). The same is true of the
  debug tree's source maps on a dev server bound beyond localhost.
- **An overlong target is the server's to answer, not the site's.** Past roughly 4 KB Apache cannot
  map the path to a file and returns `AH00127`/`403`, and past its request-line limit a `414` — both
  route-independent, neither reaching a real slug, and neither carrying the site's security headers.
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
  Not a usable oracle; see [It answers as though it is not there](../phpanta/docs/security.md#it-answers-as-though-it-is-not-there).
- **A captured read credential replays within the ±300 s skew window**, yielding the answer to a
  read to somebody who has already broken TLS. See
  [What a signature covers](../phpanta/docs/security.md#what-a-signature-covers-and-why-replay-is-closed).
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
