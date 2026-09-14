# Security

This document is the security posture of the site in one place: the philosophy, the layered
hardenings, the invariants each layer enforces, and what is known and accepted. It describes what
the code does and, more usefully, *why* — an absence is worth writing down when it is a decision
rather than an oversight, and most of the security here is absences. The assessments this came out
of, and the findings they closed, are in [history/security.md](history/security.md).

The short version: **the shape of the site is its first defense.** It is static, has no database and
no runtime dependencies, and its pages start no session and hold no `<form>` — the admin's door for a
browser is the one place either exists, and it opens only to an enrolled passkey. Whole classes
of vulnerability are not mitigated here — they are structurally absent. What remains is enforced at
type boundaries and at the single place markup is rendered, so the failure mode of a mistake is a
build error or a thrown exception, not a silently shipped hole.

**One address family writes.** `/admin/update/v1/patch` accepts a signed `POST` carrying a gzipped
tarball and writes it into `src/` and the webroot — it is the deploy path, and the admin it belongs
to is described in full under [The admin](#the-admin), with the admin's other writes. Every page is
read-only, accepts no upload and persists nothing a request sends.

## The attack surface

Everything an attacker can reach:

- **Nine routes of the site's, every one `GET`/`HEAD`**: `/`, `/releases`, `/releases/{slug}`,
  `/releases/{slug}/{format}`, `/demos/{slug}` and `/demos/{slug}/{label}` (each behind that demo's
  own HTTP Basic password), `/imprint`, `/privacy` and `/language/{language}`. There is
  deliberately no `/demos` index — see [demos.md](demos.md).
- **Four more are the framework's admin**: `/admin`, `/admin/{service}`,
  `/admin/{service}/{version}` and `/admin/{service}/{version}/{action}`, the last of which accepts
  a `POST`. A caller whose ECDSA signature this deployment's public key does not verify, and whose
  session no enrolled passkey unlocked, sees the entrance at `/admin` and nothing else: every depth
  below it, existing or not, under every verb, gives one answer — a `303` back to the entrance for a
  page, a `401` challenging for `NS1` for data. The entrance itself takes a `POST` from anyone, for
  its passkey ceremonies, counted per address. **Four services answer under it**: `update`, which
  writes, `access`, which enrols and revokes the devices a browser may open the admin with, and
  `health` and `capability`, which only read. That difference is visible only past the gate:
  `ApiController` asks who is calling before it has resolved a service at all, so a stranger learns
  that there is an admin and nothing about what is in it. Both suites sweep `update`, `health` and
  `capability`. `/api`, where the admin used to be, matches no route and answers exactly like
  `/no-such-page`.
- **Static assets** under `/assets/`, served by the web server, never by PHP. The one exception is a
  demo's audio, which PHP serves itself precisely so that it is *not* static — see below.
- Everything else answers `404` or `405`.

Everything an attacker controls: the **request target** (the path), the **method**, the request
headers the app reads — `Authorization`, and the six `RequestHeader` cases: `X-Requested-With`,
`If-None-Match`, `Range` (demo audio only), `Accept-Language` and `Cookie` (every page, which is
written in the language they pick; of the cookies only `lang` is read, and only as one of the
`Language` cases — anything else falls through), and `Referer` (`/language/{language}` alone, where
only its path is used — see [the language cookie](#the-language-cookie)) — plus the referrer once
more when download logging is on, which it is not; and, under `/admin` alone, the `__Host-session`
cookie and a **request body**.

A signed call's body is read at one call site, and **it is not read at all until a signature has
verified**. The credential arrives in `Authorization` rather than framed into the body, so an
unsigned caller is refused before `php://input` is touched, and the read that does happen is bounded
by the *signed* length — which `ApiGate::MAX_BODY` (8 MiB) caps — rather than by `post_max_size`. A
browser's form is the other body, read by the framework's `AdminBrowser` alone: at the entrance only
after the post has been counted against the sender's address, and below it only for a session a
passkey unlocked — bounded by `Request::MAX_FORM` either way.

What is *not* in the surface, and the bug class each absence removes:

| Not present | Class it removes |
|---|---|
| No database, no SQL | SQL injection |
| No session outside the admin, and one cookie that is only a preference | session fixation/hijack; the ambient credential CSRF rides |
| No `<form>` outside the admin, no ambient credential | CSRF target; mass-assignment |
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
SecurityHeaders::send();                 // 1. headers first — even the last-resort 500 carries them
$this->handle(Request::fromGlobals())->send();  // 2–5. parse, gate, route, answer; then send

// handle(), which sends nothing — what a test calls:
$response = Layered::around($this->layerTable(),   // 3. the app's layers — none here
    new Router($this->routeTable()))->handle($request);  // 4. route
return $response->answer($request)->withHeadersFirst(SecurityHeaders::all($this));  // 5. answer
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
- **Every admin answer says `no-store, private`**, which is also what keeps an `ETag` off it, and
  asks not to be indexed; a demo's responses do the same — see
  [Authentication](#3-again-authentication).

### 3 + 4. The method gate

The framework's — see [phpanta/docs/security.md](../phpanta/docs/security.md#3--4-the-method-gate).
Here that is the site's nine `ReadOnly` routes and the framework's four admin routes, `Delegated`.
The verify script sweeps `BREW` alongside every real verb at every admin depth, existing or not, and
holds each to one answer everywhere: a `303` for a page, a `401` for data. Under `php -S` an unknown
verb never reaches PHP — the server refuses it with a `501` itself, identically at every depth and
at `/no-such-page` — so the controller's half of that is the framework's `ApiTest`, in-process.
([history](history/api.md))

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

Two gates. One is HTTP Basic — each demo's; the other is the admin's, a signature or an enrolled
passkey, described under [The admin](#the-admin). The framework's Basic admin gate, `AdminGate`,
stands on no route here, and `RoutingTest` asserts that none carries it.

A demo's credential is a `PasswordHash` on the `Demo` object itself rather than in a file; its gate
is `DemoGate::admits()`, the site's gate built on the framework's `Auth`. It is public and returns a
`bool` — the *decision*, separated from the `401` that follows it. The `401` is a value too:
`DemoGate::enter()` returns it rather than ending the request, the caller returns it in turn, and it
carries `#[\NoDiscard]` — a call whose result goes nowhere is the one way to leave the door open,
and it fails the suite. The comparison itself is the framework's, `Auth::matches()`.

- **Every comparison is constant-time, and neither is skipped when the other fails.** The password is
  `password_verify()`; the user name is `hash_equals()`, compared on every request just the same.
  Chaining the two with `&&` would leak what each individually does not: bcrypt is deliberately
  slow, so a wrong user name would return in microseconds while a right one paid the full cost — a
  difference measurable across a network that tells an attacker which half of the credential they
  already have. Both run every time; the results are combined afterwards.
- **An empty hash is an unconfigured gate, not one that accepts an empty password.** A credentials
  file with an empty `pass_hash` is how an unconfigured gate is spelled, and the guard refuses an empty hash out loud rather than relying on `password_verify('', '')` happening to be
  false.
- **The token is spelled once, at both ends.** `BasicChallenge` writes `Basic realm="…"` into the
  `401` and `Request::fromGlobals()` reads `Basic ` on the way back in, and both say
  `AuthScheme::Basic`. A mismatch there would make every Basic gate refuse everything, identically, with
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
- There is no `data/admin.php`: no route
  here stands behind the framework's Basic admin gate. A **demo** gate has no absent case at all — a `Demo` cannot be
  constructed without a `PasswordHash`, so a demo that is reachable is a demo that is gated.
- **A demo that does not exist is refused identically to one whose password is wrong**, in status
  code *and* in elapsed time. A `404` for an unknown slug and a `401` for a known one is a catalogue
  of unreleased tracks, readable one guess at a time; and returning early on the unknown one would
  answer in microseconds where a real comparison pays bcrypt, so the uniform `401` would be undone by
  a stopwatch. `DemoGate::enter()` verifies against `PasswordHash::unmatchable()` — a real
  digest with no preimage — and then refuses. Same reasoning as the no-short-circuit rule above, one
  level out.
- **A demo's password gates the bytes, not only the page.** Its audio is under `data/`, which the web
  server does not serve, so `DemoAudioController` is the only route to it and it asks the same
  question. That is deliberately unlike a release, whose download is a `303` to a HiDrive share URL —
  a capability that can be forwarded and that outlives any password change. The gated responses also
  carry `no-store, private`, no `ETag` (so no `304` on a guessed validator) and
  `X-Robots-Tag: noindex, nofollow, noarchive`. See [demos.md](demos.md).

**The site's pages have no CSRF surface, and that is a property rather than an oversight.** It rests
on two facts, either of which would be enough: no page starts a session, and the site's one cookie,
`lang`, is a preference rather than a credential — it opens nothing, so a cross-site request that
carries it gains nothing (and it is `SameSite=Lax` all the same); and no page has a `<form>`, while
the Basic-authenticated routes are ones the browser sends credentials to because of the realm rather
than the origin. A signed `POST` to `/admin` cannot be forged cross-site either: nothing a browser
sends on its own carries an ECDSA signature.

**The admin's browser door is the one place a session and a form exist**, and a cross-site post is
refused there three times over: the `__Host-session` cookie is `SameSite=Lax`, so it is not sent;
every post must carry the form token its session handed out, which the admin checks itself rather
than through `CsrfGuard`, because the guard on its routes would refuse every signed write; and every
write needs a fresh tap of the unlocking passkey over a challenge bound to that write's method and
address, which no other origin can ask for. See
[phpanta/docs/security.md](../phpanta/docs/security.md#a-browser-by-passkey).
([history](history/api.md))

### 5. The response — output safety in the markup tree

The framework's — see [phpanta/docs/security.md](../phpanta/docs/security.md#5-the-response--output-safety-in-the-markup-tree).
The site's side of it:

- **The verify script fails a heredoc or a `'<tag'` literal** anywhere under `src/` or
  `phpanta/src/`. The framework's `MarkupTest` pins the one `htmlspecialchars` call site and every
  spelling of an off-origin URL `Element` refuses, and the site's `HtmlTest` that nothing under
  `src/` escapes for itself.
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

## The admin

The framework's — see [phpanta/docs/security.md](../phpanta/docs/security.md#the-admin).
What is this site's about it:

- **That it exists is public, by decision.** The source is open, so an address pretending not to be
  there would hide nothing a reader could not look up; what the gate keeps is what is *in* the
  admin. Nothing on the site links to `/admin` yet. ([history](history/api.md#2026-09-14--the-admin-moves-to-admin-and-says-that-it-is-there))
- **A browser opens it with a passkey the signing key enrolled.** It registers at the entrance and
  is shown an enrolment code; `php tools/api.php access v1 enrol --code <code> --name <name>` makes
  it a device; it unlocks for eight hours, and taps again for every write. A browser can never
  enrol itself, and never runs `update v1 patch`. See
  [phpanta/docs/security.md](../phpanta/docs/security.md#a-browser-by-passkey) and, for the steps,
  [deployment.md](deployment.md#6-the-admin-in-a-browser).
  ([history](history/api.md#2026-09-14--a-browser-by-passkey))
- **`Site::origin()` is what switches passkeys on here.** It names `https://neurosys.gg`, so a
  passkey's relying party is `neurosys.gg`. In development and from loopback only, the request's own
  `Origin` comes first, so the local Apache — with `PHPANTA_ENVIRONMENT=development` in its vhost —
  runs a real ceremony at `neurosys.localhost`, against a key registered there. A passkey is bound to
  the host it was registered on, so a local device's key opens nothing on the live site.
- **The browser door needs three things per deployment**: `data/session.key`, which seals its
  sessions and its enrolment codes; `data/admin-passkeys.json`, the enrolled devices, absent meaning
  none; and `data/throttle/`, where the entrance counts its posts, ten per address in fifteen
  minutes. `deploy.sh` excludes the two files, and creates none of the three.

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
- **No push can reach `data/`**, which is what keeps `data/update.pub`,
  `data/session.key`, `data/admin-passkeys.json`, `data/demos.php` and 8.6 MB of unreleased audio
  out of reach however well a payload is signed.
- **`Authorization` has to survive Strato**, and `public/.htaccess` puts it back with
  `E=HTTP_AUTHORIZATION`. Every demo's Basic gate already depends on it arriving, which is the
  strongest evidence available that Strato forwards it — but a proxy that strips it fails closed and
  in silence, looking exactly like a bad key, so re-check it on the live host rather than reason
  about it. ([history](history/api.md))
- **Strato reports one directory two ways** — `/home/strato/…/cgi-bin/neurosys` against
  `/mnt/web505/…/cgi-bin/neurosys` — which is why `App::webroot()` takes only `DOCUMENT_ROOT`'s
  basename. A push leaves byte-identical files alone for the NFS reason in
  [deployment.md](deployment.md).
- **The verify script holds the framework to its claims over real HTTP**: every method, `BREW`
  included, at every admin depth, existing or not — a `303` for a page and a `401` for data; the
  entrance's headers, the `NS1` challenge and the `406`; six malformed-credential probes, each a
  `303`, or Apache's `400` or a `431` for the one over the header limit; `/api/*` answering exactly
  like `/no-such-page`; nothing under `public/admin/`; and `openssl_` only in `PublicKey`, with no
  signing or key-minting call under `src/` or `phpanta/src/`, the way it pins `curl_` to
  `CurlTransport` under `tools/lib/`.

## Known and accepted

What is open, or accepted as a decision, with the reason each is where it is. Everything else the
assessments turned up is fixed — see [history/security.md](history/security.md).

- **Static assets carry no security headers.** `.htaccess` passes real files through before the
  rewrite to `index.php`, so no PHP runs for `/assets/**` — see [Transport](#1-transport--https-and-hsts). The same is true of the
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
- **A captured read credential replays within the ±300 s skew window**, yielding the answer to a
  read to somebody who has already broken TLS. See
  [What a signature covers](../phpanta/docs/security.md#what-a-signature-covers-and-why-replay-is-closed).
- **No audience field.** Cross-deployment replay is closed by key separation; if two deployments
  ever share a key, an `aud` field is what to add.

## What is deliberately not here

- **No web-application firewall, and no rate limit listed.** This is a static site on shared hosting;
  the perimeter is the host's. The framework ships a `RateLimit` layer, and this site lists none: its
  pages take no attempts, and the one door that does, the admin's entrance, counts them itself.
- **No form tokens and no session outside the admin.** A page has nothing for them to protect — see
  the CSRF paragraph under [Authentication](#3-again-authentication). The CSP's `form-action 'self'`
  holds the admin's forms to this origin as well.
- **No cookie or consent banner** for the site itself — its one cookie is the language a visitor
  chose, set only when they click the switch. The one consent gate is on
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
