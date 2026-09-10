# History — security

How the security posture in [../security.md](../security.md) got its current shape: the dated
assessments and the findings they closed, the request-parsing and header faults, and the CSP
allowances that were removed. The API's own story is in [api.md](api.md); the markup tree's in
[markup.md](markup.md).

## The assessments

### 2026-09-04 — the first assessment, and the anchors that meant "before a newline" (`0b863b6`, `39c8939`)

*Moved from security.md's "The assessment (2026-09)".*

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

### 2026-09-08 — the update endpoint, reviewed (`91b0e2e`)

*Moved from security.md's "The update endpoint, reviewed (2026-09-08)".*

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

### 2026-09-09 — the newer surfaces, reviewed (`650272a`)

*Moved from security.md's "The newer surfaces, reviewed (2026-09-09)". The two observations and the
one unexercised surface it named are still open, so they are carried in security.md's
"Known and accepted" rather than here.*

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
to fix and the second is a decision — so both are recorded here rather than closed in a commit:
`TRACE` answered by the server rather than the site, and the SoundCloud `secret-token` being public
by design. One surface, `FileResponse` and `ByteRange`, was reviewed rather than exercised. All three
are in security.md's "Known and accepted".

### 2026-09-09 — the API, reviewed (`df613de`)

*Moved from security.md's "The API, reviewed (2026-09-09)". Its fourth item — `public/api/` must
never exist — and the site-gate interaction are current rules and stay in security.md.*

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

### 2026-09-10 — `TRACE`, checked on the live host

*Moved from security.md's "Known and accepted", where it stood open.*

> **`TRACE` is answered by the server, not the site — low severity, and not reachable through this
> code at all.** Apache's default `TraceEnable On` answers a `TRACE` before the request reaches
> `index.php`, echoing the request's own headers back in a `message/http` body — and because it never
> reaches PHP, that response carries none of the security headers a real one does. It is the same
> "static assets never reach PHP" gap, one step worse because the echoed body is attacker-shaped. It
> is bounded on every side that matters: a browser refuses to issue a cross-origin `TRACE`, so the
> classic cross-site-tracing route to a stored credential is closed in the client; the site sets no
> cookie to harvest; and the `Authorization` header both gates read is not one a cross-site `TRACE`
> could originate. It cannot be fixed where the rest of this repository's Apache config lives —
> `TraceEnable` is a server-level directive, invalid in `.htaccess`, so the only lever on shared
> hosting is a `RewriteRule` that refuses the method at the edge. **Open:** a `curl -X TRACE`
> against the live host would say which way Strato has it; the local rig has it on, and the local
> rig is not Strato.

The curl was sent against `/`, `/releases` and a static path under `/assets/`. Each came back
`HTTP/2 405` from Apache itself — `Server: Apache/2.4.68 (Unix)`, an empty `Allow`, Apache's own
`text/html` body, and none of the probe's headers echoed. Strato has `TraceEnable` off, so there is
nothing to close; security.md states the result where the method gate is described.

## Response headers and the CSP

### 2026-09-04 — `'unsafe-inline'` leaves `style-src` (`a0c6a1a`)

*From CLAUDE.md and security.md.*

`style-src` is strict too: it carried `'unsafe-inline'` only for SoundCloud's attribution markup,
and `<soundcloud-player>` sets those properties through the CSSOM instead — same styling, nothing for
the allowance to cover.

security.md put the same fact as: the SoundCloud player sets its accent through the CSSOM rather
than a `style` attribute, so the one thing `'unsafe-inline'` used to cover no longer needs covering.

### 2026-09-05 — `data:` leaves `img-src` (`61becda`)

*From CLAUDE.md and security.md.*

`img-src` went the same way: it allowed `data:` on the strength of a comment saying the cover
placeholder needed it, and the placeholder references nothing at all.

### 2026-09-05 — the source maps stop being public (`0e0d0e6`)

*From security.md's "Transport".*

That used to include the source maps, which carry the whole commented TypeScript because `tsconfig`
sets `inlineSources` — 79,354 bytes of it, ungated. **The prod build deletes every one of them** and
asserts no shipped module still names one, so on the live host there is nothing there to be ungated;
the verify script checks both halves. It was a non-issue either way, since the source is public on
GitHub, but that was a reason not to worry about it rather than a reason to serve a second copy.

