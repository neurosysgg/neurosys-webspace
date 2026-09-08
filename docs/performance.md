# Performance — what a request costs, and where it goes

Measured numbers for the site as it runs, and the harnesses that produced them. Nothing here is a
target: at the time of writing a warm request is answered in under four milliseconds and the whole
page is forty-five kilobytes, so this document exists to record where the time and the bytes
actually are rather than to argue that any of it is too much.

The front end's build decisions are argued in [frontend.md](frontend.md); this is the measurement
behind them. The two test suites are in [testing.md](testing.md), and the server the numbers were
taken against is in [deployment.md](deployment.md).

**Everything below was measured on 2026-09-08**, on the development machine — Apache 2.4.68 with
`mod_proxy_fcgi` to php-fpm 8.5.10, opcache on with the default optimizer, Xdebug 3.5.3 loaded in
`develop` mode. That is the same arrangement Strato runs except that Strato executes PHP as CGI, so
the shape holds and the absolute figures do not transfer.

---

## The headline

Time to first byte, median. **Cold** means opcache had to compile; **warm** is every request after.

| route | cold | warm |
|---|---|---|
| `/` | 10.3 ms | 2.65 ms |
| `/releases` | 12.2 ms | 3.09 ms |
| `/releases/ill` | 13.6 ms | 4.08 ms |
| `/privacy` | 9.9 ms | 2.62 ms |
| 404 | 9.8 ms | 2.55 ms |

Cold is paid once per deploy — or once per edit to any file the request touches, since
`opcache.validate_timestamps` is on. Everything else is warm.

**To reproduce a cold request without restarting anything**, invalidate opcache by touching the
sources and wait past `opcache.revalidate_freq`:

```bash
find src data -name '*.php' -exec touch {} + && sleep 2.6 && \
  curl -s -o /dev/null -w '%{time_starttransfer}\n' http://neurosys.localhost/releases/ill
```

---

## Where the cold time goes

All of it is opcache compiling, and most of *that* is the optimizer. Compiling the 106 files a
release page touches, measured three ways:

| | cost |
|---|---|
| plain compile, no opcache at all | 3.2 ms |
| compile into opcache, `opcache.optimization_level=0` | 5.2 ms |
| compile into opcache, optimizer on (the default, and what runs) | 8.2 ms |

So roughly three milliseconds of every cold request is the optimizer, and it buys the warm figures
above. This is not worth tuning — it happens once — but it is worth knowing that a cold request is
not doing any more *work* than a warm one. It is doing the same work behind a compiler.

---

## Where the warm time goes

Splitting a warm `/releases/ill` by preloading every class before the clock starts, so the cost of
*loading code* separates from the cost of *running it*:

| | ms | |
|---|---|---|
| re-including the 106 class files | 1.40 | bytecode is cached; linking and top-level code are not |
| the autoloader closure itself | 0.48 | 107 calls — `str_starts_with`, the pipe chain, `is_file`, `require` |
| the actual request | 2.83 | |

**A warm request still includes every file.** opcache caches the compiled form, not the inclusion,
so the 1.4 ms is irreducible short of preloading — which shared hosting does not offer.

And that 2.83 ms of work, by phase:

| phase | ms | share |
|---|---|---|
| `Layout::wrap` — building the node tree | 1.194 | 42.1% |
| `Node::render` — tree to markup | 1.075 | 37.9% |
| `Router::dispatch` — match, read `releases.php`, build the View | 0.278 | 9.8% |
| `SecurityHeaders::send` | 0.216 | 7.6% |
| `Request::fromGlobals` | 0.021 | 0.7% |
| `RouteInitialization::routes` | 0.019 | 0.7% |
| `Auth::requireSiteAuth` | 0.008 | 0.3% |
| `ETag::forBody` | 0.005 | 0.2% |

**Eighty percent of a request is building and rendering the markup tree.** Routing, authentication,
request parsing and the ETag hash are together under one twentieth of it — which is the answer to
the question the tree was always going to raise, and it is a smaller number than it looks: the whole
tree is one millisecond.

---

## Inside the tree

From an Xdebug profile of `/releases/ill`, by call count rather than by time — the time is inflated
and the counts are not:

| | count |
|---|---|
| `Element::render` | 137 |
| `Text::render` | 262 |
| `Element::__construct` | **440** |
| `Element::attr` | 223 |
| `Element::containing` | 81 |

`137 + 223 + 81 = 441`. **Every builder call constructs a copy**, so a document of 137 rendered
elements costs 3.2 element objects each. That is the immutable-builder design showing up as a
number, it is about 0.35 ms, and it is the price of the guarantee `#[\NoDiscard]` and `readonly`
are there to make — see the collections section of `CLAUDE.md`. Nothing here suggests changing it.

The one part of the tree that was worth measuring separately is the preload block, because it is
generated rather than authored:

| | |
|---|---|
| `Layout::modulePreloads()`, 46 links | 0.23 ms |
| as a share of the whole tree build | 23% |

