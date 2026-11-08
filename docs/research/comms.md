# Research — comms, the friend group's platform on c-serve

> **Status: m1–m3 built (2026-11-08); m4 and m5 planned.** This is the plan of record for replacing Discord
> among a small friend group (five people to start) with text, voice, video and screen share, and
> later live streams and recorded sets of the user's own productions. Everything runs on the
> dedicated box, as c-serve code. It follows [c-serve.md](c-serve.md)'s c6, and it keeps
> [attested-single-box.md](attested-single-box.md)'s aim: what runs is code the user wrote and can
> measure.

## Decisions

- **Everything is written here, except the crypto and the database.** mbedtls does every cipher,
  MAC, signature and the DTLS handshake; SQLite stores what must survive a restart. No proxy, no
  SFU, no streaming server and no TURN server from elsewhere.
- **One way per need.** Each need below has exactly one implementation, chosen because the way is
  standard, small, and checkable against test vectors or a reference implementation offline. A
  second way is added only when the first is proven unable to do the job.
- **Tests are the trust.** Every parser is fuzzed, every RFC with test vectors is held to them, and
  every protocol is checked against a reference peer — locally, or on the box on a private network
  before it takes traffic. That is the `diff.sh` pattern: a known implementation is the oracle.
- **HTTP stays HTTP/1.1.** No h2, no h3. The site is small and its prod build is bundled, so the
  multiplexing buys almost nothing, and h2's DoS classes (Rapid Reset, CONTINUATION floods, HPACK
  bombs) never apply.
- **Browser only.** No native client.
- **Text first.** Friends leave Discord for a place to talk, not only for voice, so chat ships
  before media.
- **Login is passkeys, and nothing else.** The admin's passkey ceremony was scrapped with the admin
  services (c-serve.md); this is a new, smaller use of the same verifier, ported from Phpanta.
- **Mail stays off the box.**
- **Addresses are logged by family only, as counters.** Never an IP — the same reasoning that keeps
  download logging off. The tarpit's in-memory table of keys is named in the privacy policy.

## The one way, need by need

| Need | The one way | Written here | Borrowed (crypto/DB) | Oracle |
|---|---|---|---|---|
| HTTP | HTTP/1.1, c-serve | done | — | `conformance.sh`, `diff.sh` |
| TLS | TLS 1.3 only, one P-256 cert per name, SNI in c-serve | the SNI callback | mbedtls | testssl.sh, the three browsers |
| Certificates | ACME (RFC 8555) with HTTP-01, served by c-serve itself | the client: JWS, a JSON subset | mbedtls ECDSA | Pebble |
| Concurrency | One epoll loop, every deadline in a min-heap; every handler on a fixed pool | the loop, the heap, the pool | — | `tools/abuse.py`, ASan, TSan; the heap against a sorted reference |
| Throttling | A tarpit on the loop's deadlines (below) | the tarpit | SHA-256 for its keys | its own unit tests on an injected clock, abuse scripts |
| Compression | Static files only, gzip copies made at deploy (`cserve-precompress`) | the tool, the choice of copy | zlib | the host test; never per request (BREACH) |
| Login | Passkeys (WebAuthn), ES256, no attestation: the key as SPKI beside the authenticator data, so no CBOR | the verifier (`kit/passkey`) | mbedtls ECDSA | `comms-authenticator` as a software peer |
| Signalling and chat | SSE for server→client, POST for client→server | SSE framing | SQLite for history | the three browsers |
| Media transport | ICE-lite and DTLS-SRTP on one UDP port; ICE-TCP as the fallback | STUN, the demux, the SRTP key derivation | mbedtls: DTLS-SRTP, AES-CTR, HMAC-SHA1 | RFC 5769 and RFC 3711 vectors; pion as a peer |
| SFU | Forward RTP; answer NACK and PLI; send TWCC feedback on every incoming stream | RTP/RTCP, an SDP subset | — | the three browsers under Playwright with fake media |
| Ingest | WHIP | one POST on the same stack | — | OBS (WHIP since 30) |
| Public streams | One HLS rendition of fMP4 segments, served as static files; an MSE loader in the player | the fMP4 packager, the loader | — | ffprobe, mp4box (dev only) |
| Archive | The same fMP4 segments, joined | nothing new | — | the neuro.SYS player |

What the ways leave out, and why:

- **No TURN.** The box has a public address, so the server is ICE-lite: clients reach out to it, and
  NAT is their side's business. ICE-TCP covers networks that block UDP. A network that only passes
  TLS on 443 cannot join; for this group that is accepted.
- **No WebSocket.** SSE plus POST carries signalling and chat on plain HTTP/1.1, with no framing or
  masking parser to write and fuzz.
- **No data channels.** They need SCTP over DTLS; chat already has SSE.
- **No simulcast and no transcoding.** Five people on a gigabit line need neither; a weak link drops
  packets rather than getting a smaller layer.
- **No RTMP, SRT or Icecast.** WHIP reuses the media stack, and fMP4 over HLS serves audio, video and
  the archive alike.
