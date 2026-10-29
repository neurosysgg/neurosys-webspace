# Research — the attested single box

> **Status: design, not decision.** This is a defensive threat model and an architecture for
> hardening our own site, neurosys.gg — moving it off Strato shared hosting onto a Strato
> *bare-metal* dedicated box (Arch) that we own from the firmware up. Everything here is about
> confining and attesting *our* stack; there is no exploit code, no attack tooling, and no target
> but our own infrastructure. Nothing is built yet. It exists to answer one question honestly before
> any hardware is rented: *how much of "bulletproof" is a proof, and how much is a hope?*

## Plan of record

The sequence below is the plan as it stands on 2026-10-25, ordered so each phase stands on its own
and we can stop at any one. Earlier phases need only the repo; later phases need the box. The detail
behind each lives in the sections further down.

- **Phase 0 — Make phpanta's floor explicit, total, and enforced.** *(repo only, no box, highest
  value per effort.)* **Done 2026-10-25** — [phpanta/docs/floor.md](../../phpanta/docs/floor.md).
  `Support\Random::bytes()` and `Support\Digest` (`of`, `raw`, `keyed`, `equals`) now own every
  `random_bytes()`, `hash()`, `hash_hmac()` and `hash_equals()` call — 21 files folded, not 8. The
  enforcement is a rule test rather than a phpcs sniff, matching `GuidelineTest`: `FloorTest` pins,
  in both directions, which class may call which of nine families of built-ins (filesystem, stream,
  process, network, randomness, digest, cryptography, output, runtime), and the site's own
  `FloorTest` holds `src/NeuroSYS/` to none. **The honest size was larger than the measurement
  below:** besides the floor classes, the owners include `FilesystemProbe`, `UpdateApplier`,
  `ReleaseRecord`, `App`, the drop store's handle reads, and the whole `machine` service. Each is
  pinned with its reason; moving them onto the floor is follow-up work, and the network family has
  no owner at all.
- **Phase 1 — Grade 1 containment, locally.** *(local machine, no box.)* A php-fpm pool with its own
  uid, the systemd sandbox unit, a derived seccomp filter, and ephemeral workers
  (`pm.max_requests=1`). Ship it with a test suite that *asserts* the confinement: a forbidden
  syscall kills the worker, worker PIDs never repeat across requests, a write outside `data/` fails,
  an outbound connection is refused.
