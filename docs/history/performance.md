# History — performance

Before-and-after figures of past changes. The current measurements are in
[../performance.md](../performance.md). The same changes from the code's side are in
[frontend.md](frontend.md) and [markup.md](markup.md).

## Bundling the prod tree

### 2026-09-08 — one bundle instead of forty-nine minified files (`e49afef`)

The payload table carried a row for what shipped before the bundle:

| | files | raw | gzip |
|---|---|---|---|
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

Of that render time, measured on the debug tree's 46-link preload block: that was a real cost and it
is now zero on the tree that ships.

## Parsing the privacy policy

### 2026-09-09 — `/privacy` stopped being a pass-through (`17cca79`)

**The `/privacy` row is the only one re-measured on 2026-09-09**, and it moved because that page
stopped being a pass-through: `MarkupParser` reads both halves of the policy into the markup tree
instead of `RawHtml` emitting them verbatim. It is the largest single cost this site has taken for a
guarantee.

`RawHtml::render()` was 0.004 ms, so effectively all of it is new.

**`/privacy` is smaller on disk and bigger on the wire than it was, which is the opposite of what
anyone would guess.** Parsing decodes character references, so the 44,404 bytes `RawHtml` used to
emit are 42,528 now — and the gzipped body went *up*, 12,399 → 13,378. `&auml;` is six bytes that
repeat 69 times in the German half and compress almost to nothing; the `ä` that replaces it is two
bytes that do not. Fewer bytes, less redundancy, worse ratio. Still 29 KB saved and still not a
close call, but it is a reminder that raw size is not the thing being compressed.