- **No live FLAC.** Browser WebRTC carries Opus only, at most 510 kbps stereo — transparent, but not
  lossless. Sets stream in Opus; the FLAC follows through the release pipeline.

## The tarpit

Passkeys mean a failed login is a bad signature, not a guess, so the tarpit is a rate limit on the
ceremony and the brake on the demo gate's passwords.

- A failure's reply is held back on its connection's deadline; no worker waits, and nothing
  spins or blocks the loop.
- A request that arrives while its key waits gets an immediate `429` with `Retry-After`, before
  any PBKDF2, and makes the wait a step longer. So a guesser who waits for each answer is slowed
  exponentially, and guesses sent alongside are refused.
- Only a try counts: a request with no credentials is the browser asking what to send.
- The wait doubles from one second to a hard cap (five minutes), and a count drops by one per
  ten quiet minutes. A success clears the account's count.
- The key is the account and the address's network together — never the account alone, which
  would let a stranger lock a friend out. An IPv6 address counts as its /64. The network alone is
  a second key that waits only past eight failures, so guesses spread over accounts are slowed
  too.
- The key table is fixed-size. Its set is picked by a SHA-256 of a secret drawn at start, so keys
  can't be aimed. A full set gives up the entry with the least wait left, never the oldest, so a
  flood of junk keys can't wash a penalty away.
- Held-back replies are capped in total (past the cap a connection is closed, never answered
  early), and connections per network are capped by the loop.
- A known and an unknown demo slug cost the same PBKDF2 and get the same 401.

## On the box

- **Two processes, one codebase.** `web` holds TLS, HTTP, SSE, auth and chat; `media` holds UDP,
  DTLS, SRTP, forwarding and the fMP4 writer. They talk over a unix socket. Each has its own seccomp
  profile; `media` sees nothing on disk but the recording directory.
- **Three ports**: 443/tcp (`web`, every name by SNI), one UDP port and one TCP port (`media`).
  nftables denies the rest; the UDP port is not rate-limited as TCP is.
- **The kernel**: `linux-lts` from a pinned archive snapshot, so the image is reproducible; a reboot
  is the patch path, since measured boot re-measures it. Unprivileged user namespaces off,
  `kernel.io_uring_disabled=2` (epoll is the one way), and `kernel.modules_disabled=1` after boot.
- **Addresses**: dual-stack, IPv6 preferred, AAAA everywhere, an HTTPS record with `ipv6hint`. The
  family counters say what IPv6-only would cost.

## Phases

Each phase ends when its gate is green, and each gate runs offline.

- **m1 — the loop (c-serve's c6).** *Done* (2026-11-08; [c-serve.md](c-serve.md)'s c6 has the
  detail). The parts:
  - epoll, the deadline heap and the worker pool replace thread-per-connection;
  - bodies are pulled;
  - the tarpit;
  - the access log, without addresses;
  - precompressed static files;
  - the production link (`cserve-static`).

  *Gate:* `test/cserve.sh` 113/113 on both hosts; `diff.sh` 374 cases, under `SAN=1` too;
  `make check`; `make abuse`. The abuse suite covers slowloris, slow-read, a per-network flood, a
  connection flood, a pool flood and held-back replies, under ASan and TSan.
- **m2 — on its own feet.** *Built* (2026-11-08). The parts:
  - **TLS 1.3 only on the server**, a P-256 certificate per name in `--certs DIR/<name>/`, picked by
    SNI. No SNI, or a name not there, gets `unrecognized_name`.
  - **SIGHUP reads the names again.** A connection keeps the set it began under, and a set that
    doesn't load leaves the old one. An empty directory starts and refuses every handshake, so a
    new box can boot before its first certificate.
  - **`cserve-acme`** gets the certificates by HTTP-01, ES256 JWS, a fresh key per issuance, in a
    process of its own. It signals nothing: its timer's `ExecStartPost` sends the SIGHUP.
  - **A plain listener** answers the challenges (`--acme-dir`, any `Host`), a `308` to https for a
    certificate's name, and a 404 for the rest.
  - **The production tree:** `php tools/app-data.php --prod` prints `build/dist/app/` against the
    prod manifest.

  *Gate:*
  - `make acme`: Pebble 2.8.0, 17/17, the 16 ACME checks also under ASan — issuance for two names
    from an empty `--certs`, a rerun that keeps them, a forced renewal served after a SIGHUP
    without a restart;
  - testssl 3.2: nothing above LOW but Pebble's untrusted root. The grade is B, capped by that
    chain alone. The one LOW is `FS_KEMs`, no post-quantum hybrid key exchange, which mbedtls's
    TLS does not offer;
  - `make check`, with the names and ACME suites and fuzz modes 10 and 11;
  - `make abuse`;
  - `test/cserve.sh` 113/113 on all three runs, the prod tree's included.

  After m2, c-serve can run on the box alone and take neurosys.gg.
