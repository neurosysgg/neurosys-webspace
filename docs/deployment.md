# Deployment — Strato

## The layout on the server

The deployment sits in `cgi-bin/` on Strato, and the webroot is a directory inside it. That is what
`App::above()` resolves to — the repository root locally, `cgi-bin/` on the server — and every
other path hangs off it, so `data/` is a sibling of the webroot rather than inside it:

```
cgi-bin/                 ← App::above()
├── neurosys/            ← the webroot (DOCUMENT_ROOT) ← build/dist/public/
├── phpanta/             ← phpanta/src/ + phpanta/autoload.php — the framework, written first by a push
├── src/                 ← src/, with the prod AssetManifest.php laid over it
├── autoload.php
├── data/                ← NOT web-exposed; releases, profiles, credentials, demos, logs/, throttle/
├── .update-serial       ← the API's replay counter; in no mirrored or rsynced tree
├── .update-previous/    ← what the last push replaced, for `update v1 rollback`
└── .update-stage/       ← where a push stages what it writes; empty between pushes
```

`data/.htaccess` (`Require all denied`) goes up with `data/` as a fallback for a host where the
directory would be reachable — it is not load-bearing here, since nothing under `cgi-bin/` outside
the webroot is served.

## First-time setup

Prerequisites are in [../README.md](../README.md) — PHP 8.5 with `ext-uri`, `ext-dom`,
`ext-openssl` and `ext-zlib`, Node ≥ 26.7, Composer, and `openssl` on the path once for the update
keypair below.

### 1. Open the project in PHPStorm

Open the `neurosys/` root as the PHPStorm project. The `.idea/` folder is gitignored — it can contain deployment credentials, keep it local.

### 2. Git

```bash
git init
git remote add origin git@github.com:<you>/neurosys.git
git branch -M main
git add .
git commit -m "initial"
git push -u origin main
```

Or: `gh repo create neurosys --private --source=. --push`

### 3. Mount the host, and `deploy.sh`