That was a real cost and it is now zero on the tree that ships — see
[the front-end payload](#the-front-end-payload) below.

---

## Compression

`mod_deflate` compresses documents and text assets; the block that configures it is in
`public/.htaccess`, keyed on content type rather than on a file extension, so it reaches
`index.php`'s output as well as Apache's own static files.

| route | raw | gzip | saved |
|---|---|---|---|
| `/` | 7,050 | 1,350 | 80.9% |
| `/releases` | 7,226 | 1,362 | 81.2% |
| `/releases/ill` | 11,113 | 2,088 | 81.2% |
| `/imprint` | 7,656 | 1,445 | 81.1% |
| `/privacy` | 44,404 | 12,399 | 72.1% |

It costs server time, and the cost scales with the body rather than being flat:

| route | identity | gzip | cost |
|---|---|---|---|
| `/` | 2.44 ms | 2.51 ms | +0.07 |
| `/releases/ill` | 3.20 ms | 3.57 ms | +0.37 |
| `/privacy` | 2.34 ms | 3.33 ms | +0.99 |

The privacy policy spends a full millisecond to save 32 KB, which at 10 Mbit/s is twenty-six
milliseconds it does not spend. Not a close call in either direction.

**Non-2xx responses are not compressed, and this is Apache's behaviour rather than the site's.**
The 404 comes back at its full 6,902 bytes with the same `Content-Type: text/html; charset=utf-8`
that the 200s compress under, and Apache's own 404 for a missing `.css` is uncompressed too — the
error path does not carry the output filter chain that `AddOutputFilterByType` installs. It costs
about 5.6 KB per 404 and nothing else, there is no configuration here that changes it, and it is
recorded because it is invisible to both test suites and reads like a bug in the site when it is
not.

**`mod_deflate` merges with the site's own `Vary` rather than replacing it.** A document comes back
`Vary: X-Requested-With,Accept-Encoding` — worth stating because the failure it would otherwise be
is exactly the one `Vary` is on that response to prevent, and it would be silent.

Both of these can only be seen through a real server, so neither suite can assert them. Re-check
after a deploy:

```bash
curl -sI -H 'Accept-Encoding: gzip' https://neurosys.gg/assets/js/main.js | grep -i 'encoding\|cache'
```

---

## The front-end payload

The debug tree in `public/` ships 49 separate modules; the tree that deploys bundles them into one.
Both are gzipped at level 6, which is what `mod_deflate` uses.

| | files | raw | gzip |
|---|---|---|---|
| debug tree, as committed | 49 | 35,527 | 15,681 |
| minified per file — what shipped before | 49 | 21,796 | 12,798 |
| **bundled and minified — what ships** | **1** | **15,557** | **5,801** |

**6,997 gzipped bytes, 54.7%**, and 48 fewer HTTP responses. The reason it is so much larger than
minification alone was ever worth is that gzip's window then spans the whole graph instead of
restarting at every small module — a per-file measurement and a concatenated one are answering
different questions, and this codebase has been careful about that distinction in both directions.

Bundling also empties the preload list, which takes the block out of every document:

| | raw | gzip |
|---|---|---|
| `/` before | 7,050 | 1,350 |
| `/` after | 2,979 | 963 |
| `/releases/ill` before | 11,113 | 2,088 |
| `/releases/ill` after | 7,042 | 1,705 |

About **385 gzipped bytes off every page**, plus the 0.23 ms of render time the 46 elements cost.

---

## What is deliberately not optimised

- **The debug tree is not bundled or minified**, and must not be. `test/js/` imports it by path,
  `npm run coverage` pins its 100% gate to those exact paths, and the verify script diffs it
  byte-for-byte against a fresh `tsc`. All three want output a person can read.
- **The markup tree's copy-on-write builders stay.** 440 constructions for 137 elements is the
  measured price of a guarantee that holds for every element however it was built.
- **Images are not content-hashed.** They are vendored and hand-placed; a calendar TTL is the right
  tool for files that change about never.

---

## Caveats on the numbers

- **An Xdebug profile inflates by roughly four times** — the same request that answers in 4.9 ms
  profiles at 21.5 ms — so shares and call counts from a profile are trustworthy and absolute
  microseconds are not. Every millisecond figure in this document was taken with no profiler
  attached.
- **The phase harness's "warm" uses opcache's disk `file_cache`**, which is slower than the shared
  memory a real php-fpm worker reads. That is why it reports 4.9 ms where Apache reports 4.1; the
  Apache figure is the true one and the harness is for the split between phases, not the total.
- **Asset byte counts are gzip level 6**, matching `mod_deflate`'s default. Level 9 differs by
  under five bytes across the whole tree and is not worth distinguishing.
- **These are development-machine numbers.** Strato runs PHP as CGI rather than through
  `mod_proxy_fcgi`, so cold and warm both differ there. The ratios hold; the milliseconds do not.

---

## Further reading

- [frontend.md](frontend.md) — the build these payload numbers come out of, and why the two trees
  differ
- [testing.md](testing.md) — the two suites, and what each can and cannot see
- [deployment.md](deployment.md) — the live host, and what `.htaccess` does to a response
- [architecture.md](architecture.md) — the request traced end to end, which is what the phase table
  is a clock on