- **Phase 2 — Own the server front.** *(Superseded by [c-serve](c-serve.md) c2, 2026-10-29.)* *(needs the box, or a staging host.)* Bring up `ctui-wm`'s
  already-built, fuzzed C HTTP/TLS server (`ctui-remote`'s stack) as the front for neurosys.gg in
  place of Apache + php-fpm, via the existing CGI bridge, so the phpanta app is unchanged. Mind the
  gaps `server_remote_control.md` lists (compression encoder, Range, access logs) and the
  `.htaccess` rules the router must mirror.
- **Phase 3 — Grade 2 attestation.** *(needs the box.)* Measured boot + TPM-sealed secrets
  (`session.key`, the update CA sealed to the PCRs), a dm-verity signed code partition, and the
  signed-deploy chain hung off the existing ApiGate push. Prove unseal-fails-on-tamper on a staging
  vhost first.
- **Phase 4 — Optional: own the glue, contain the interpreter.** *(Superseded by [c-serve](c-serve.md): the glue becomes C, 2026-10-29.)* *(architecture call, not a security
  one.)* Run the dynamic layer in our QuickJS behind a capability-scoped host API that implements
  exactly the Phase 0 floor, one ephemeral JS context per request. De-risked experiment: point the
  CGI bridge at a QuickJS host for a single non-critical route behind the same mTLS, and measure. It
  bets nothing — the rest of the site stays PHP.
- **Running in parallel — Grade 3 slice.** Property-test the security primitives (ApiGate
  HMAC, SessionSeal, passkey verify, the update CA) hard: constant-time assertions, seal/unseal
  round-trips, verifier fuzzing of malformed input. Independent of hosting.

Legitimate stopping points: **Phase 0 alone** already hardens the live PHP site; **Phases 0–1 on
Strato** is the no-box answer; the box is only worth renting for the Grade 2 properties in Phase 3.

## The question, and the honest answer

The brief was "how provably bulletproof can we make a contained, modern, high-performance, dynamic
website." The word that does the work is **provably**, and the first job of this document is to
refuse to let it mean more than it can.

"Provable" splits into three grades, and they are not the same kind of claim:

- **Grade 1 — provable containment.** *When* the dynamic layer is exploited, the blast radius is
  bounded to something we wrote down and can test. This is real, achievable today, and the whole
  point.
- **Grade 2 — attested integrity.** The stack cannot lie about what it is: the chain from power-on
  to served byte is either immutable or cryptographically measured, and the secrets only exist when
  the measurement matches. Achievable on hardware we own; *impossible* on Strato shared hosting,
  which is the reason this document exists at all.
- **Grade 3 — provable correctness.** The code has no exploitable bug. For a PHP engine and a real
  application this is **not achievable** and we will not pretend otherwise. There is one narrow,
  genuine slice of it (the security primitives), covered at the end.

The thing worth building is **Grade 1 + Grade 2**: a site where the PHP is assumed buggy, the bugs
are proven unable to get out, and the stack is proven to be the one we signed. That is a real
systems result. "Bulletproof PHP" is not.

## Threat model

We confine the untrusted thing; we must first name it.

**The untrusted unit is a single request worker.** Everything that parses attacker bytes — the
php-fpm process handling one HTTP request — is assumed to be, at any moment, fully controlled by the
attacker. Not "might have a bug"; *owned*. This is the same posture [µOS](../../../../Projects/µOS)
takes toward a driver: you do not trust the component, you bound it. The design is judged by what a
fully-owned worker can still reach.

**In scope (what we defend against):**

- A pre-auth remote code execution in the PHP engine or a bundled extension — the worst realistic
  bug class. The 2026-10 PHP engine advisories (fixed in 8.5.12 / 8.4.27 / 8.3.36 / 8.2.35) are the
  reference *shape* of what we defend against: *a fatal mid-request leaves dangling engine state, a
  later request on the same reused worker consumes it.* We neutralise that class structurally — by
  removing the reused worker — rather than waiting for the next patch.
- A compromised worker attempting lateral movement: reading other sites' data, reading the update
  CA or session key, writing to the code partition, opening outbound connections, escalating to
  root, or persisting across a reboot.
- An attacker with **physical or host-level access to the disk at rest** — the Strato datacentre,
  a stolen drive, a support tech. On a rented box this is a real principal, not paranoia.
- Tampering with the deployed code or the boot chain between deploys.

**Out of scope (named so the claims stay honest):**

- A bug in the Linux kernel's syscall-filter or namespace enforcement itself. seccomp-bpf and
  namespaces are our *trusted computing base*; if they are broken, Grade 1 is broken. This is a
  deliberate, enumerable trust assumption — the TCB is "UEFI + our signed kernel + the enforcement
  primitives," and nothing above it.
- A hardware backdoor in the CPU/firmware below our Secure Boot root, or a TPM that lies. We trust
  the silicon's root of trust; we cannot verify it.
- A supply-chain compromise of PHP/Arch upstream that we then sign and deploy ourselves. Measured
  boot proves *we ran what we signed*, not *what we signed was good*.
- Denial of service. Bounded, not eliminated. Out of scope for "bulletproof" in the integrity
  sense.

## Grade 1 — provable containment

Three mechanisms, in descending order of how hard the proof is.

### The worker is ephemeral — the cross-request bug class dies by construction

`pm.max_requests = 1` (or a per-request-recycled pool) means **no two requests ever share a heap or
any engine global.** The entire PHP2Shell family — and every future variant of "stale state after a
mid-request bailout" — depends on a *second* request landing on the *same* worker that was left
dirty. Recycle the worker after every request and that second request does not exist. The dangling
state is freed with the process before anything can consume it.

This is the strongest result in the whole design, because it is not a filter or a patch — it is an
*absence*. There is no reused worker to attack. The cost is latency: a cold engine per request.
Mitigations — a warmed OPcache on a read-only shared mapping, preloading, keeping the pool resident
but the *heap* per-request — are a performance problem to be measured, which is the right kind of
problem to have. **The security claim is a design invariant, not a hope: "requests do not share
mutable engine state," testable by asserting worker PIDs never repeat across requests.**

### seccomp-bpf — the one layer where "provable" is literally true

A seccomp filter is enforced by the kernel, deterministically, regardless of what the engine's heap
is doing. A denied syscall is a `SIGSYS` kill, full stop. This is the only place in the stack where
the proof is mechanical: **we can enumerate the exact allowed syscall set, and write a test that
asserts a forbidden syscall (say `execve`, `socket` to an unexpected family, `ptrace`) kills the
worker.** A fully-owned worker that needs `execve` to drop a shell simply cannot make the call.

The filter is derived, not guessed: the extension audit (see the site-security notes) already
enumerated what the runtime calls — `openssl_*`, `hash_*`, file I/O, `proc_open` only in the machine
service, which is not deployed. The allow-list is that set's syscall footprint and nothing more.
`proc_open` in production: **denied outright**, because the deployed site never legitimately spawns a
process.

### Systemd sandbox + namespaces — the enumerable blast radius

One php-fpm pool per site, its own uid, wrapped in a systemd unit whose confinement is a file we
read, diff and test:

```ini
# Illustrative — the real unit is verified against a test, not trusted because it looks right.
ProtectSystem=strict                 # whole FS read-only…
ReadWritePaths=/srv/neurosys/data    # …except exactly data/
ReadOnlyPaths=/srv/neurosys          # code served from a RO tree (see Grade 2: dm-verity)
PrivateTmp=true
PrivateDevices=true
ProtectKernelModules=true
ProtectKernelTunables=true
ProtectControlGroups=true
ProtectHome=true
NoNewPrivileges=true
RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX
RestrictNamespaces=true
SystemCallFilter=@system-service
SystemCallFilter=~@privileged ~@mount ~@debug ~@reboot
SystemCallArchitectures=native
IPAddressDeny=any                    # a worker needs no outbound network at all
IPAddressAllow=localhost             # …only the FPM socket / local DB
CapabilityBoundingSet=               # empty: no capabilities
```

The claim this buys: **a fully-owned worker can touch exactly the paths, syscalls, capabilities and
network on these lists, because the kernel refuses everything else — and the lists are short enough
to audit by eye and short enough to test exhaustively.** `IPAddressDeny=any` alone defeats most
real-world RCE payloads, which assume they can dial out.

**What Grade 1 proves:** the worker is owned ⇒ it reaches only the enumerated set. **What it does
not:** that the worker won't be owned. We assume it will be.

## The curveball — ctui-wm already owns the server

A later note (2026-10-25): the question "should we DIY this?" was asked against the assumption that
"own the stack" is hypothetical. It is not. **`ctui-wm` already contains, in C and from scratch,
every piece of a web stack except the dynamic language — and the server half is in production.**

- **`plugins/net` (~3k lines) — a *fuzzed* HTTP/1.1 server with TLS and mutual-TLS.** `httpd.c`
  (request heads parsed and fuzzed, Content-Length bodies, keep-alive, handler-per-request),
  `listen.c` (thread-per-connection under a cap, a stop fd), `conn.c` (deadlines, chunked decoder),
  `tls.c` (mbedtls 4, server side with `VERIFY_REQUIRED` client certs).
- **`apps/remote` (~1k lines) — static files + a routing table that mirrors the site's `.htaccess`
  + a CGI bridge** that spawns `php-cgi` per request *as the user* and streams the reply. Live on
  the user's iPhone today (`server_remote_control.md`, sessions 165-166), serving the same phpanta
  framework the public site runs.
- **`src/markup` (~23k) + `src/web` (~12k)** — a from-scratch browser rendering engine (HTML/CSS
  parsers, cascade, a layout engine, DOM, markdown) and a fetch/cache/cookie/adblock client.
- **`src/webview` + QuickJS** (vendored ng fork) — a JS engine wired into the DOM.
- Precedent for owning a *language*: **`ush`** in µOS, a typed scripting language with a
  whole-program type check — real language-implementation experience, not just document parsers.

This reframes "DIY" into three separable decisions, which the earlier draft collapsed into one:

| DIY target | Status | Security verdict |
|---|---|---|
| **Own the HTTP server** (parser, TLS, routing facing the network) | **Mostly built** — `ctui-remote` proves it, in production | **High value, do it.** The network-facing parser is the real attack surface; a small, fuzzed, auditable, *signable* C server is exactly Grade 1 containment, better than a shared-host Apache we can't inspect. |
| **Own the language runtime** (replace the PHP interpreter) | Not started | **Not a security win.** A dynamic-language interpreter is a large, bug-prone thing whether it is PHP, QuickJS, or ours — QuickJS has had its own CVEs. Owning it moves the bugs to code with fewer eyes, it does not remove them. Decide this on *architecture* (one language across client + server?), never on security. |
| **Own the glue between them** (a capability-scoped host API around a contained interpreter) | Not started | **The sweet spot.** See below. |

**The move the assets actually point at:** serve neurosys.gg from `ctui-remote`'s stack instead of
Apache + php-fpm, on the bare-metal box. That retires the entire Apache/php-fpm surface and delivers
the thing Grade 2 wanted — *a server whose every line we wrote, can sign, and can attest* — using
code already written and already fuzzed. The CGI bridge means the phpanta app does not change at
all; only what sits in front of it does.

**If the itch is to own the language too,** the honest form is not "write a new interpreter." It is:
keep the owned C server in front, run the dynamic layer in **QuickJS (ours) behind a
capability-scoped host API** — the C side exposes only the exact functions a page needs (render,
read `data/`, call the hub), nothing else reachable — with an **ephemeral JS context per request**
(the Grade 1 "no shared mutable state" invariant, now enforced inside our own process) and seccomp
around the whole thing. QuickJS is still a big interpreter, so it is *contained*, not trusted — the
same posture as everything else here. What this buys over PHP is not fewer interpreter bugs; it is
**one language across client and server, an engine we patch on our own clock, and a host surface
small enough to enumerate** — the capability list becomes the whole server-side API, auditable by
eye.

**The de-risked experiment:** the CGI bridge already spawns `php-cgi`. Point it at a QuickJS host
binary instead for *one* non-critical route, behind the same mTLS, and measure. It bets nothing —
the rest of the site stays PHP, and the swap is one entry in the routing table. That is the "give
DIY a shot" that plays to every asset without betting the site.

**What does not change:** we still never write crypto (mbedtls/openssl stay), and the
"don't own the PHP *runtime*" conclusion stands — the point was never "write a PHP," it was "own the
server and the glue, contain the interpreter." ctui-wm shows the server half is already done.

## phpanta's floor — the verifiable low-level API already exists

A deeper look at phpanta's core (2026-10-25), on the hypothesis that *our PHP style already enforces
a narrow enough surface to stand on.* It does, and the result is the single most important finding
in this document: **the "verifiable low-level API" we would design for a DIY runtime is already
drawn — implicitly — as the public surface of about ten wrapper classes, and almost the entire
codebase sits on top of it without touching PHP's built-ins directly.**

**The measurement.** Of phpanta's ~37k lines, the layers that make up the application bulk call
*zero* raw PHP I/O, crypto, or process built-ins:

| Layer | files touching a raw built-in |
|---|---|
| View (3.8k), Controller, Form, Text, Data, Router | **0** |
| Model (7.3k) | 8 call sites total — `hash()` ×3, `random_bytes()` ×3, `scandir()` ×2 |

Everything else funnels through a small floor, each class wrapping a handful of built-ins behind a
typed, named surface:

| Floor class | public methods | built-ins it owns |
|---|---|---|
| `Support\File` | 16 | fopen/fwrite/fread/fclose, file_get/put_contents, unlink, rename, touch, chmod, flock |
| `Support\Directory` | 9 | mkdir, rmdir, scandir, stat |
| `Support\FileLock` | 4 | fopen, flock, fclose |
| `Http\FileBody` | 3 | fopen, fread, fclose |
| `Http\Upload` | 6 | rename, chmod |
| `Http\Request` | 30 | `file_get_contents('php://input')` — the SAPI input boundary |
| `Http\SessionSeal` | 4 | openssl_encrypt/decrypt, random_bytes |
| `Service\ApiGate` | 3 | hash, hash_equals |
| `Support\PublicKey`, `Support\PasswordHash` | — | openssl verify, password_* |
| `Service\Machine\CommandRunner` | 2 | proc_open *(not deployed)* |

That is the whole floor: **roughly twenty built-ins, behind roughly ten classes, under ~37k lines
of application code.** `File`'s own docblock already reads like a contract — what it reads, what it
refuses (it *cannot* create a directory), the exact warning it suppresses and why. The style the
last review called a constraint is really a chokepoint architecture, and a chokepoint is exactly
what makes a surface verifiable: you only have to reason about the ten classes, not the thirty-seven
thousand lines.

**Why this matters for all three threads at once:**

1. **It is the verifiable API, today, with no box.** The floor is currently a *convention* — nothing
   stops a new file calling `fopen()` directly. Converting it to an *invariant* is a phpcs
   forbidden-functions sniff: "these ~20 built-ins may be called only inside these ~10 classes."
   That one rule turns "nothing bypasses the floor" from a habit into a CI-checkable property — the
   Grade 1 containment claim, *proven by a linter*, needing no hardware. The 8 stray Model call
   sites get folded into two new Support primitives first (`Random::bytes()`, `Digest::of()`) so the
   rule starts clean.

2. **It is the portable host API for the contained-QuickJS plan.** Because the application only ever
   speaks to the floor, porting the runtime means reimplementing *the floor* — ~20 primitives — not
   37k lines. A QuickJS host exposes exactly `File`/`Directory`/`seal`/`hmac`/`readInput` as
   capability-scoped functions and the app above is unchanged in shape. The capability list the
   earlier section wanted to "enumerate by eye" is literally this table.

3. **It is small enough to attest.** ~20 C primitives plus mbedtls/openssl for the crypto is a floor
   you can fuzz exhaustively and reason about line by line — the Grade 3 "prove the glue, not the
   engine" slice, sized correctly. The interpreter above stays contained, not trusted; the floor
   below is where assurance is cheap because it is tiny.

**So the DIY the user is reaching for is not "write a runtime" — it is "make the floor explicit,
total, and enforced."** That is a bounded, high-value piece of work that pays off three ways: it
hardens the current PHP site immediately (the sniff), it defines the exact surface any future
QuickJS/own-language runtime must implement (the port), and it is the only part small enough to ever
call *verified*. The floor is the bridge between the PHP we have and any runtime we might own.

**First concrete step, needs nothing but the repo:** write the forbidden-functions sniff, fold the 8
Model strays into `Random`/`Digest`, and publish the floor as a one-page contract in `docs/`. After
that, the floor is a specification, and everything downstream — the C server front, the contained
QuickJS, the attestation — implements against a surface that is written down and enforced rather
than merely observed.

## Grade 2 — attested integrity (the reason to own the box)

This is what bare metal buys that Strato shared hosting cannot, and it directly retires the standing
worry from the last security review: *Strato holds `update.pub` and `session.key` on its disks, so
Strato stays trusted.* On a box we own, the disk at rest stops being a trusted principal.

### Measured boot + TPM-sealed secrets

UEFI Secure Boot → a kernel *we* signed → the TPM 2.0 accumulates a hash chain of what actually
booted in its PCRs. **Seal `session.key` and the update CA to those PCRs, so the secrets only unseal
when the booted stack is byte-for-byte the one we signed.** Swap the kernel, patch the initramfs,
boot a rescue USB to read the disk — the PCRs differ, the TPM refuses to unseal, the secrets are not
there to steal.

This is the crisp upgrade over Strato: there, a secret on disk is readable by anyone with disk
access. Here, *a secret at rest does not exist in usable form unless the right software is running.*
That is a cryptographic property, not a filesystem permission.

### dm-verity on the code partition

The served code lives on a read-only device backed by a Merkle tree whose root hash we sign. Any
modification to any served byte fails verification at read time. **"Has my deployed code been
altered" becomes a hash check the kernel performs continuously, not a `find -mtime` we remember to
run.** The `ReadOnlyPaths` in the Grade 1 unit stops being a courtesy and becomes
cryptographically enforced — a compromised worker *cannot* write the code partition because the
device is physically read-only and verity would reject a tampered block anyway.

### Signed, reproducible deploy → the chain closes

The push already signs a release serial (ApiGate). Extend it end to end: the build emits a content
digest, we sign it, the box verifies the signature *and* the dm-verity root before swapping the code
partition in. Now the chain from **what was committed → what was built → what is served** is
verifiable at every hop. The existing `UpdateApplier` / `TarArchive` / `ApiGate` machinery is the
right skeleton; this hangs attestation off it rather than replacing it.

**What Grade 2 proves:** the running stack is the one we signed, and the secrets exist only while it
is. **What it does not:** that what we signed is free of bugs (that's Grade 3), or that the silicon's
root of trust is honest (out of scope).

## Grade 3 — correctness, and the one honest slice of it

Formally verifying the PHP engine or the application: **not realistic, and not attempted.** This is
the µOS-versus-seL4 line — a from-scratch *verified* web stack is a career, not a project, and we
would be trading a real site for a toy to chase a proof nobody needs.

The genuine slice is this: **the security-critical surface is tiny and separable.** ApiGate's HMAC
signing, SessionSeal, the passkey verifier, the update CA — a few hundred lines wrapping OpenSSL and
`hash_*`, already judged sound in the 2026-10 review. The realistic high-assurance move is to treat
*those* differently from the other 50k lines:

- **Property-test them to exhaustion** — constant-time assertions on the comparisons, round-trip
  invariants on seal/unseal, fuzzing the signature verifiers against malformed input. This is
  cheap and it is the part where a bug is catastrophic.
- **Optionally, later,** lift just those primitives into a memory-safe core (a small Rust library
  behind FFI) and leave PHP as the glue. This is the only place where "own this bit of the stack"
  earns its cost — not the runtime, not the crypto math (never roll that), but the *handling* around
  the primitives. Flagged as future work, not this round.

We never write our own crypto primitives. Grade 3's honest form is "prove the glue around trusted
primitives, leave the primitives and the engine alone."

## What this is not

- **Reversed on 2026-10-29: the runtime leaves PHP.** This design still confines what runs, but what
  runs becomes [c-serve](c-serve.md), a C host with the site compiled in, and that document
  subsumes Phase 2 and Phase 4 below.
- **Not a reason to own the PHP runtime.** Owning the *binary* was rejected for Strato because you
  can't; on bare metal you *can*, but it buys almost nothing Grade 1+2 don't already give, and it
  adds a build to maintain. Track the extension contract (`health v1 extensions`) instead.
- **Not free.** It adds to the trusted base everything shared hosting handled: kernel patching, TLS,
  firewall, the boot chain, log handling, physical-ish custody of a rented box. The trade is only
  worth it for the Grade 2 properties — if we didn't want attestation, Strato shared hosting plus
  the Grade-1-flavoured `.user.ini` hardening is the saner answer.

## Migration path, if we proceed

The staged sequence is the **Plan of record** at the top of this document — Phase 0 (the floor)
first, needing only the repo, through Phase 4 and the parallel Grade 3 slice. It is written so each
phase stands alone and we can stop at any one; the legitimate stopping points are noted there. This
heading is kept only as a signpost so the plan is not looked for in two places.

## Related

- Site security review and the PHP-surface audit: the neurosys-site-security memory.
- [µOS](../../../../Projects/µOS) — the from-scratch OS, the reference for what owning-the-stack
  actually costs and for the confine-don't-trust posture.
- `docs/deployment.md` — the current Strato deployment this would replace.
- `docs/security.md`, `docs/reviews/` — the signing/sealing machinery (ApiGate, UpdateApplier,
  SessionSeal, PasskeyVerifier) that Grade 2 extends rather than replaces.