`./deploy.sh` rsyncs over the host mounted as SFTP (GVFS). It is gitignored — it holds the host and
account name — so it exists only on the machine that deploys; the mount point and account are its
`SFTP_MOUNT` and `SFTP_USER`. It uploads everything in [The layout on the server](#the-layout-on-the-server)
except three files under `data/`, which are uploaded by hand (steps 4 and 5, and the keypair below).

### 4. Configure PHPStorm deployment (optional)

For pushing one file in a hurry — see [Full deploy](#full-deploy) for what it skips.
**Settings → Build, Execution, Deployment → Deployment → +**

1. Type: **SFTP**.
2. Name: `Strato (neurosys.gg)`
3. Fill in host, port, username, password (save to system keychain, not the project).
4. **Mappings tab**:
   - Local path: `public`
   - Deployment path: `cgi-bin/neurosys` (the webroot)
   - Web path: `/`

### 5. Create `data/logs/`, once

There is no `data/admin.php`: it would hold the credential for the framework's Basic admin gate,
which stands on no route of this site's, and the framework requires no credential file of a
deployment.

`data/logs/` has to be created on the server by hand, once: `deploy.sh` excludes it, and PHP creates
a log file but not its directory. `public/index.php` points `error_log` at
`data/logs/php-YYYY-MM.log`, and without the directory every diagnostic silently goes to Strato's
own log instead — `health v1 report` shows `logs/` as a `warn` until it exists. The download log
would land there too, if download logging were ever switched on.

### 6. The admin in a browser

The admin lets a browser in by passkey — see [security.md](security.md#the-admin). That needs two
things on the server that `deploy.sh` never creates, and a device enrolled by the signing key:

- **`data/session.key`**, thirty-two random bytes, base64: it seals the admin's browser sessions and
  its enrolment codes. Strato has no SSH, so mint it locally, upload it over SFTP as
  `data/session.key`, and keep no copy of the live one here — the working tree's, if it has one, is
  the local deployment's own:

  ```bash
  php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;' > session.key
  ```

- **`data/throttle/`**, a directory writable by PHP, where the entrance counts its posts. Without it
  the entrance answers `503` and takes nothing.
- **`data/admin-passkeys.json`** needs no upload: absent means no device is enrolled, and `access v1
  enrol` writes it on the server. Upload an empty store (`[]`) only if you want the file there
  first; `deploy.sh` excludes it either way.

Then enrol a device:

1. Open `https://neurosys.gg/admin` on it and choose **Register this device**. The page shows an
   enrolment code, written as the command below, and the key's fingerprint. The code is good for
   ten minutes.
2. On the machine holding the signing key:

   ```bash
   php tools/api.php access v1 enrol --code <code> --name phone --dry-run
   php tools/api.php access v1 enrol --code <code> --name phone
   ```

3. Back at `/admin`, **Unlock with a passkey**. An unlock lasts eight hours; every write asks for
   the passkey again. `php tools/api.php access v1 passkeys` lists the devices, and `access v1
   revoke --passkey <credential id>` takes one away from its next request on.

**On a server still running the code from before `/admin`, the first deploy of it is `./deploy.sh`**
— the push cannot reach a server that has no `/admin` (see
[When a push is refused](#when-a-push-is-refused)). Before it, upload `data/session.key`, create
`data/throttle/`, and check `data/logs/` still exists. After it, `php tools/api.php update v1
version`, then register, enrol and unlock as above, re-check `.htaccess`, and check that
`/api/update/v1/version` answers exactly like `/no-such-page`.

### 7. Check the HTTPS redirect on the first deploy

`public/.htaccess` redirects `http://` to `https://`, and `Strict-Transport-Security` then tells the
browser not to try plaintext again for a year. This is the one change nothing local can verify —
`php -S` ignores `.htaccess` entirely — so check it once against the live host:

```bash
curl -sI http://neurosys.gg/ | head -3
```

Two things to confirm, in this order, because the second is hard to undo:

1. **A single 301 to the `https://` URL, and no loop.** Strato terminates TLS at its proxy, so
   `%{HTTPS}` can read `off` on a request that arrived encrypted. The rule checks
   `X-Forwarded-Proto` as well and redirects only when *both* say plaintext — but if a redirect loop
   appears anyway, that pair is where to look, not the header.
2. **Every hostname that resolves here can serve HTTPS**, because the header carries
   `includeSubDomains`. A browser that has seen it will not speak plaintext to *any* name under the
   domain until the max-age runs out, and the only thing that can shorten that is a smaller max-age
   delivered over HTTPS. A subdomain that cannot do TLS becomes unreachable rather than insecure.

If either is in doubt, ship `StrictTransportSecurity::ONE_DAY` first — override
`Site::strictTransportSecurity()` to return `new StrictTransportSecurity(StrictTransportSecurity::ONE_DAY)`
— confirm, then delete the override to put it back to the default year.

## Regular deploy — the signed push

`php tools/push-update.php` deploys `public/`, `src/` and `autoload.php` in **one HTTPS request**.
It exists because the mount is slow in the way that matters: a single `stat` over it costs 480 ms,
walking `src/` costs 3.7 s, and `deploy.sh`'s `rsync -c` reads all 269 files on both sides. The
payload is 250 KB, and grows with the codebase — `--dry-run` prints the current figure.

```bash
npm run build:prod
php tools/push-update.php --dry-run
php tools/push-update.php
```

Afterwards, ask the server what it is actually running — the read half of the same admin, signed
with the same key:

```bash
php tools/api.php update v1 version
```

It answers three lines: the last serial accepted, the versioned entry-script URL (which carries the
build stamp, and is the one fact that tells a deploy that wrote from one that found every file
already current), and the PHP version.

### When you need more than three lines

```bash
php tools/api.php health v1 report          # does this host meet what the site needs
php tools/api.php capability v1 runtime     # what it is; also extensions, settings, deployment, errors
```

Two more services of the same admin, signed with the same key and reached by the same command.
Given fewer than three operands, that command lists what the server offers instead —
`php tools/api.php`, `php tools/api.php health`, `php tools/api.php health v1` — each entry with
what it says of itself; a listing is past the gate, so it is signed too.

- **`health` checks every requirement the site declares**: PHP 8.5, the five extensions the site is
  a fatal without, the php.ini floors a push needs, the webroot and the tracked `data/` files.
  **It answers 503 when a required one is unmet**, with the whole report in the body, so
  `tools/api.php` exits 1 and a script can stop on it.
- **`capability` lists what the host has, with no verdict**: every extension, every directive, the
  clock, every `data/` file, the error log's tail.

Both answer what `update version` deliberately does not. See [health.md](../phpanta/docs/health.md), and
[runtime.md](runtime.md) for what they said about each runtime, side by side. The site declares no
requirement of its own (`Site` does not override `ownRequirements()`), so its `health` report is
exactly the framework's floor.

**Strato passes a `503`'s body through unchanged**: `HTTP/2 503` and the report byte for byte —
measured while the report was still plain text, and a claim about the body rather than its type.
Nothing in `public/.htaccess` replaces an error body either. A front proxy *can*
substitute its own page for a 5xx, which is why this was asked of the live host rather than
assumed. The question was put with a probe push declaring one impossible requirement (see
[Probing the live host](#probing-the-live-host)). If the host changes, ask again the same way.
([history](history/api.md))

**They are the one source for what the live runtime is.** The extensions are declared in
`composer.json`, which never runs on the server because `vendor/` is not deployed. They are asked
for in `test/basic_test.sh`, which runs whichever `php` is on `$PATH` locally. The error
configuration some docblocks quote (`display_errors` off, `error_log` empty) is a copy of a reading
these services re-take. When a docblock and the answer disagree, the answer is right.
([history](history/api.md))

Six lines are worth reading before the rest:

- **`health`'s `FAIL` lines and its tally.** The tally names every verdict even at zero, so
  `0 fail` is the line that says the host is fine.
- **`capability runtime`'s `clock`.** A credential whose serial sits more than five minutes from
  the server's clock is refused, and that is cause number two in the list a refused call prints.
  There is a chicken and an egg here: a clock far enough out refuses the call that would report it.
  But a clock that is *drifting* is caught here well before it costs a deploy.
- **`capability deployment`'s `update.pub`.** It can never read `absent`, because an answer you are
  reading was verified against it. The size beside it is what tells a whole key from a truncated
  paste.
- **Each extension `health` checks is asked by being used**, not by `extension_loaded()`. Registered
  and working are two questions, which is the standard `test/basic_test.sh` already states for
  `ext/dom`. `capability extensions` lists what is registered.
- **In `capability deployment`, a file's presence and whether the repository tracks it are two
  columns, not a verdict.** `absent  (tracked)` reads as the fault it is. `health deployment` fails
  only the tracked files.
- **`capability errors` reads `error_get_last()`**, deliberately process-global. A diagnostic that
  a userland handler takes never populates that function, and every suppression here goes through
  `Diagnostics::muted()`. So the line reports precisely the diagnostics **nothing in this repository
  handled**.

Neither reports the **replay serial**, which `update version` does. They overlap with it on
`PHP_VERSION` and nothing else. Nothing about either answer is public: to a caller the admin cannot
verify, every `health` and `capability` address gives the same answer as `/admin/update/v1/patch`
and as an address that is not there — a `303` to the entrance, or a `401` for data. That is the
only reason answers this detailed are safe to produce at all.

### Probing the live host

When the question is one the report does not answer — whether an extension actually does the thing
about to be relied on, say — put a probe up with a push and take it down with another, **from a
detached worktree at `HEAD`** rather than from the working tree. The endpoint mirrors the whole tree,
so a push from a dirty working tree would ship the change being checked *for* alongside the check.
Run `git submodule update --init` in the worktree first: it starts with an empty `phpanta/`, and the
push refuses one. That is how `ext/dom` was checked by parsing before `MarkupParser` relied on it
([history](history/hosting.md)).

### The flags

`--dry-run` sends a real signed payload and has the server validate every member, report exactly
what it would write and delete, and **write nothing** — it does not even advance the replay serial,
so the same payload can then be sent for real. Use it whenever you are unsure; it costs one request.

`--no-mirror` leaves alone whatever the payload does not mention. The default is to mirror, matching
`deploy.sh`'s `--delete` on these two trees, and mirroring is the only way stale files ever leave the
server.

`--any-framework` ships `phpanta/` as it stands. Without it the push refuses a framework that is not
checked out, has changes that are not committed (an untracked file counts — the push packs every file
under `phpanta/src/`), or is not the commit the site's `HEAD` records, because each would put code on
the server that no checkout of the site reproduces. The refusal comes before anything is signed, dry
run or not, and says which of the three it is.

`--url` points somewhere else; it defaults to `https://neurosys.gg`. It is an **origin** —
`https://`, a host, a port if it has one, and nothing after it. A path, query, fragment or user part
is refused where it is typed: the path is derived from the action, so there is one place that knows
what the address is and it is the same `AdminPath` case the router matches with. It must be `https`;
`Url` refuses anything else, on the one request that carries a signature.

`--key` names a different private key; it defaults to **the origin's own key** —
`~/.config/neurosys/update.key` for `https://neurosys.gg`, and `update-<host>.key` beside it for any
other origin, a port joining the host with a dash (`update-localhost-8443.key`). **The production key
is refused for any other origin**, even named with `--key`, and so is a copy of it under another name.

### First-time setup: one keypair per deployment

**Every deployment has a key of its own.** A signed manifest names a method and a path but no host,
and the replay guard is a counter per deployment — so a credential minted for one deployment verifies
at any other holding the same public key, for as long as its serial is fresh there. Two deployments
sharing a key share every credential, a push included. The tools enforce it (above); this is why.

The **private halves never enter this repository** — they live beside the SoundCloud refresh token,
for the same reason — and the tools refuse one that other users can read. The production pair, once:

```bash
(umask 077; mkdir -p ~/.config/neurosys && openssl genpkey -algorithm EC -pkeyopt ec_paramgen_curve:P-256 -out ~/.config/neurosys/update.key)
```

```bash
openssl pkey -in ~/.config/neurosys/update.key -pubout    # → the server's data/update.pub
```

Upload that public half **by hand**, once, as `data/update.pub` on the server.
`deploy.sh` excludes it — there is no repo copy to sync, and syncing the local key over the live one
would lock you out of the endpoint.

The working tree's `data/update.pub` is the **local** deployment's, from a pair of its own:

```bash
(umask 077; openssl genpkey -algorithm EC -pkeyopt ec_paramgen_curve:P-256 -out ~/.config/neurosys/update-neurosys.localhost.key)
```

```bash
openssl pkey -in ~/.config/neurosys/update-neurosys.localhost.key -pubout -out data/update.pub
```

That is the key a call to `https://neurosys.localhost` resolves to.

**Its absence is the off switch.** No key on the server, no signed call verifies: the entrance
still answers, and every address below it gives everyone the one answer a stranger gets, forever. That is the opposite
polarity to `data/site_auth.php`, whose absence stands its gate *down* — worth reading twice,
because the two files look alike.

`data/update.pub` and `cgi-bin/.update-serial` cover every service, not only the push; they are
named for the service that first needed them. Renaming either would mean a file uploaded by hand on
the server and a replay counter starting again from zero, so they keep their names.

### When a push is refused

A refusal before the signature verifies is a **401**, because the commands ask for data and that is
what the admin answers any request for data it will not verify. It says nothing about why,
deliberately, so check these in order — the command prints the same list:

1. **The key.** Does `data/update.pub` on that server match the private half for its origin?
   For production, `openssl pkey -in ~/.config/neurosys/update.key -pubout` and compare.
2. **The clock.** The signed serial must be within five minutes of the server's.
3. **A replay.** A serial is the signing time in seconds and each is spent once, so two calls
   signed in the same second collide — wait a second and run it again; every run signs anew. Note
   that a push which *failed* has still spent its serial — the replay guard is armed before the
   archive is touched, so a credential that produced a failure can never be sent again either.

A **409** says another write is in progress. The server holds a lock for the length of a write, and
a second one arriving meanwhile is refused without spending its serial or touching a file — two
writes at once would each mirror over the other. Wait for the first, then run it again.

A server that is not running the `/admin` code at all — a fresh host, one still running the code
from before `/admin`, or one a push has broken — answers with something that is not the admin's,
typically the site's own 404 page, and the command says the server is older than `/admin`. The
admin cannot fix that for itself. **The way back is
`./deploy.sh`, over the mount.** `--url` will not reach anything else: it takes an origin and
appends the action's path. ([history](history/api.md))

A refusal *after* the signature verifies is a **422** with a full sentence saying which member of
the archive was wrong, or why the release it replaces could not be recorded — by then you have
proved you hold the key, so there is nothing left to hide.
An address the admin does not have is a **404** with a sentence too, and a verb the action does not
answer on is a **405** naming the one it does; both are visible only to the key holder.

### Reading the report

```
applied
written 3  unchanged 185  deleted 1  failed 0
```

**`written` is what actually changed**, not what the payload carried. A push sends all 188 files
every time and writes only the ones whose bytes differ, which is `rsync -c`'s rule and is here for a
sharper reason than saving four filesystem operations — see below. So a push after a one-file edit
should say `written 1`, and a push that says `written 188` means something rebuilt the whole tree.

`unchanged` is counted rather than listed: nothing happened to those files, and 185 lines of them
would bury the three that did change. `deleted` and `failed` are always named in full.

**Nothing live changes until every changed file is staged.** A push writes what it will change into
`cgi-bin/.update-stage/` first, and asks the live tree whether each destination can take a file — a
directory where a file goes, or a file where a directory must be, refuses the push with a 422 and
nothing written. Only then does it rename each staged file into place, so a failure while writing
cannot leave half a release live, and the note `staged N files beside the roots, then renamed them
into place` says it happened.

**A non-empty `failed` makes the response a 500** even though everything else applied, which is
deliberate — a partial push is not a successful one. After staging, only a rename can fail: a live
directory PHP may not write into, or a disk that filled in between. **It also mirrors nothing**:
deleting the old tree's leftovers around a file that did not land would leave the server with
neither version.

**A push that rewrites code the applying request still has to load can answer 500 and have
landed.** The request applying a push is the *old* code, and a class it has not loaded yet is read
from disk after the push rewrote it. When the push changes that class's shape, the request dies
mid-reply with nothing wrong on the server. The error log names the call, and the push reports
`refused with 500`. The renames run in pack order with the manifest last, so a new build stamp from
`update v1 version` is the first sign it landed. A follow-up `--dry-run` reading `written 0` is the
proof. ([history](history/api.md#2026-09-13--a-push-that-replaced-the-code-applying-it-0381de4))

**Only a root the push carries is mirrored.** A payload with no file under `phpanta/` leaves the
server's `phpanta/` exactly as it is, rather than reading the absence as "delete all of it". Both
decisions appear in the report as `note:` lines, so a dry run shows them before anything happens.

#### The `.nfsXXXXXXXX` files, if you ever see one

Strato serves off **NFS**. Any atomic write — `File::write()`, and `rsync` too — creates a temp file
and renames it onto the target; when the target is a file some process still has open, the NFS
client renames the *old* inode aside as `.nfsXXXXXXXX` instead of unlinking it, so the open handle
stays valid. `public/index.php` is exactly that file: the request doing the pushing is executing out
of it.

The stray then sits in the tree the mirror walks, and cannot be deleted while a worker holds it.
**The mirror knows the name** — `.nfs` and 24 hex digits is the client's, not the site's — so it
never counts one as surplus or records it for a rollback. It tries each once on the way past and
says what came of it in a note, and the push stays a 200:

```
note: public/.nfs00000000bd2dac2512228f60 is a file the NFS client renamed aside, still held open by a running worker; it goes when the worker lets go, or over the mount, and is never served meanwhile
```

Once the worker has let go, the next push removes it and says so in a note of its own.

This is why a push leaves an unchanged file strictly alone: `UpdateApplier::isCurrent()` skips a
file whose bytes are already there — not rewritten, not touched, not chmodded — so an unchanged
`index.php` is never renamed over. Permissions are deliberately not reconciled, which is the trade
`rsync` without `-p` makes for the same reason.

`deploy.sh` strands the same inode every time it rsyncs `index.php`, so a stray can predate any
push. If you find one, it is harmless (Apache answers 500 for it, having no `SetHandler` for the
extension, so it is not served) and it clears itself when the worker holding it recycles.
([history](history/api.md))

Removing one by hand is tidiness, not a fix. If you want it gone now, do it **over the mount**: the
delete has to come from a different NFS client than the one holding the handle, and the web process
is that client. `deploy.sh` already knows where the mount is — it is gitignored, because the host
and account name are not this repository's to publish:

```bash
source <(grep -E '^(SFTP_USER|SFTP_MOUNT)=' deploy.sh) && rm -v "$SFTP_MOUNT/cgi-bin/neurosys/".nfs*
```

### Rolling back a push

```bash
php tools/api.php update v1 rollback --dry-run   # what it would restore and remove
php tools/api.php update v1 rollback
```

Every push records the release it replaces before it writes anything — the old bytes of each file it
overwrites or the mirror deletes, and the names of the files it adds — in `cgi-bin/.update-previous/`
beside `.update-serial`. The push's report says so in a `note:` line. A rollback puts exactly that
back: `+` lines are files restored byte for byte, `-` lines are files the push had added and the
rollback removed. Files the push left alone are not touched.

- **One step back, never two.** The record holds the last push only. Each push replaces it (a push
  that changes nothing keeps it), and a completed rollback clears it, so a second rollback is a 422
  saying there is nothing to roll back.
- **It refuses a tree that has moved on.** If anything it would touch no longer holds what the push
  left — a `./deploy.sh` since, a hand edit over the mount — it refuses whole with a 422 naming the
  paths, and writes nothing. It never mixes two releases.
- **A push whose record cannot be written is refused** with a 422 and nothing written. If that
  happens, PHP cannot write beside `.update-serial`, or an old record there is unreadable.
- Like the push, it never touches `data/`. It runs the code it is rolling back *from*, and a rollback
  that restores `public/index.php` strands one `.nfs` file the same way a push does (above).

**`./deploy.sh` remains the full recovery path** — for anything more than one step back, for a tree
the rollback refuses, and for a push that broke the endpoint the rollback would be sent to.

### Measuring the host's filesystem

```bash
php tools/api.php update v1 probe --dry-run   # the directory it would use, and nothing written
php tools/api.php update v1 probe
```

A push still writes into the live tree file by file. Whether it could instead stage the tree beside
the live one and swap it in rests on what Strato's NFS allows — a directory renamed while a worker
holds a file inside it, how long two renames leave a name with nothing at it, whether
`sys_get_temp_dir()` is even the same device. `update v1 probe` asks the host by doing each in a
scratch directory beside `.update-serial` and removing it again. It is a write — the lock and a
serial, like a push — and its lines are facts, not verdicts; what each means is in
[the framework's security document](../phpanta/docs/security.md#measuring-the-host).

### What it does not do

It never touches `data/`. That tree is 8.6 MB of demo audio, it is rsynced deliberately *without*
`--delete` because `demos.php` and `demos/` are gitignored, and reproducing that asymmetry inside a
mirroring updater is where a mistake would take unreleased tracks off the server. `data` is not one
of the three roots a payload may name, so a push cannot reach it even if one tried.

**So a push alone cannot ship an entry that names a new case.** A release whose `description:` names
a new `ReleaseDescription` case comes in two halves. The case is in `src/`, which a push carries.
The entry naming it is in `data/releases.php`, which only `./deploy.sh` carries. `./deploy.sh`
ships both, and a push alone leaves the live catalogue without the entry.

**`deploy.sh` remains, and remains the recovery path.** A push that breaks `src/` breaks the endpoint
that would fix it — `update v1 rollback` included; the way back is the mount. Every previous tree is in git, so recovery is
`git checkout <ref> -- src public && ./deploy.sh`.

## Full deploy

`./deploy.sh` is the full deploy and the recovery path — it rsyncs `build/dist/public/`, `src/`,
`autoload.php` and `data/` over the mounted SFTP in one go, so `data/` goes up with everything else.
The script is gitignored (it holds the host and account name), so it exists only on the local
machine.

**It runs `npm run build:prod` first, so you do not have to.** `build/dist/` is the shipped tree:
`public/` bundled and minified, with every source map deleted, and a manifest of its own. Building
it inside the deploy is deliberate — the alternative is a staleness check that is wrong once and
then silently ships whatever `build/dist/` happened to hold. It also means a forgotten
`npm run build` is caught before anything is uploaded rather than after. See
[frontend.md](frontend.md) for the two trees.

That is why there is a third rsync: `build/dist/src/NeuroSYS/AssetManifest.php` goes up **after**
`src/`, over the one file that differs between the two trees. Its stamp is a hash of the minified
bytes rather than the readable ones, which is correct — a stamp is a claim about content. Order is
safe: the assets land before the manifest naming them, and `.htaccess` *strips* the version segment
rather than resolving it, so a document cached with the previous stamp still finds the new files.

**It deliberately excludes `data/admin.php`, `data/site_auth.php`, `data/update.pub`,
`data/session.key` and `data/admin-passkeys.json`**, and `data/logs/`. None has a repo copy: each
deployment holds its own, and syncing whatever this machine has would overwrite it. This site needs
no `admin.php` at all — no route stands behind the framework's Basic admin gate. Upload the keys by hand when they actually change — see
[The admin in a browser](#6-the-admin-in-a-browser) for the session key. The device store is written
on the server by `access v1 enrol` and `revoke`, and a copy from here would put this machine's list
of devices on the live host.

**`--delete` is on for `public/` and `src/` and off for `data/`.** The two trees it deletes from are
wholly generated or wholly committed, so the working tree is authoritative about what should be
there. `data/` is not: `demos.php` and `demos/` are gitignored, so a deploy from a clone that has
never staged a demo would take every demo off the live server. The price is that **a data file
renamed or removed locally stays on the server** until somebody deletes it over the mount — a
deleted demo's MP3s, for instance. See [demos.md](demos.md).

The PHPStorm route still works if the mount isn't up: right-click `public/` → **Deployment → Upload to
Strato (neurosys.gg)**, then upload `data/releases.php` manually via the Remote Host panel. Note what
that skips: it uploads the **debug** tree, maps and all, under a manifest stamped for different
bytes. Nothing breaks — the version segment is stripped, not resolved — but both halves of the prod
build are silently undone. Use it for one file in a hurry, not for a deploy.

**Run `npm run build` first if you touched anything under `assets/ts/`** and you are deploying any
way other than `./deploy.sh`. `public/assets/js/` is compiled output that is committed and built
from as-is, so an unbuilt source edit deploys the previous JS without a word. `composer verify`
catches it — it fails when the committed output has drifted.

`vendor/` and `node_modules/` are dev-only tooling and are not in the list above — nothing Composer or
npm installs ever reaches the server.

## What `.htaccess` does to a response

Beyond the `SetHandler` allow-list and the HTTPS redirect, `public/.htaccess` shapes every static
response — and hides one file.

**`public/.user.ini` is PHP's per-directory php.ini**, read by Strato's `cgi-fcgi` on each request
and cached for `user_ini.cache_ttl` (300 s there), so a change to it takes up to five minutes to be
in force after a push. It sets `register_argc_argv = Off`, which `health v1 settings` checks.
`.htaccess` hands the path to the router, and `phpanta/tools/dev-router.php` does the same under `php -S`,
so it answers exactly like an address that does not exist; a `Require all denied` would be a 403,
which says the file is there. It can only set per-directory and user directives — `opcache.enable`
is not one of them, and that one is Strato's to switch on.

**Last measured on the live host 2026-09-06.** A stamped module comes back `content-encoding: gzip`
with `cache-control: public, max-age=31536000, immutable`, and a bare `/assets/js/main.js` comes back
gzipped with `max-age=3600` — so `mod_deflate` is present and the two cache tiers are genuinely
mutually exclusive rather than merely written to be. Strato adds a `Vary: X-Forwarded-For` of its
own, which `Accept-Encoding` is appended to.

**A shared host can gain or lose a module without telling anybody**, and behind the
`<IfModule>` guards the failure is silent in both directions — the day before that reading, nothing
was compressed and no `Cache-Control` came back, with nothing in this repository changed between
the two. ([history](history/hosting.md))

**Note what that block does and does not reach.** Every `Header set` here sits inside a
`<FilesMatch>` keyed on a file extension, so it applies to what Apache serves and never to a
document, which `index.php` produces. Documents answer for themselves — `ViewResponse` sends
`Cache-Control: no-cache`, an `ETag` over the rendered body and `Vary: X-Requested-With`, so a
returning visitor revalidates and usually gets a 304. The two halves fit together deliberately: a
document embeds the versioned asset URLs, so a cached document naming *last* build's URLs would be
served last build's JS out of the year-long immutable cache below. `no-cache` means there is no
window in which that can happen, rather than a bounded one. Both blocks are `<IfModule>`-guarded,
which means an absent module is silence rather than a 500, and equally means a missing `mod_deflate`
would leave the block doing nothing with no sign. **Re-check after deploying**, since this is not
something either test suite can see:

```bash
curl -sI -H 'Accept-Encoding: gzip, br' https://neurosys.gg/assets/js/main.js | grep -i 'encoding\|cache'
```

That URL is the calendar tier and nothing the site emits asks for it. The one a page actually loads
carries the build stamp, so check that tier too — it is the one the year-long `immutable` is on:

```bash
curl -s https://neurosys.gg/ | grep -oE '/assets/js/v-[^"]+/main\.js' | head -1 \
  | xargs -I{} curl -sI -H 'Accept-Encoding: gzip' "https://neurosys.gg{}" | grep -i 'encoding\|cache'
```

**The version-segment rewrite is the highest-risk line in the file.** Compression failing costs
bytes; that rewrite failing costs every stylesheet and every module, because the manifest names URLs
only it can resolve — an unstyled page with no JS at all. It is verified against real Apache locally
and against the live host on each deploy, and it is the first thing to check if a deploy goes wrong:

```bash
curl -s https://neurosys.gg/ | grep -oE 'href="/assets/css/v-[^"]+"' | head -1
```

Take that path, request it, and expect a 200 with `immutable` in `Cache-Control`. A 404 means the
`RewriteRule` did not fire and the fix is to revert the manifest to unversioned URLs, not to debug
it live.

Cache lifetimes come in two tiers, split by whether the URL names its own content. Built assets are
served under a build-stamp segment and get `immutable` for a year; see [frontend.md](frontend.md)
for cache versioning. Everything else keeps a calendar TTL — an hour for a bare `.css`/`.js`
(nothing the site emits asks for one, so this is only ever a URL somebody typed), thirty days for
images and fonts. The two are made mutually exclusive by document order rather than by an `env=!`
condition, because the rewrite is an internal redirect and the variable then arrives named
`REDIRECT_VERSIONED`; both spellings are set, and only one is ever defined. Verified against real
Apache, not reasoned about.

As read on the live host 2026-09-05, it serves **HTTP/2** (no HTTP/3 — no `Alt-Svc`) from
Apache 2.4.68.
