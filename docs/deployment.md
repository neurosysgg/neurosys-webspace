# Deployment — Strato + PHPStorm

## Strato folder layout

Strato gives you an FTP root with (at least) one web-exposed folder. The mapping is:

```
/                    ← FTP root
├── htdocs/          ← web-exposed → upload public/* here
└── data/            ← NOT web-exposed → upload data/* here
    └── logs/        ← must be writable by PHP
```

The `public/` and `data/` directories are siblings on the server, which matches how `ReleaseRepository`, `Auth`, and `DownloadLogger` resolve `dirname(__DIR__, 3) . '/data/...'` from inside `src/NeuroSYS/Service/`.

If your account only has one folder and there's no "outside webroot" option, `data/.htaccess` (`Require all denied`) is there as a fallback — just ensure it actually uploads (some FTP clients hide dotfiles).

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

### 3. Configure FTP deployment

**Settings → Build, Execution, Deployment → Deployment → +**

1. Type: **FTP** or **SFTP** (prefer SFTP if Strato offers it — check your hosting panel).
2. Name: `Strato (neurosys.gg)`
3. Fill in host, port, username, password (save to system keychain, not the project).
4. **Mappings tab**:
   - Local path: `public`
   - Deployment path: `/htdocs` (or whatever Strato calls the webroot)
   - Web path: `/`

### 4. Upload `data/` manually (one-time)

`data/` is intentionally outside the deployment mapping. Do this once:

1. Open the **Remote Host** tool window in PHPStorm (or any FTP client).
2. Create a `data/` folder next to `htdocs/` on the server.
3. Upload `data/.htaccess`, `data/admin.php`, and `data/releases.php` into it.
4. Create `data/logs/` inside it, ensure it's writable (`chmod 755` if needed).

### 5. Set stats password

On your local machine, generate a bcrypt hash:

```bash
php -r "echo password_hash('yourpassword', PASSWORD_BCRYPT) . PHP_EOL;"
```

Paste the output into `data/admin.php` as `pass_hash`, then upload that file.

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

**Its reason for existing is that none of that had ever been asked of the live host.** The
extensions are declared in `composer.json`, which never runs there because `vendor/` is not
deployed, and asked for in `test/basic_test.sh`, which runs `php` from `$PATH` on whichever machine
runs the suite. The error configuration this repository quotes as measured fact — `display_errors`
off, `error_log` empty — was measured by hand, once, and copied into five docblocks. This is the
first thing that asks the runtime actually answering requests.

Two lines are worth reading before the rest of it:

- **`clock`.** A credential whose serial sits more than five minutes from the server's clock is
  refused, and that is cause number two in the list a refused call prints. There is a chicken and
  an egg — a clock far enough out refuses the call that would report it — but a clock that is
  *drifting* is caught here well before it costs a deploy.
- **`update.pub`.** It can never read `absent`: a report you are reading verified against it. The
  size beside it is what tells a whole key from a truncated paste.

Nothing about the answer is public. `/api/health/v1/report` is as invisible as
`/api/update/v1/patch` — unsigned, it is the same 404 an address that does not exist gets — which
is the only reason a report this detailed is safe to produce at all.

`--dry-run` sends a real signed payload and has the server validate every member, report exactly
what it would write and delete, and **write nothing** — it does not even advance the replay serial,
so the same payload can then be sent for real. Use it whenever you are unsure; it costs one request.

`--no-mirror` leaves alone whatever the payload does not mention. The default is to mirror, matching
`deploy.sh`'s `--delete` on these two trees, and mirroring is the only way stale files ever leave the
server.

`--url` points somewhere else and `--key` names a different private key. Both default sensibly:
`https://neurosys.gg` and `~/.config/neurosys/update.key`. Note that `--url` is an **origin** now
rather than a full endpoint — the path is derived from the action, so there is one place that knows
what the address is and it is the same `SitePath` case the router matches with. It must be `https`;
`Url` refuses anything else, on the one request that carries a signature.

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

Note the file keeps its name. `data/update.pub` and `cgi-bin/.update-serial` now cover every service
rather than only the push; renaming either would mean a file uploaded by hand on the server and a
replay counter starting again from zero, which is a migration to buy a tidier name. They are named
for the service that first needed them.

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
4. **An old server.** One that has not yet had this code pushed to it is still answering on
   `/update` and has never heard of `/api`, so it refuses with a `405` — the same refusal a bad
   signature gets, which is why this is on the list.

   **The way out is `./deploy.sh`, and `--url` will not do it.** That flag now takes an *origin* and
   the path is appended, so `--url https://neurosys.gg/update` asks for
   `https://neurosys.gg/update/api/update/v1/patch`. This is the bootstrap the endpoint cannot do
   for itself, and it is the case `deploy.sh` is kept for: **the first deploy of the commit that
   creates `/api` has to go over the mount.** Every push after it is one request again.

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

This is why a push leaves an unchanged file strictly alone. **It was never the endpoint's bug** —
`deploy.sh` strands the same inode every time it rsyncs `index.php`; the endpoint is just the first
thing here that mirrors, and so the first thing that ever looked. If you find one, it is harmless
(Apache answers 500 for it, having no `SetHandler` for the extension, so it is not served) and it
clears itself when the worker holding it recycles.

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



`./deploy.sh` is the current path — it rsyncs `build/dist/public/`, `src/`, `autoload.php` and
`data/` over the mounted SFTP in one go, so `data/releases.php` no longer needs a separate manual
upload. The script is gitignored (it holds the host and account name), so it exists only on the local
machine.

**It runs `npm run build:prod` first, so you do not have to.** `build/dist/` is the shipped tree:
`public/` minified, with all 42 source maps deleted, and a manifest of its own. Building it inside
the deploy is deliberate — the alternative is a staleness check that is wrong once and then silently
ships whatever `build/dist/` happened to hold. It also means a forgotten `npm run build` is caught
before anything is uploaded rather than after. See CLAUDE.md's *Debug and prod builds*.

That is why there is a third rsync: `build/dist/src/NeuroSYS/AssetManifest.php` goes up **after**
`src/`, over the one file that differs between the two trees. Its stamp is a hash of the minified
bytes rather than the readable ones, which is correct — a stamp is a claim about content. Order is
safe: the assets land before the manifest naming them, and `.htaccess` *strips* the version segment
rather than resolving it, so a document cached with the previous stamp still finds the new files.

**It deliberately excludes `data/admin.php` and `data/site_auth.php`.** The copies in the repo are
placeholders — `admin.php` ships an empty `pass_hash` — so syncing them would overwrite the live hashes
and lock `/admin/stats` out. Upload those two by hand when they actually change.

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