- **m3 — text.** *Built* (2026-11-08), in its own repo, `~/Projects/comms`, as a c-serve app
  served on a name of its own (`--app comms --for HOST --app-root DIR`).
  - **Joining** is by invite. `comms-invite`, on the admin's own machine, signs a token with a
    P-256 key, and the box holds only `invite.pub`. The token rides the link's fragment, and its
    nonce is spent once. A revocation is the same kind of token.
  - **Passkeys:** ES256, discoverable credentials, user verification required, no attestation.
    The key comes as SPKI, so no CBOR is read. `kit/passkey` also checks that the attested
    credential and key are the ones posted.
  - **State is server-side in SQLite**: challenges spent by deleting them, sessions stored as the
    SHA-256 of a `__Host-` cookie. The one cross-site rule is the `Origin` check, with
    `SameSite=Strict` as well.
  - **SQLite is the vendored amalgamation (3.54.1)**, pinned by SHA3-256 and checked before every
    build. It is compiled in with its own options and kept out of lint. There is a connection per
    thread, and the loop has a read-only one of its own.
  - **Chat** is SSE on c-serve's new loop streams plus POST. A post wakes the room's streams, and
    a woken stream reads what is new from the database.
  - **The password lane** (slow routes hold at most half the workers) answers the open question
    below.
  - **The crypto lock**: key slots and the RNG are taken under `tls.c`'s lock, since mbedtls here
    has no threading.

  *Gate:*
  - `comms-authenticator` holds a running host to every ceremony promise (30 checks) and to the
    tarpit (4), under ASan, TSan and -O2.
  - The kit's fuzz modes 10–13: client data, authenticator data, SSE, base64url and cookies.
  - The three browsers under Playwright (`make browsers`). Chromium runs the real ceremonies
    with its virtual authenticator. Firefox and WebKit stream between two contexts, reconnect
    after going offline, page through history and carry a 4000-byte message. WebKit runs in
    Playwright's Ubuntu 24.04 image, since its build wants that release's libraries.
  - c-serve's `make check`, `make abuse` (a password flood now leaves pages at 0.00 s) and
    `make acme`.
- **m4 — voice, video, screen share.** STUN and ICE-lite, DTLS-SRTP, the SRTP key derivation,
  RTP/RTCP forwarding with NACK, PLI and TWCC, the SDP subset, the `media` process. DTLS-SRTP
  through mbedtls is proven against all three browsers before anything is built on it. A music mode
  sends Opus stereo at up to 510 kbps with echo cancellation, noise suppression, gain control and
  DTX off. *Gate:* the RFC vectors; pion as a peer; a five-browser call under Playwright with fake
  media; fuzzed STUN, RTP, RTCP and SDP.
- **m5 — streams and the archive.** WHIP ingest, the fMP4 packager, HLS as static files, the MSE
  loader in the player, the archive. *Gate:* OBS publishing over WHIP; every segment read clean by
  ffprobe and mp4box; playback in the three browsers.

The estimate is 6–8k lines of C on top of c-serve. The RTP/RTCP layer, the SDP subset and the fMP4
packager are the largest pieces.

## Risks

- **Congestion control.** Browsers raise their send rate only on feedback, so TWCC on every incoming
  stream is required, not optional. Per-subscriber adaptation is out: a friend on a weak link loses
  packets.
- **DTLS-SRTP in mbedtls** (`MBEDTLS_SSL_DTLS_SRTP`) is far less exercised than its TLS path, which
  is why m4 opens on it.
- **System audio in a screen share** depends on the browser and the OS. Music from a DAW goes in
  through a virtual device instead (a PipeWire loopback, BlackHole, VB-Cable).

## Rights

Streams carry the user's own productions only. Without GEMA membership, those need no licence;
authorship is kept on record. A set with anyone else's tracks needs GEMA licensing, and joining GEMA
would bring a licence even for the user's own works. Checked with someone qualified before the first
public stream.

## Open questions

- **Password floods and the pool — answered (m3).** A route that checks a password is `slow`, and
  slow requests hold at most half the workers, queued or running. Past that they get a quick 503.
  Under a flood, pages are answered at once, under either sanitizer.
- **Invitations — answered (m3).** An invite is signed offline with a P-256 key whose public half
  the box holds, and so is a revocation (`comms-invite`).
- **PSA on workers — answered (m3).** mbedtls is built without threading, so every use of a key
  slot or the RNG outside `tls.c` takes its lock (`cserve_crypto_lock()`). Keyless hashing and
  PBKDF2 over bytes don't, so a password check never holds up a handshake.
- **The SDP subset.** Exactly which lines, codecs (Opus, VP8 or H.264) and extensions are accepted;
  everything else is refused.
- **TWCC.** Feedback interval and packet format detail, settled in m4 against the browsers.
- **Names.** Which hostnames ACME issues for (`neurosys.gg`, a name for chat and calls, one for
  streams), and whether IPv4 needs more than one address once SNI carries them all.
- **The group's IPv6 reach**, read from the family counters once m2 is live.

## Related

- [c-serve.md](c-serve.md) — the host; m1 is its c6.
- [attested-single-box.md](attested-single-box.md) — the box, measured boot and the containment
  this keeps.
