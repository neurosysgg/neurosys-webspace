# Phase 2 — generating a release from its folder

The endgoal is a release built from a supplied `.flp`/zip. What exists now is the half that can be
verified today: `tools/stage-release.php` turns a prepared folder under
`~/Music/neuro.SYS/releases/<name>/` into the `data/releases.php` entry that [releases.md](releases.md)
otherwise asks a person to type, and checks the folder is fit to upload before it does.

That is the right half to have built first, and not only because it is easier. FL Studio writes the
tags this reads — every master here carries `ENCODER=FL Studio` — so the chain is
**`.flp` → export → prepared folder → entry**. A `.flp` reader would replace the *export* step. It
would not replace this one, and it could not be checked against anything until this one existed.

## Using it

```bash
php tools/stage-release.php ~/Music/neuro.SYS/releases/ill           # report, then the entry
php tools/stage-release.php ~/Music/neuro.SYS/releases/ill --check   # report only, exit 1 on FAIL
```

The report goes to **stderr** and the entry to **stdout**, so `> entry.php` keeps the block alone and
`2>&1 >/dev/null` keeps the report alone. The report names each fact, its value, and **where it came
from** — that third column is the one worth reading, because a fact taken from a filename and a fact
taken from a tag are not equally trustworthy.

```
  title    'ill.'                       FLAC TITLE tag
  slug     'ill'                        derived from the title
  bpm      140                          FLAC BPM tag
  key      D# Minor                     FLAC INITIALKEY tag
  genre    Dubstep                      FLAC GENRE tag
  formats  FLAC, WAV, MP3, STEMS        files present
  cover    ill. cover.jpg               web/ export

  OK   every format is the same recording, and every lossless one is 24-bit/48.0kHz
  OK   stems: the zip matches REMIX PACKAGE/, 6 files
  OK   cover: ill. cover.jpg, prepared for the web
```

**Nothing is emitted while a check FAILs**, and that is the point of the ordering: a folder that is
not ready is not one to be minting HiDrive share links against, because a link is bound to the bytes
it was minted for.

**It prints; it does not write `data/releases.php`.** That file is ordered by hand, newest first, and
carries the one field nothing can derive. Generating into it would leave it half-authored and
half-generated — the arrangement `tools/build-css.mjs` already refuses when it rejects a rule in a
manifest, since a file either orders parts or is one.

## What the folder knows

| Field | Source | Fallback |
|---|---|---|
| `title` | FLAC `TITLE` | — |
| slug (array key) | derived from the title | — |
| `bpm` | FLAC `BPM` | the filename, `140 D#Min ill …` |
| `key` | FLAC `INITIALKEY` | the same filename token |
| `genre` | FLAC `GENRE` | — |
| `formats` | which files exist | — |
| `cover` | `web/` export | `cover.*` at the root, then the FLAC's `PICTURE` block |

Each field reads its sources in order and stops at the first hit. Where none hits, the field is null
and the preflight says so — **nothing is guessed**, on the same reasoning that has
`HttpMethod::tryFrom()` return null rather than assume GET, and `Request::path()` hand back a target
verbatim so it 404s rather than quietly serving the home page.

The `genre` row has no fallback on purpose. It resolves through `Genre::tryFrom()` and fails loudly
when it misses, because the tag is free text that FL Studio never validates: the demos folder carries
`bass house?`, `bass house???` and `Melodic Dubstep`, none of them cases. A new genre is a new enum
case, which is a decision rather than a lookup.

## What it cannot know

Three facts, all arriving from outside the filesystem:

- **`description`** — `'wub wub'`, `'debut single'`. Editorial, with nothing to derive it from.
- **HiDrive share ids** — minted by hand in the web UI. The REST API can do it, but the OAuth
  credentials take STRATO up to 72 hours to issue; see [releases.md](releases.md).
- **SoundCloud `trackId` / `permalink` / `secretToken`** — they do not exist until the track is up.

**`Release` already models exactly this half-state**, which is why the tool is useful before any of
the three arrive. `new Format(ReleaseFormat::FLAC)` with no link renders the download card and
answers a click with a 503; `cover: null` renders the placeholder; an omitted `embed:` renders no
player. So the emitted entry is a **valid, renderable release the moment it is pasted**, and each
unknown is filled in later without changing its shape. `ReleaseFolderTest` asserts that by evaluating
the generated block and checking the `Release` it produces.

The entry names, per line, the file whose share id is wanted — which is the thing worth having to
hand while standing in HiDrive's web UI:

```php
    'ill' => new Release(
        title:       'ill.',
        …
        description: '',   // editorial — nothing in the folder supplies this
        cover:       null, // share id for ill. cover.jpg
        formats: new Collection(Format::class)->with(
            new Format(ReleaseFormat::FLAC),   // share id for ill..flac
            new Format(ReleaseFormat::STEMS),  // share id for 140 D#Min ill remix package.zip
        ),
```

## Where the code lives

`tools/stage-release.php` is a six-line entry point; the work is in `tools/lib/`, behind the CLI
layer described in `CLAUDE.md` under *The tooling*:

```
tools/lib/Release/
├── ReleaseFolder.php   ← reads a folder, and records where each fact came from
├── Preflight.php       ← judges it
├── EntryWriter.php     ← emits the data/releases.php block
├── Probe.php           ← the one metaflac/ffprobe shell-out site
├── AudioStream.php  Cover.php  Finding.php     ← what those three hand back
└── Fact.php  Source.php  FlacTag.php  Level.php  KeyNotation.php
```

Read, judge, emit — three verbs, three classes, and each one testable without the other two.

**None of it is under `src/`**, for two mechanical reasons:

- `deploy.sh` rsyncs `src/` with `--delete`. Anything there **ships to Strato**, where a
  release-authoring tool has no business being.
- `phpunit.xml.dist` names `src` as its coverage source, so a class there would join the site's
  coverage figure and need tests written against shell-outs to `metaflac` and `ffprobe`.

An earlier draft of this page gave a third reason, that the verify script bans heredocs under
`src/`. That is not accurate: the check matches `<<<'?HTML` and inline markup literals, so
`EntryWriter`'s `<<<'PHP'` would pass it. The two above are decisive on their own.

The tooling does load the site's `autoload.php` and resolve against the real enums, which is what
makes its output trustworthy: a genre the site cannot render fails in the tool rather than as a
`ValueError` when `data/releases.php` loads on the server.

`Source` is the enum that pays for itself immediately. Deciding whether to warn about a cover used to
be `$cover['from'] !== 'web/ export'` — a string on both sides of a comparison, where a typo in
either half turned the check off in silence. It is now `$cover->isWebExport()`.

`test/unit/ReleaseFolderTest.php` covers what needs no folder on disk — the key parser, slug
derivation, format ordering, and the shape of the emitted entry, which it `eval`s to prove it
produces a renderable `Release`. The folder-reading half shells out, which is not something a unit
test should reach for; it is exercised by running the tool, and the tool stays out of `composer test`
because the music folder is not in the repo and will not exist on a clone.

## The preflight, and the discrepancy behind each check

Every check here is one a real folder suggested. They are worth more than the emission, because they
run *before* an upload: a re-export after the fact costs the upload, a fresh share link and an edit to
`data/releases.php`.

- **Every claimed format is the same recording.** Durations compared against the FLAC, tolerance half
  a second. `ill.` spans 187.129 / 187.119 / 187.066 s — encoder padding. A ten-second gap means one
  file was re-exported and the others were not, which nothing notices until a stranger downloads the
  odd one out.
- **Every lossless format matches the master's rate and depth.** Not a hard-coded 24/48 — the check is
  agreement with the FLAC, so it holds whatever a future release is mastered at.
- **The stems zip matches the folder it was packaged from.** Rooted where the zip is rooted, so a
  `REMIX PACKAGE/` holding a MIDI beside `stems/` compares as the whole tree. The zip is what ships;
  the loose folder is scratch, and the two are free to drift.
- **A cover exists, and is the `web/` export** rather than a master PNG or a picture still embedded in
  the FLAC. The last two are a WARN, not a FAIL — publishable, but not what should be uploaded.
- **Every tag resolves to an enum case**, naming the offending value when it does not.

The parser behind the key check does two normalisations, and `ReleaseFolderTest` pins both: case
varies (`140 d#min` has occurred far more often than `140 D#Min`), and `MusicalKey` spells only
sharps, so a flat has to be folded to its enharmonic equivalent or it resolves to nothing at all —
quietly, which is the failure this whole arrangement exists to avoid.

## What was fixed in the two folders

Writing the preflight found four discrepancies, all since corrected **locally**. Both folders now
report clean, and both derive every fact from a tag rather than a filename.

| Was | Now |
|---|---|
| `ill..wav` was 16-bit/44.1kHz beside a 24-bit/48kHz FLAC, and carried no tags | re-derived from the FLAC master, bit-identical, 24/48, tagged |
| `hello world!.flac` had no `BPM` or `INITIALKEY`; the filename was the only record | both tags written; audio verified untouched |
| `hello world!` had no `web/` cover — its only art was embedded, and typed `image/apng` for a still | `cover.png` extracted, `web/` PNG + JPEG exported at ill.'s settings, block re-typed `image/png` |
| `ill/STEMS/` disagreed with the `REMIX PACKAGE/stems/` inside its own zip | folder moved to match the zip, which is what ships |

**The HiDrive copies are still the old bytes.** `/releases/ill/wav` will keep serving the 16-bit file
until it is re-uploaded, and `hello world!`'s FLAC there is still the untagged one. Worth checking
when you replace them: whether a share link survives an overwrite at the same path, or whether it has
to be re-minted and the id in `data/releases.php` updated.

## Path to the `.flp`

The prepared folder is the interface. Today a person fills it from FL Studio by hand; later a `.flp`
reader fills it automatically. Either way what turns it into a `data/releases.php` entry is this
code, and it is the only part of the chain that could be verified against two real releases today.

What a `.flp` would add: tempo and key from the project header rather than from a tag someone
remembered to set, the stem list from the mixer tracks rather than from filenames, and the export
itself. What it still will not know is the description, the share ids and the SoundCloud ids — the
same three, for the same reasons.

One fact is already sitting in the folder with nowhere to go: `ill.`'s FLAC carries
`DATE=2026-09-04`, and `Release` has no date field, so the catalogue's newest-first ordering is array
order maintained by hand. Adding one is a real option and deliberately not taken here.