### 2026-09-05 — header values arrive second (`867372f`)

*From CLAUDE.md's Architecture section.*

**Both halves are typed, and the value half arrived second.** The name was an enum first because a
misspelled header name is silent; the value stayed a string on the reasoning that a value is just
text. So is a name. The difference is that a header value has a **grammar** — a quoted `ETag`, a
comma-separated `Allow`, `Basic realm="…"`, `max-age=…; includeSubDomains`, `no-store, private` —
and every one of those was being assembled at a `new Header(…)` call site, which is the one place a
grammar cannot be checked. `ContentSecurityPolicy`, `PermissionsPolicy`, `StrictTransportSecurity`
and `MimeType` already had `render()` and only ever lacked the interface saying what it was for;
`CacheControl`, `ETag`, `Vary`, `Allow`, `BasicChallenge` and `Location` are the ones that had
nowhere typed to live.

Two things fell out of it. `SecurityHeaders::send()` used to flatten each case to a string and parse
it back with `SecurityHeader::from()` one line later, purely because the value beside it was a
string — that round trip is gone. And `Location` is now checked: an absolute `https://` URL, the
same shape `Profile` demands, which makes it the one address the site emits that used to have
nothing looking at it.

## Parsing the request

### 2026-09-05 — `parse_url()` gives way to `Uri\Rfc3986\Uri` (`4fb5d1a`)

*From CLAUDE.md and security.md.*

It used to be `parse_url()`, which signals failure with `false` rather than null — so `?? '/'` read
as a guard and was not one, and the `false` reached `rtrim()` as an uncaught `TypeError`: `GET ///`
was a 500, ahead of the router and ahead of the 405 gate. It is `Uri\Rfc3986\Uri::parse()` now,
which returns **null** on a target it cannot read — the thing `??` was looking for all along — so the
trap is gone rather than guarded against, and `///` comes back as the root because that parser can
actually read it.

### 2026-09-08 — the Basic token spelled once (`2405b8f`)

*From CLAUDE.md's Architecture section.*

**Both sides of the Basic handshake now spell its token once.** `BasicChallenge` wrote
`Basic realm="…"` into the 401 and `Request::fromGlobals()` matched `'Basic '` on the way back in,
in two files, neither knowing about the other — and a mismatch there is the quietest failure on the
site: `Request::authorization()`'s docblock already describes it about the header's *name*, and it
is the same failure. Both gates would refuse everything, identically, on every attempt, with
nothing in any log, and the first thing anybody would suspect is the credentials file. It is
`AuthScheme::Basic` at both ends now, and the grammar under it —
`Basic SP base64(user ":" pass)` — lives in `credentials()` rather than as two `explode()`s in the
middle of building a request.

### 2026-09-09 — the realm a visitor writes, and the route claim nobody checked (`e1f08a5`)

*From CLAUDE.md's Architecture section.*

**The realm inside that token is checked too, and it was the last header value that checked
nothing.**

**That fallback used to be the whole target, and the sentence justifying it was false.** It read
"so it matches no route and 404s", and `Route::matches()` compiles `{slug}` into `([^/]+)`, which
matches anything at all — so every placeholder route matched, and whatever followed the `?` arrived
*inside a captured value*. `/demos/x"y?a=1` reached `DemoController` with a slug of `x"y?a=1`, and a
demo's slug is what names its `WWW-Authenticate` realm, so a query string and a raw quote reached a
response header on the one route built to give nothing away. Two things are worth keeping from it:

- **The claim was checkable in one call and was never checked.** It was a statement about `Route`,
  written in a file that does not import `Route`. `RoutingTest` now pins it from the other side —
  that a malformed target *does* match a placeholder route — which is the row nobody wrote, and
  `RequestTest` pins the cut.
