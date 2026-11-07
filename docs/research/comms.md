# Research — comms, the friend group's platform on c-serve

> **Status: planned (2026-11-08), nothing built.** This is the plan of record for replacing Discord
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
| Concurrency | One epoll loop and a timer wheel; a small pool for PBKDF2 | the loop | — | slowloris-style abuse, ASan, TSan |
| Throttling | A tarpit on the timer wheel (below) | the tarpit | — | its own unit tests, abuse scripts |
| Login | Passkeys (WebAuthn) | the verifier, a CBOR subset | mbedtls ECDSA | `tools/authenticator.php` as a software peer |
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

- A failure parks the connection on the timer wheel; nothing spins or blocks the loop.
- A request that arrives while its key is penalised gets an immediate `429` with `Retry-After` and
  extends the penalty; only one connection per key is parked.
- The penalty grows exponentially to a hard cap (five minutes) and decays.
- The key is the account and the address together — never the account alone, which would let a
  stranger lock a friend out. An IPv6 address counts as its /64.
- Parked connections are capped per key and in total; the key table is fixed-size.
- Success and failure take the same time. An unknown demo slug costs the same PBKDF2 as a wrong
  password.

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

- **m1 — the loop (c-serve's c6).** epoll and the timer wheel replace thread-per-connection; the
  PBKDF2 pool; the tarpit; the rest of c6 (gzip, access log, cert reload, the static production
  build). *Gate:* `test/cserve.sh` and `diff.sh` unchanged, plus abuse scripts — slow clients, floods,
  parked-connection exhaustion — under ASan and TSan.
- **m2 — on its own feet.** TLS 1.3 only, SNI, the ACME client. *Gate:* a full issuance and renewal
  against Pebble; testssl.sh clean. After m2, c-serve can run on the box alone and take neurosys.gg.
- **m3 — text.** Passkey enrolment and login, sessions, SSE, chat rooms and history in SQLite.
  *Gate:* the software authenticator against every ceremony promise; fuzzed CBOR and SSE; the three
  browsers under Playwright.
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

- **Invitations.** Admin services are scrapped, so who mints the link a friend enrols a passkey
  with? The lean answer is an invite signed offline with a P-256 key whose public half the box
  holds, as `update.pub` was.
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
