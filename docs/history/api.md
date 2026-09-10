# History — the API

How `/api` in [../security.md](../security.md#the-api) and [../deployment.md](../deployment.md) came
to be the way it is: `/update` becoming a family of addresses, the credential moving into
`Authorization`, the serial, the mirror, and the webroot that emptied this repository. The
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