- **`BasicChallenge` validates its realm now**, because a value with a grammar is checked by the
  class that owns it: RFC 9110's `qdtext`, so no `"` and no `\`, and never empty. It was the one
  `HeaderValue` carrying something other than a fixed vocabulary that checked nothing, next to a
  `Location` that refuses a non-`https` URL and a `CspHost` that refuses anything but a bare
  origin. It throws, which reports a realm built wrong *here*; what a **visitor** sends is dealt
  with one layer out, by `Auth::demoRealm()` `rawurlencode`ing the slug — the treatment
  `SitePath::to()` already gives the same value, a no-op for every real slug, and what keeps a
  hostile target a 401 rather than the 500 a throw would make of it. Same two-layer arrangement as
  `Profile::$url`.

Note what was **never** wrong, because it bounds how bad this was: `header()` refuses a value
containing CR or LF, so no second header was ever reachable, and the same input is escaped
correctly one route over — the 404 renders it through `Element::render()` as
`find "/a\&quot;b&lt;script&gt;"`. Note also what was doing the work in production, because it is
the part not to lean on: Strato's proxy percent-encodes a non-`pchar` byte before PHP sees the
target, so the live host answered a well-formed realm while a bare Apache 2.4 — *the version the
live host runs* — did not.

## The shape of the site

### 2026-09-08 — "read-only" stops being the whole sentence (`791fbc4`)

*From security.md's introduction.*

**One sentence used to be longer.** This document said the site was read-only, accepted no upload
and persisted nothing a request sends. That is no longer true: `/api` accepts a POST carrying a
gzipped tarball and writes it into `src/` and the webroot. It is the deploy path, replacing minutes
of `rsync -c` over an SFTP mount where a single `stat` costs 480 ms. Everything else in this
document still holds, and the claims that changed are corrected where they appear rather than
quietly reworded.

### 2026-09-08 — the CSRF argument loses a leg (`791fbc4`)

*From CLAUDE.md and security.md.*

**There is no CSRF surface here, and that is a property rather than an oversight — but it now rests
on two legs rather than three, which is worth stating rather than leaving as an unchanged
paragraph.** The leg that went is "the only state-changing verb is refused by the 405 gate":
`/api/update/v1/patch` honours a POST.

security.md's version of the three-leg argument, as it stood: it rests on three independent facts,
any one of which would be enough: the site sets no cookie and starts no session, so there is no
ambient credential for a cross-site request to ride; there is no `<form>` and the only
state-changing verb is refused by the `405` gate; and the one authenticated route is Basic, where the
browser sends credentials because of the realm rather than the origin.

### 2026-09-09 — the unaudited hole closes (`17cca79`)

*From security.md's "The response". The full story is in [markup.md](markup.md).*

**There is no unaudited hole, and there used to be one.** `RawHtml` emitted `data/privacy.*.html`
verbatim; `MarkupParser` reads those two files into the tree instead, so a hand-authored document is
subject to every rule above rather than exempt from them.

## From the code comments

*Moved out of comments under `src/`, `test/` and `tools/` when those were brought to the present
tense. Quoted as they stood; an ellipsis marks where a passage was cut short.*

### 2026-09-05 — one malformed log line took down the stats page (`a2e8502`)

From `ServiceTest`:

> The two are different cases and used to be the same one. … before this a single malformed line
> 500'd the whole stats page instead.

### 2026-09-05 — documents inherited their `Content-Type` (`a2e8502`)

From the verify script:

> ViewResponse used to send no Content-Type at all and inherit PHP's default_mimetype — right by
> accident of the runtime's ini, and unwritten anywhere.

`MimeType` told the same story from the other side:

> The enum this replaced held `text/html` as one opaque string and stapled `; charset=utf-8` onto
> every case

> {@link ViewResponse} used to send no `Content-Type` at all and inherit PHP's `default_mimetype` and
> `default_charset` ini settings … it was the only response on the site whose headers were not
> written down anywhere

### 2026-09-04 → 2026-09-09 — smaller framings from the header classes

- `ContentSecurityPolicy` (`e3aceef`): "hand-written string this used to be"
- `Allow` (`867372f`): "marking a method read-only used to mean remembering to edit both"
- `Auth` (`e1f08a5`): "louder than the malformed header it replaced"
- `SecurityHeaders` (`15a10f1`): sending the headers went 33.17 µs → 34.58 µs; the comment now
  states the absolute cost.
