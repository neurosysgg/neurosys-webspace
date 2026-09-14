# History — the API

How the admin in [../security.md](../security.md#the-admin) and [../deployment.md](../deployment.md)
came to be the way it is: `/update` becoming a family of addresses, the credential moving into
`Authorization`, the serial, the mirror, the webroot that emptied this repository, and `/api`
becoming `/admin`. The
security reviews of the endpoint are in [security.md](security.md).

## `/update`

### 2026-09-08 — the method question moves onto the route (`791fbc4`)

*From security.md's "The method gate" and CLAUDE.md's "The API".*

**The question moved onto the route when the signed endpoint arrived**, and it is a `MethodPolicy`
rather than a set of methods. That is not a stylistic choice. A route carrying its own set would
make the `405` name it, so `PUT /api/update/v1/patch` would answer `Allow: GET, HEAD, POST` — and
that `POST` is precisely the fact the endpoint exists to hide. An unrecognised verb was worse:
`null` is in no set, so the refusal would have named the whole set. So there are two policies, not
ten sets: nine routes are `ReadOnly` and refuse exactly as the old global gate did, and `/api` is
`Delegated`.

### 2026-09-08 — P-256, verified before it was relied on (`791fbc4`)

*From CLAUDE.md's "The API".*

P-256 was verified end to end on the live host before a line was written.

### 2026-09-08 — the mistake written down: one class answering two questions (`791fbc4`)

*From CLAUDE.md's "The API" and security.md's "Where the roots resolve, and the mistake that is
written down". The guards it produced are current and stated in security.md.*

**One mistake in this feature is written down rather than merely fixed, because it reads as
reasonable and is not.** `UpdateRoot` originally answered both "is this name under a root" and
"where does that root live", and the second question reaches `DOCUMENT_ROOT`. `Config::webroot()`
takes only the **basename** of that variable — it has to, because the live host reports one
directory under two different absolute paths (`/home/strato/…/cgi-bin/neurosys` against
`/mnt/web505/…/cgi-bin/neurosys`) and a path built from the wrong one compares equal to nothing. But
a basename grafted onto a different tree names a real directory somewhere else, and a test pointing
`DOCUMENT_ROOT` at a sandbox got *this repository's* `public/` back. The mirror emptied it, and
`src/` with it. Two guards came out of that: `Config::webroot()` now refuses a `DOCUMENT_ROOT` that
is not inside the deployment, comparing `realpath()` on both sides rather than guessing — every
candidate guess is a directory something would then be willing to delete — and `UpdateApplier` takes
its `Deployment` as a constructor argument, so a test cannot reach the live tree rather than being
unlikely to.

security.md told it as: that separation exists because it was originally one class, and the join was
a real fault: deciding whether a name was *under* `public/` meant resolving where `public/` **is**,
which reaches `DOCUMENT_ROOT` — and during development a test that pointed `DOCUMENT_ROOT` at a
sandbox got this repository's `public/` back and the mirror emptied it.

### 2026-09-08 — two live faults found by writing the tests (`1f9bf5e`)

*From CLAUDE.md's "Tests".*

Two live faults came out of writing those tests rather than out of running the code. `DOCUMENT_ROOT`
was not trimmed, so `'   '` reached the containment check, where `dirname('   ')` is `'.'` and its
`realpath()` is the working directory — the deployment itself, under the test runner. And a name
whose *parent* is the deployment but which is not there passed too, so `/…/deployment/nonexistent`
resolved to a webroot the first push would have created and written the whole site into, beside the
real one, served by nothing. Both are refusals now.

### 2026-09-08 — the `.nfs` stray, and whose bug it was (`791fbc4`)

*From CLAUDE.md's "The API" and deployment.md. The mechanism and the fix are current and stated in
deployment.md.*

A push writes what changed, not what it carries, and that is `rsync -c`'s rule arrived at the hard
way rather than borrowed. One push, one undeletable file in the webroot, one spurious failure, a 500
on a push that actually worked.

**The interesting half is that this was never the endpoint's bug.** `deploy.sh` strands the same
inode every time it rsyncs `index.php` — rsync writes beside and renames exactly as `File::write()`
does — and the first stray found in the webroot was dated to the rsync that had run twenty minutes
earlier, not to any push. The endpoint is simply the first thing here that *mirrors*, and so the
first thing that ever looked. A push that reports `failed` now means something, which is the whole
point of not letting it cry wolf once per deploy.

## `/api`

### 2026-09-09 — one address family instead of one address (`5baabd4`)

*From CLAUDE.md's "The API" and security.md.*

**It was `/update`, one address with one verb, and generalising it cost less than adding a second
endpoint beside it would have.** Every property below — the silence, the method policy, the key, the
serial — had to hold for the next owner-only tool too, and the choice was to arrange them once more
or to arrange them once. `/update` is gone rather than aliased: an endpoint whose design is to be
unfindable does not want two doors.

### 2026-09-09 — the bootstrap deploy (`5baabd4`)

*From CLAUDE.md's "The API" and deployment.md's refusal list. It was a one-time step and the live
host has since been deployed past it.*

**Which leaves one bootstrap the endpoint cannot do for itself**, and it is worth knowing before
deploying: a server still running the old code answers `/api/…` with the 405 it gives any absent
address, and `--url` cannot reach `/update` any more because it takes an *origin* and appends the
path. So **the first deploy of the commit that creates `/api` goes over the mount** — `./deploy.sh`,
which is the case it is kept for. Every push after it is one request again.

deployment.md listed it as a fourth refusal cause:

4. **An old server.** One that has not yet had this code pushed to it is still answering on
   `/update` and has never heard of `/api`, so it refuses with a `405` — the same refusal a bad
   signature gets, which is why this is on the list.

   **The way out is `./deploy.sh`, and `--url` will not do it.** That flag now takes an *origin* and
   the path is appended, so `--url https://neurosys.gg/update` asks for
   `https://neurosys.gg/update/api/update/v1/patch`. This is the bootstrap the endpoint cannot do
   for itself, and it is the case `deploy.sh` is kept for: **the first deploy of the commit that
   creates `/api` has to go over the mount.** Every push after it is one request again.

### 2026-09-09 — the credential moves into `Authorization` (`5baabd4`)

*From CLAUDE.md's "The API" and security.md.*

**This paragraph used to argue the opposite**, and half of that argument was simply wrong rather
than outdated: it said a header would put cases into the browser's bundle, because `RequestHeader`
is mirrored in TypeScript and compared case-for-case — but `Authorization` is a
**`ServerVariable`**, not a `RequestHeader`, and has no mirror.

security.md's version: **Why a header is acceptable here**, given this document's earlier objection
to one: that objection had two halves and only one survives.

The body read it replaced, from security.md's attack surface: its predecessor read up to 8 MB before
it could refuse anybody, which this document previously named as the one thing that could tell the
endpoint from a typo — by how long it took to drain.

And the binding it forced, from both documents: a credential that authenticates *some* request
rather than one was sound while `/update` was the only address there was to sign for, and stops
being sound the moment there are two.

### 2026-09-09 — the serial is spent before the action, not after (`5baabd4`)

*From CLAUDE.md's "The API" and security.md.*

**What changed is when a write spends it: before the action, not after.** The old order wrote the
whole tree and then discovered it could not record the serial, leaving a deployment that had been
updated and a credential that could update it again — and nothing to do but say so in the report.
Arming first turns that into a refusal with nothing written.

### 2026-09-10 — `health`, the extension point checked (`8f766f0`)

*From CLAUDE.md's "The API", security.md and deployment.md.*

**`health` is that claim cashed, and it came to exactly what the paragraph above says it would**: an
`ApiService` case, one arm of one `match`, an action enum and a handler. No route, no method policy,
no second arrangement of the gate, the silence or the serial — and `tools/api.php` reached it with
**no change at all**, because that command resolves an address through the site's own `ApiService`
and `ApiAction` rather than through a copy. A claim about an extension point made while one thing
had ever used it is worth checking rather than trusting, and this one held.

**What it reports is the set of facts this repository asserts and has never checked.** The live
host's error configuration — `display_errors` off, `error_log` empty — was stated as measured fact in
**five** docblocks, every one of them a copy of one measurement taken by hand. Two statements of one
fact with nothing keeping them in step is the failure this whole file is arranged against, and
neither statement could speak for the runtime that actually answers a request.

deployment.md put it as: **its reason for existing is that none of that had ever been asked of the
live host.** This is the first thing that asks the runtime actually answering requests.

`GuidelineTest`'s two-files clause is what said out loud that the report should carry no replay
serial: the word `serial` exists in `ApiEnvelope` because it is a key of the signed manifest, and
writing it again as a caption in the report would have forced a `#[BareString]` onto *that* class
for a word of this one's. The rule is symmetric, which is what made the duplication visible rather
than arguable.

### 2026-09-10 — `health` split into `capability` and `health` (`2ab7e6e`)

*From the conversation that asked for it, and the plan it was built from.*

**The report answered two kinds of question in one voice.** Most of its lines were inventory — a
version, a SAPI, the ini values, a clock, a log's tail — and a few were verdicts: an extension
`MISSING`, a tracked file `absent`. Nothing told a reader which lines were claims, and the report
always answered 200, so "is anything wrong" had no answer a script could read.

It became two services. `capability` lists what the host has, all of it — every extension and every
directive rather than the nine `PhpSetting` named — and judges nothing. `health` checks declared
requirements and answers **503 when a required one is unmet**, with the whole report in the body.
`health v1 report` kept its address and became "every check"; the inventory lines moved, and no
alias was left behind, because the endpoint's design is to have no second door.

**The requirement core was written to be lifted out.** The user was considering extracting a
framework, so `Model/Health/` imports nothing of this site's, and the site declares its own floors
through the same extension point a user would — `RequirementInitialization`, beside
`RouteInitialization`, and two requirement classes of its own under `Service/Health/`. The user's
example of what a user might need to declare was COM interop on Windows; `ExtensionRequirement`
with a proof closure covers it in one line.

Three decisions worth keeping:

- **Optional requirements exist from the start**, with `opcache.enable` as the first real one, so
  the `warn` level is a branch the site takes rather than a case no test reaches.
- **`update.pub` is not a requirement.** A verified call has already proved the key, so the check
  could never fail.
- **The `memory_limit` floor is derived from `ApiGate::MAX_BODY`** — three copies of a push coexist
  at its peak — which made the constant public rather than restating 8 MiB in a second place.

**Whether Strato would let a 5xx body through was the one thing the repository could not answer**,
and it was asked the documented way: a push from a detached worktree at `9790d25` declaring one
required extension no PHP has, `health v1 extensions` called against it, and `HEAD` pushed back.
The answer was `HTTP/2 503`, `content-type: text/plain; charset=utf-8`, and the report intact.

The first live report also said two things nothing had asked before: `opcache.enable` is `0` on the
live host — the optional requirement's first real `warn` — and the last unhandled diagnostic there
was PHP 8.5's deprecation of deriving `$_SERVER['argv']` from the query string, raised at startup
under `cgi-fcgi` because `register_argc_argv` is on.

### 2026-09-13 — a push that replaced the code applying it (`0381de4`)

*From deployment.md's paragraph on a push that answers 500 and has landed.*

The push that shipped the Phpanta feature batch (serial 1789322381) reported `refused with 500`, and
every other sign said it had landed. The new build stamp was live, `health v1 report` passed, and
the two new cross-origin headers were on every page. The error log held one line: the old
`App::run()` calling `PlainTextResponse::send()`. The batch had made every response return an
`Answer` rather than send itself, so the class the push had just rewritten no longer had that
method — and the request applying the push, still the old code, was the one that loaded it.

A follow-up dry run read `written 0, unchanged 364`, and named only the `.nfs` stray the new
`public/index.php` left behind. It was also the last push applied without a record of the release
it replaced: the old applier did not take one, so the first push that can be rolled back is the
next one.

## `/admin`

### 2026-09-14 — the admin moves to `/admin`, and says that it is there

*From security.md's "The attack surface", "The API" and "Known and accepted", and CLAUDE.md's
"The API and deploying".*

The signed API moved from `/api/{service}/{version}/{action}` to `/admin`, at four depths — the
entrance, a service, a version and an action — and the three above an action came to list what is
under them. `/api` kept no alias; it matches no route, and answers exactly like `/no-such-page`.

**Why.** The owner wanted an admin that could be found and walked: a page by default, in the site's
own shell, and JSON when asked for with `Accept: application/json`, which is what the signing
commands send. The old family could not be walked by anybody, the key holder included, without
knowing every address in advance. And its central claim had stopped being worth its cost: the
repository is open source, and the site is to link to the admin from its footer, so that there *is*
an admin is public either way. What a stranger must not learn is what is in it.

**What was given up** is the indistinguishability. Every address under `/api`, to any caller without
a signature that verified, answered exactly as an address that does not exist — the app's `404` for
a read, the `405` for anything else, both from `UnroutedController`. In its place is uniformity
inside `/admin`: one answer at every depth below the entrance, whether the address exists or not — a
`303` to `/admin` for a page, a `401` challenging for `NS1` for data. The measured ~180 µs between the
`/api` shape and a typo went with it, since it only ever mattered as a leak of existence.

**`/admin/stats` was deleted.** It was the site's one gated route, behind the framework's Basic
`AdminGate`, and it collided with `/admin/{service}`, claiming one of the admin's own addresses for a
site page. It had nothing to show besides: download logging is off for legal reasons, so the page
said only that. `StatsController`, `StatsView`, `StatsText`, `DownloadStats`, its stylesheet and
`AdminTest` went with it, and `SitePath::Stats`. `DownloadLogger`, `DownloadLogEntry` and
`Site::DOWNLOAD_LOGGING` stayed, with logging still off, and `data/admin.php` stayed as an inert
placeholder, because the framework tracks it and `health v1 deployment` asks for it.

security.md listed the old family as the tenth route:

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

and carried the timing twice, under the API and under Known and accepted:

- **About 180 µs** separates the `/api` shape from a typo for a caller already sending an `NS1`
  credential, measured on localhost in the 2026-09-09 pentest — see
  [Known and accepted](../security.md#known-and-accepted).
- **About 180 µs separates `/api` from a typo for a caller already sending an `NS1` credential.**
  Not a usable oracle; see It answers as though it is not there, in the framework's security
  document.

CLAUDE.md said of it: `/api/{service}/{version}/{action}` is the one address family that writes.
Every call is signed with an ECDSA P-256 key the server cannot use; an unsigned call gets exactly
what an absent address gets.

### 2026-09-14 — a browser, by passkey

*From security.md's opening, "The attack surface", the CSRF paragraph, "The admin" and "What is
deliberately not here", deployment.md's "Full deploy", and CLAUDE.md's "The API and deploying".*

The admin came to let a browser in: a device registers at `/admin`, the signing key enrols it with
`access v1 enrol`, and it unlocks with a passkey for eight hours and taps again for every write. The
design and its reasons are the framework's, in its own history; what was this site's is below.

**A client certificate could not work on Strato.** TLS ends at Strato's front proxy — the reason
`.htaccess` asks `X-Forwarded-Proto` as well as `%{HTTPS}` — so neither Apache nor PHP ever sees a
handshake, and `.htaccess` has nothing to request a certificate with. WebAuthn works over plain
HTTPS and keeps only a public key on the server, which is the property `data/update.pub` has.

**Sodium was judged overkill.** Strato registers `sodium` 8.5.9, but none of the three local
runtimes has it, so every use would have been a floor the tests could not exercise; and none of what
it offered — Ed25519 passkeys, Ed25519 for `NS1`, XChaCha20 for the session seal — closed a real
hole. P-256 through openssl serves both the CLI and the browser.

**`Site::origin()` came to return `Origin::of(Site::ORIGIN)`**, which is what switches the passkeys
on here, and `data/session.key` came into use, sealing the admin's browser sessions and enrolment
codes. `data/admin-passkeys.json` joined the files `deploy.sh` excludes and `.gitignore` names. The
footer link to `/admin` was left for the next batch.

The opening of security.md said the site "is static, has no database, sets no cookie, starts no
session, has no `<form>`, and has no runtime dependencies". Its CSRF paragraph ended:

`/admin` does accept a `POST`, and a cross-site `POST` to it cannot forge an ECDSA signature. So there
is no form token, and nothing for one to protect.

The framework has both halves of the other arrangement — a sealed cookie session, and the
`CsrfGuard` layer that holds every write to the token that session handed out … The day this site
has a form and a login is the day this paragraph changes, and they are listed on the routes that take
the writes.

The admin section said: Nothing on the site links to `/admin` yet, and a browser, which cannot sign,
sees only the entrance. deployment.md said "a session key, if the site ever keeps sessions, is minted
on the host it serves and never leaves it", and CLAUDE.md: The site keeps no session today, so it has
no `session.key`.

## From the code comments

*Moved out of comments under `tools/` and `test/` when those were brought to the present tense.
Quoted as they stood; an ellipsis marks where a sentence ran on into the rule it supported, which
stays in the code.*

### 2026-09-08 — a push that sent an empty POST (`791fbc4`)

From the push side of `tools/lib/Http/`:

> …which is what it used to ask … that guard skipped attaching its body and sent an empty POST — and
> the update endpoint … answered like an address that does not exist.

### 2026-09-10 — the extension list, stated twice before it could be checked (`8f766f0`)

From `HealthTest`:

> The list existed in two places before — `composer.json` … and `test/basic_test.sh` … neither could
> speak for the host. PhpExtension is the third statement of it and the first that can be compared

### 2026-09-09 — the credential needed no magic of its own (`5baabd4`)

From `AuthScheme` and `ApiCredential`:

> this token is what replaced a magic: the credential needs no `NSU1` of its own

> Its predecessor framed three segments into the request body and needed a prefix for each of the
> first two and an `NSU1` in front

### 2026-09-09 — `UpdatePatch` was `UpdateController` from the signature down (`5baabd4`)

> It is what `UpdateController` used to be from the signature check down … What it is no longer is a
> Controller

### 2026-09-09 — the version question had no route (`5baabd4`)

From `UpdateAction`:

> They were one route and no route respectively — `/update` accepted a POST and there was no way at
> all to ask the second question, which is why the answer used to be a `curl` against the home page
> and a look at the asset URL in the markup.

### 2026-09-09 — the replay guard armed after the write (`5baabd4`)

From `ApiController`, telling the ordering in the entry above from the other side:

> which is the one behaviour here that differs from the endpoint this replaces. That one wrote the
> whole tree and then discovered it could not arm the replay guard

### 2026-09-10 — both body-limit figures were wrong until `/api/health` could ask (`e2bf4e3`)

From `ApiGate`:

> **Both figures above were wrong until `/api/health` could ask**, which is worth recording rather
> than quietly correcting, because the two were wrong in different ways. The ratio said *a fortieth*
> and had been wrong since it was written: `post_max_size` on the live host is 128M, and 8 MiB is a
> sixteenth of it, not a fortieth. The payload said 210 KB and had merely **drifted**, because it
> grows with the codebase and nothing re-derived it.

### 2026-09-10 — the extension point, cashed twice (`2ab7e6e`)

From `ApiService`, which counted its services:

> Two cases, and the enum would be worth having at one … **{@link self::Health} is what cashed that
> claim**, and it cost what the paragraph above said it would: a case here, one arm of the `match`
> below, an action enum and a handler. No route, no policy, no second arrangement of anything.

From `HealthReport`, whose inventory half is now `capability`:

> **It exists because every fact below is currently asserted somewhere and checked nowhere.** … The
> live host's error configuration is stated as measured fact in five separate docblocks … every one
> of them a copy of one measurement taken by hand. Nothing has ever asked the runtime that actually
> answers requests. This does.
