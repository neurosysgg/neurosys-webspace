# Deployment — Strato

## The layout on the server

The deployment sits in `cgi-bin/` on Strato, and the webroot is a directory inside it. That is what
`Config::above()` resolves to — the repository root locally, `cgi-bin/` on the server — and every
other path hangs off it, so `data/` is a sibling of the webroot rather than inside it:

```
cgi-bin/                 ← Config::above()
├── neurosys/            ← the webroot (DOCUMENT_ROOT) ← build/dist/public/
├── src/                 ← src/, with the prod AssetManifest.php laid over it
├── autoload.php
├── data/                ← NOT web-exposed; releases, profiles, credentials, demos
└── .update-serial       ← the API's replay counter; in no mirrored or rsynced tree
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

### 5. Set stats password

On your local machine, generate a bcrypt hash:

```bash
php -r "echo password_hash('yourpassword', PASSWORD_BCRYPT) . PHP_EOL;"
```

Paste the output into `data/admin.php` as `pass_hash`, then upload that file by hand — `deploy.sh`
excludes it, because the repo copy is a placeholder.

`data/logs/` is only needed if download logging is ever switched on, and it has to be created on the
server by hand then: `deploy.sh` excludes it, and `fopen(…, 'ab')` creates the file but not its
directory.

### 6. Check the HTTPS redirect on the first deploy

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

If either is in doubt, ship `StrictTransportSecurity::ONE_DAY` first — one constructor argument in
`SecurityHeaders::strictTransportSecurity()` — confirm, then put it back to the default year.

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

Afterwards, ask the server what it is actually running — the read half of the same API, signed with
the same key:

```bash
php tools/api.php update v1 version
```

It answers three lines: the last serial accepted, the versioned entry-script URL (which carries the
build stamp, and is the one fact that tells a deploy that wrote from one that found every file
already current), and the PHP version.

### When you need more than three lines

```bash
php tools/api.php health v1 report
```

The second service on the same endpoint, signed with the same key and reached by the same command —
`/api/health/v1/report`. It answers what `update version` deliberately does not: the SAPI and the
ini limits a request runs under, whether each of the four extensions the site is a fatal without is
present **and working**, where a PHP diagnostic goes on this host and the last one that got there,
the server's own software, kernel and clock, and whether every file under `data/` is where the site
expects it.

**It is the one source for what the live runtime is.** The extensions are declared in
`composer.json`, which never runs there because `vendor/` is not deployed, and asked for in
`test/basic_test.sh`, which runs whichever `php` is on `$PATH` locally; the error configuration
docblocks quote (`display_errors` off, `error_log` empty) is a copy of a reading this report
re-takes. When a docblock and the report disagree, the report is right. ([history](history/api.md))

Five lines are worth reading before the rest of it:

- **`clock`.** A credential whose serial sits more than five minutes from the server's clock is
  refused, and that is cause number two in the list a refused call prints. There is a chicken and
  an egg — a clock far enough out refuses the call that would report it — but a clock that is
  *drifting* is caught here well before it costs a deploy.
- **`update.pub`.** It can never read `absent`: a report you are reading verified against it. The
  size beside it is what tells a whole key from a truncated paste.
- **Each extension is asked by being used**, not by `extension_loaded()` — registered and working
  are two questions, the standard `test/basic_test.sh` already states for `ext/dom`.
- **A file's presence and whether the repository tracks it are two columns, not a verdict.**
  `absent  (tracked)` reads as the fault it is without the report inventing a severity word.
- **The `errors` section reads `error_get_last()`**, deliberately process-global. A diagnostic a
  userland handler takes never populates that function, and every suppression here goes through
  `Diagnostics::muted()` — so what the line reports is precisely the diagnostics **nothing in this
  repository handled**.

It reports **no replay serial**; `update version` does, and the two handlers overlap on
`PHP_VERSION` and nothing else. Nothing about the answer is public. `/api/health/v1/report` is as
invisible as `/api/update/v1/patch` — unsigned, it is the same 404 an address that does not exist
gets — which is the only reason a report this detailed is safe to produce at all.

### Probing the live host

When the question is one the report does not answer — whether an extension actually does the thing
about to be relied on, say — put a probe up with a push and take it down with another, **from a
detached worktree at `HEAD`** rather than from the working tree. The endpoint mirrors the whole tree,
so a push from a dirty working tree would ship the change being checked *for* alongside the check.
That is how `ext/dom` was checked by parsing before `MarkupParser` relied on it
([history](history/hosting.md)).

### The flags

`--dry-run` sends a real signed payload and has the server validate every member, report exactly
what it would write and delete, and **write nothing** — it does not even advance the replay serial,
so the same payload can then be sent for real. Use it whenever you are unsure; it costs one request.

`--no-mirror` leaves alone whatever the payload does not mention. The default is to mirror, matching
`deploy.sh`'s `--delete` on these two trees, and mirroring is the only way stale files ever leave the
server.

`--url` points somewhere else and `--key` names a different private key. Both default sensibly:
`https://neurosys.gg` and `~/.config/neurosys/update.key`. `--url` is an **origin**, not a full
endpoint — the path is derived from the action, so there is one place that knows what the address is
and it is the same `SitePath` case the router matches with. It must be `https`; `Url` refuses
anything else, on the one request that carries a signature.

