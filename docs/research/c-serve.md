# Research — c-serve, the site's core in C

> **Status: c0–c5 built (2026-11-07)**: the conformance suite, the extracted stack, its host, the
> toolkit an app is written in, checked case by case against Phpanta's own answers, and this site as
> a native app (`app/`) that runs with no PHP behind it (`test/cserve.sh`: 113/113) and answers as
> the PHP does (`test/cserve/diff.sh`: 370 cases, byte for byte). Recorded 2026-10-29. This takes neurosys.gg's
> runtime off PHP. A C web host serves the site, and the site is a first-class app compiled for it.
> The host fuses Phpanta's `App` contract with ctui-wm's plugin architecture, on the HTTP/TLS
> server that ctui-wm already has. It reverses "not a rewrite away from PHP" in
> [attested-single-box.md](attested-single-box.md#what-this-is-not), and it subsumes that
> document's Phase 2 (the C front) and Phase 4 (QuickJS for the dynamic layer).

## Decisions

- **The dedicated box is a yes.** Strato shared hosting runs nothing but PHP, so it can't host
  this. Development stays local in the meantime.
- **The runtime leaves PHP.** The site never depended on PHP specifically. Every line of
  interpreter, extension and framework the site drops is a line that needs no audit.
- **The admin services are scrapped**: `update`, `health`, `capability`, `access`/passkeys,
  `machine` and `drop`. With them go the signed `NS1` calls, the passkey ceremony, the sealed
  session, the serial and the tar.gz push. Deploys become the box's business: a signed build onto
  the verity partition (attested-single-box Phase 3).
- **The stack is its own repo, `c-serve`**, developed at `~/Projects/c-serve`. It is extracted from
  ctui-wm the way c-µdsp came out of ctui-mus. ctui-wm and this site (`c-serve/`) both take it as a
  submodule. What
  ships to the box is c-serve plus this site's app, and nothing from the desktop.
- **Apps link statically for production and load dynamically for development.** Both modes
  read the same vtable, so neither is a second design (see [Apps](#apps)).

## Why it is smaller than it looks

The port surface was measured on 2026-10-28. It comes to about 47k lines of PHP, roughly half of
them docblocks. The decisions above remove most of it.

| Area | PHP lines | Fate |
|---|---|---|
| The admin: Api, Update, Health, Passkey, Drop, Machine models and services | ~15k | dropped |
| `Http/`: request, answer, headers, negotiation, Range, multipart | ~10k | ported, the part a read-only site needs |
| The markup tree, the vocabulary, `Layout`, 7 views | ~6k | ported |
| The site: models, controllers, repositories, `DemoGate` | ~6.6k | ported |
| `Text/`: ~70 site pairs, plus the framework's few that survive | ~1.5k | ported, **without ICU**: the one plural belonged to a form |
| `Data/` (PDO SQLite) | 1.3k | dropped: the site has never used a database |
| `App`, `Router`, layers | ~1.5k | becomes c-serve's app contract |

Some things fall away entirely:

- **No ICU.** Every pattern the site uses is a `{name}` substitution.
- **No ext/dom.** Only the privacy policy is hand-authored HTML. It becomes a build-time
  conversion against the vocabulary rather than a runtime parse.
- **No zlib inflate.** That was only for the push.
- **No AEAD, no ECDSA, no PBKDF2.** Those were only for the admin, the session and drops.

The crypto the site keeps is SHA-256 for ETags and a password check for the demo gates. mbedtls
has no bcrypt, so the demo passwords are re-minted under PBKDF2-HMAC-SHA256 (decided 2026-10-29,
see c4), which mbedtls has and Phpanta's `PasswordHash` now reads too.

## What exists in ctui-wm

Measured on 2026-10-28 in `~/Projects/ctui-wm`:

- **`plugins/net/`**, about 3k lines: HTTP/1.1 server and client, mbedtls 4 with mutual TLS, and
  a fuzz case for every parser (`tools/fuzz/http.c`).
  - The server has keep-alive, HEAD and streamed chunked replies.
  - The request head is capped at 16 KiB and 64 headers, and targets at 4 KiB.
  - Bodies are Content-Length only, buffered whole.
- **`apps/remote/`** (ctui-remote), about 1.2k lines:
  - the `.htaccess` logic as C: static files, ETag/304, the immutable `v-XXXXXXXX` tier, and
    refusal of `..`;
  - a php-cgi bridge.
  - Its pages are a Phpanta app (`remote/`), which makes it the second app c-serve has to host.
- **The plugin ABI** (`src/base/plugin.h`): one exported vtable with an ABI number, a size and a
  name. Slots are only ever appended, so a slot past a plugin's size reads NULL. Dependencies go
  through `uses`/`bind`, and the loader is dlopen with cycle detection.

What is missing, so c-serve adds it:

- **The server is not pluggable.** It has one handler callback, no router and no app slot. The
  server half is linked only into ctui-remote (`NET_SERVE_SRC`, Makefile:435).
- **Request parsing:** no query, cookie or form parsing.
- **Reply limits:** reply headers are a fixed 4 KiB buffer, which is tight under the CSP. There is
  no gzip encoder and no access log.
- **It is built for one phone, not the public internet:**
  - one detached thread per connection, capped at 64, which a slow client can exhaust;
  - one process-wide mbedtls lock;
  - no certificate reload.
- **JSON:** ctui's parser is the lenient one for config files. Outside input needs a strict one.

## Apps

**An app is a vtable, `CSERVE_APP`, in ctui-wm's style:** an ABI number, a size and a name,
appended slots, and NULL meaning "not implemented". Its slots answer what Phpanta's `App`
answered, minus the admin.

| Phpanta | c-serve |
|---|---|
| `name()` | `name` |
| `routes()` | a route table: pattern, method policy, handler |
| `notFound()` | `not_found` |
| `languages()` | the offered languages, default first |
| `shell()` | `shell`: the document around a page |
| `vocabulary()` | the tags and attributes hand-authored HTML may use (checked at build time) |
| `contentHosts()`, `permissionsPolicy()`, HSTS, COOP, CORP | the security header policy, as data |
| `origin()` | the canonical origin |
| `buildId()` | the asset stamp |
| `ownDataFiles()` | the data the app reads (see open questions) |

**Linking.** Each app exports its vtable under a per-app symbol, `cserve_app_<name>`.

- In a development build, the host dlopens `apps/<name>.so`. Rebuilding one app and restarting
  needs no relink of the host.
- In a production build, a generated table lists `&cserve_app_<name>` for every app compiled in.
  The host is one static binary with no dlopen, so what the box measures is everything that runs.

**The host** (`c-serve`) is its own process. It takes one listener per address, routes by `Host`
to an app, and owns everything an app must not: TLS, the parse, the limits, the security headers,
compression, the access log, the static tree and the build-stamp rewrite. An app sees a parsed,
bounded request and returns a reply value. **Nothing ends a request but the host**, which is
Phpanta's "every decision returns", kept.

## What carries over from Phpanta's rules

The rules port; the code doesn't.

- **No HTML from a string.** A view builds a node tree. Escaping and the URL-scheme check live in
  render, and the WHATWG on-origin check comes with it. (`/\r\n/host` is still a trap.)
- **Names and values are typed.** Tags, attributes, headers and CSS classes are C enums, emitted
  under the same names. `enum-parity.test.mjs` keeps pinning the client's mirrors, now against the
  C.
- **Every visible word is a catalog entry with both languages.** A missing German fails the build,
  not a test that runs later.
- **Every file is a credential until proven otherwise; fail closed.**
- **A gate's refusal is a value it returns.** `DemoGate` keeps its realm encoding and the
  `qdtext` check.

## Phases

Each phase stands alone, and the site keeps serving from PHP until the last one.

- **c0. Conformance suite.**
  - **Split:** the HTTP half of `test/basic_test.sh` (about 130 curl checks: routes, rendered
    output, security headers, caching, language negotiation, the read-only method gate) splits
    into a script that takes `BASE`.
  - **Admin checks:** the admin's checks leave with the admin, but one is kept: `/admin` answers
    like `/no-such-page`.
  - **The PHP-internal half stays** with the PHP until the PHP goes.
- **c1. Extract c-serve.**
  - Move `plugins/net`'s server, TLS, connection and listener code, its tests and its fuzz cases
    into `~/Projects/c-serve`. ctui-wm takes it as a submodule, and ctui-remote builds unchanged.
  - Ship a minimal ctui dependency, or none. Decide by what `conn`/`tls` actually include.
- **c2. Host + app ABI + router.** *Built.*
  - `c-serve` serves the site's `public/` and routes everything else to php-cgi, the bridge
    ctui-remote already has. The conformance suite passes against it.
  - This is attested-single-box's Phase 2, done as c-serve.
  - As built: ctui-remote's routing and CGI became c-serve's `static.c` and `cgi.c`, and
    ctui-remote uses them. The host is `cserve` (apps `dlopen`ed) or `cserve-static` (apps linked
    in); the ABI is `src/app.h` (ABI 1: `init`, routes, `fini`); the router is `src/route.c`
    (`{name}`, a trailing `*`, a 405 with `Allow`). `--climb front` sends a `..` path to PHP for its
    404, which fixes Apache's `/.htaccess` 403 on the way. A HEAD whose body is streamed goes out
    without framing and closes its connection, because `curl -X HEAD` reads it as a GET's.
- **c3. The framework layer**, an app's toolkit. *Built.*
  - request: query, cookies, `Accept-Language`, `If-None-Match`, `X-Requested-With`;
  - reply with typed headers;
  - the markup tree and render;
  - catalogs and negotiation;
  - ETag and `Vary`;
  - the SPA fragment.
  - As built: c-serve's `kit/`.
    - **The modules:** `arena` (one request's memory and its first error), `reading`, `text`,
      `markup` + `html`, `headers` and `page`.
    - **Security headers:** the host owns them, in `src/policy.c`. ABI 1 gained two slots,
      `security` (the CSP's hosts, as data) and `not_found`.
    - **Render is Phpanta's byte for byte**, and the readers decide as Phpanta's do. Every case's
      expected answer is printed by Phpanta itself (`test/cserve/goldens.php` → c-serve's
      `tests/data/goldens.h`).
    - **Catalogs are a C macro taking each language**, so a missing German is a compiler error.
    - **A phrase's grammar is `{name}` alone.** Anything ICU would treat specially is refused,
      including an apostrophe.
    - **The ETag is the first 16 bytes of a SHA-256** (mbedtls's PSA), not xxh128. It is the
      same shape, and nothing compares it across the two.
    - `cserve_stays_on_origin()` refuses any value with a scheme. Phpanta's WHATWG resolution
      accepts `https:x`. Render never asks either one that question, because a `/` or `#` comes
      first.
    - `containingHtml` is not ported: `/privacy` becomes a build-time conversion in c4.
    - `apps/hello` exercises all of it end to end in both link modes.
- **c4. The neurosys app.** *Done.*
  - Every route is native: `/`, `/releases`, a release, a download, `/demos/{slug}` and its audio,
    `/imprint`, `/privacy` and the language switch, plus the 405 and `OPTIONS` answers on them. The
    php-cgi front still answers what no route claims (PHP's 404 and its write-method 405) and
    `/admin`; c5 deleted it.
  - **The app is `app/` in this repo**, built against c-serve from outside its tree
    (`make EXTERNAL_APPS=neurosys=<repo>/app`). c-serve's lint covers it, through `app/.clang-format`.
  - **The data is compiled in.** `php tools/app-data.php` prints `app/gen/` from the PHP:
    - the catalogs as `T(id, en, de)`;
    - the site's elements, attributes and classes;
    - the manifest;
    - the releases and profiles, with every derived value worked out;
    - the privacy policy as C that builds its tree.

    So there is no runtime parser and no HTML parse. `npm run build` runs it last, and the verify
    script fails `app/gen/` drifting from the PHP.
  - **The demos are a runtime file**, `data/demos.json`, which the same command prints from
    `data/demos.php` (gitignored the same way). The app reads it at `init()` with `kit/json`, a
    strict version of ctui's parser.
  - **Demo passwords are PBKDF2-HMAC-SHA256** at 600 000 iterations, `$pbkdf2-sha256$…`:
    - `PasswordHash::mint()` writes it, and Phpanta reads it beside bcrypt;
    - c-serve's `kit/password` reads it with mbedtls;
    - a bcrypt demo is served by PHP and refused by the C, logged at start-up, until
      `stage-demo --rotate` re-mints it.
    - mbedtls's PBKDF2 takes about 530 ms per check where PHP's takes 95 ms, so a gate is slower
      natively. It is also the brake on guessing, since nothing throttles the gate.
  - **c-serve grew** what the app needed:
    - `kit/json`, `kit/password` and `kit/file` (a file in ranges);
    - Basic credentials and a qdtext-checked challenge;
    - a sized stream;
    - ABI 1's `refuse` slot, the app's own 405 and `OPTIONS`.

    Every reader has goldens from Phpanta and a fuzz mode.
  - **The gate is `test/cserve/diff.sh`.** It runs two hosts on one staged tree, PHP alone and the
    app in front, and compares 259 requests: status, headers in order, body. It normalises only
    the host's own headers, the framing, the ETag's value, and php-cgi's default `text/html` on a
    redirect. `SAN=1` runs both under ASan+UBSan.
  - **Known gap:** the switch reads a Referer's path without WHATWG's normalising (dot segments,
    percent-encoding). A browser sends one normalised already.

- **c5. PHP leaves the host.** *Done.*
  - **The fallthrough is gone.** The app has a `not_found` slot, `UnroutedController`'s answer: a
    read is the 404 page, any other method (OPTIONS too) the read-only 405 with `Allow: GET, HEAD`.
    The host runs it with no `--cgi`; `cgi.c` stays in c-serve, where ctui-remote uses it.
  - **The admin, `/drop` and `/api` are natively absent**: each answers as `/nope` does, which
    `diff.sh` asserts of the app alone. PHP keeps them, since Strato keeps running PHP until the box
    exists; the footer's admin link is PHP's and the app's alike, byte for byte.
  - **Strato stays PHP** (the user's call, 2026-11-07), so `phpanta/` keeps being deployed there
    until the move to the box, and `diff.sh`'s PHP side is the reference for both.
  - `test/cserve.sh` runs the conformance suite against the app alone; its PHP-only runs went with
    the CGI.
- **c6. Public-internet hardening**, needed before the box takes traffic:
  - replace thread-per-connection with an event loop, or bound it tightly;
  - a gzip encoder, the access log and cert reload;
  - mbedtls built with threading, or one TLS context per worker;
  - the static production build, and `test/cserve.sh` against the production layout with it.

## Open questions

- **Data after c5.** Until PHP leaves, `data/*.php` is the source and `app/gen/` a print of it.
  Then the release and demo tooling has to write the new formats itself.
- **Concurrency model for c6:** an event loop, or a fixed worker pool with per-connection deadlines.
- **The tooling.** About 18k lines of PHP import runtime classes (`Site`, `MusicalKey`, the API
  enums) and write PHP into `data/`. Once the admin is gone, they need only the data format and
  the enums. Do they stay PHP against a thin model, or move?
- **ctui-remote** has its own pages in Phpanta. Do they become a c-serve app too, or stay behind
  the CGI bridge?
- **Phpanta's future** as a published PHP framework, once nothing here runs it.