### First-time setup: the keypair

Generate it once. The **private half never enters this repository** — it lives beside the SoundCloud
refresh token, for the same reason.

```bash
mkdir -p ~/.config/neurosys && openssl genpkey -algorithm EC -pkeyopt ec_paramgen_curve:P-256 -out ~/.config/neurosys/update.key
```

```bash
chmod 600 ~/.config/neurosys/update.key && openssl pkey -in ~/.config/neurosys/update.key -pubout -out data/update.pub
```

Then upload `data/update.pub` **by hand**, once, next to `admin.php` on the server. `deploy.sh`
excludes it — there is no repo copy to sync and syncing a local test key over the live one would
lock you out of the endpoint.

**Its absence is the off switch.** No key on the server, no endpoint: every address under `/api`
answers exactly like an address that does not exist, for everyone, forever. That is the opposite
polarity to `data/site_auth.php`, whose absence stands its gate *down* — worth reading twice,
because the two files look alike.

`data/update.pub` and `cgi-bin/.update-serial` cover every service, not only the push; they are
named for the service that first needed them. Renaming either would mean a file uploaded by hand on
the server and a replay counter starting again from zero, so they keep their names.

### When a push is refused

A refusal before the signature verifies is a **404** (or a 405 for a write method), because that is
what the endpoint answers to anyone it will not verify. It says nothing about why, deliberately, so
check these in order:

1. **The key.** Does `data/update.pub` on the server match your private half?
   `openssl pkey -in ~/.config/neurosys/update.key -pubout` and compare.
2. **The clock.** The signed serial must be within five minutes of the server's.
3. **A replay.** The same payload cannot be applied twice; rebuild it (any rebuild mints a new
   serial). Note that a push which *failed* has still spent its serial — the replay guard is armed
   before the archive is touched, so bytes that produced a failure can never be sent again either.

A server that is not running the `/api` code at all — a fresh host, or one a push has broken —
refuses exactly the same way, and the endpoint cannot fix that for itself. **The way back is
`./deploy.sh`, over the mount.** `--url` will not reach anything else: it takes an origin and
appends the action's path. ([history](history/api.md))

A refusal *after* the signature verifies is a **422** with a full sentence saying which member of
the archive was wrong — by then you have proved you hold the key, so there is nothing left to hide.
An address the API does not have is a **404** with a sentence too, and a verb the action does not
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

**A non-empty `failed` makes the response a 500** even though everything else applied, which is
deliberate — a partial push is not a successful one.

#### The `.nfsXXXXXXXX` files, if you ever see one

Strato serves off **NFS**. Any atomic write — `File::write()`, and `rsync` too — creates a temp file
and renames it onto the target; when the target is a file some process still has open, the NFS
client renames the *old* inode aside as `.nfsXXXXXXXX` instead of unlinking it, so the open handle
stays valid. `public/index.php` is exactly that file: the request doing the pushing is executing out
of it.

The stray then reads as a surplus path to the mirror in the same request and cannot be deleted,
because the handle keeping it alive belongs to the process trying to delete it. It shows up as:

```
! public/.nfs00000000bd2dac2512228f60 — could not be removed
```

This is why a push leaves an unchanged file strictly alone: `UpdateApplier::isCurrent()` skips a
file whose bytes are already there — not rewritten, not touched, not chmodded — so an unchanged
`index.php` is never renamed over. Permissions are deliberately not reconciled, which is the trade
`rsync` without `-p` makes for the same reason.

`deploy.sh` strands the same inode every time it rsyncs `index.php`, so a stray can predate any
push. If you find one, it is harmless (Apache answers 500 for it, having no `SetHandler` for the
extension, so it is not served) and it clears itself when the worker holding it recycles.
([history](history/api.md))

To remove one now, do it **over the mount** rather than through the endpoint. The delete has to come
from a different NFS client than the one holding the handle, and the web process is that client, so
a push will never manage it. `deploy.sh` already knows where the mount is — it is gitignored,
because the host and account name are not this repository's to publish:

```bash
source <(grep -E '^(SFTP_USER|SFTP_MOUNT)=' deploy.sh) && rm -v "$SFTP_MOUNT/cgi-bin/neurosys/".nfs*
```

### What it does not do

It never touches `data/`. That tree is 8.6 MB of demo audio, it is rsynced deliberately *without*
`--delete` because `demos.php` and `demos/` are gitignored, and reproducing that asymmetry inside a
mirroring updater is where a mistake would take unreleased tracks off the server. `data` is not one
of the three roots a payload may name, so a push cannot reach it even if one tried.

**`deploy.sh` remains, and remains the recovery path.** A push that breaks `src/` breaks the endpoint
that would fix it; the way back is the mount. Every previous tree is in git, so recovery is
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

**It deliberately excludes `data/admin.php`, `data/site_auth.php` and `data/update.pub`**, and
`data/logs/`. The copies of the first two in the repo are placeholders — `admin.php` ships an empty
`pass_hash` — so syncing them would overwrite the live hashes and lock `/admin/stats` out; the third
has no repo copy at all. Upload those by hand when they actually change.

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
response.

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
