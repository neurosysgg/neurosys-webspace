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
tools/lib/
├── Flp/                ← the project reader — see "Phase 3" below
├── Php/                ← Expression, Value, ClassConstant, Argument, Call, Entry
└── Release/
    ├── ReleaseFolder.php   ← reads a folder, and records where each fact came from
    ├── ProjectFile.php     ← finds the .flp, loose or inside a zip
    ├── Preflight.php       ← judges it
    ├── EntryWriter.php     ← emits the data/releases.php block
    ├── Probe.php           ← the one metaflac/ffprobe shell-out site
    ├── AudioStream.php  Cover.php  Finding.php     ← what those three hand back
    └── Fact.php  Source.php  FlacTag.php  Level.php  KeyNotation.php
```

Read, judge, emit — three verbs, three classes, and each one testable without the other two.

### `tools/lib/Php/`, and why the emitter stopped writing strings

`EntryWriter` used to be a heredoc with `%s` holes and a `sprintf` per fragment. That made a class
name a string and an enum case `'MusicalKey::' . $key->name` — a spelling nothing checked, in the
one file whose failure mode is a `data/releases.php` that will not parse. It is now a small
expression tree, which is the same objection the markup tree answers for HTML, answered for PHP: the
emitter composes values and one renderer writes the syntax, so `Genre::Dubstep` comes out of a real
`Genre` and a case that does not exist cannot be written down.

**`var_export()` on the whole `Release` was the obvious version of that idea, and it is the wrong
output.** It works, and PHP already emits `\NeuroSYS\Model\Release::__set_state(array(…))` for it —
given a `__set_state()` on each class it would round-trip exactly. But `ill.` comes out as **191
lines against 35**, with `Collection`'s private `items` and its `type` string on show, every
`SoundCloudEmbed` default spelled out, and no comment anywhere. `data/releases.php` is ordered and
edited by hand, and the three things it most needs are the share-id comments, the named arguments,
and the commented-out lines for facts that do not exist yet — none of which an exported object can
carry. It would also mean adding a magic method to eight shipped `src/` classes to serve a tool that
never runs on the server.

So the tree emits what a person would have typed, and `var_export()` does the part it is genuinely
good at: quoting the leaves, in `Php\Value`. Two things about it are worth knowing, because both
were bugs before they were tests — **`var_export(null)` is `NULL` in capitals**, alone among the
literals it emits and unlike every null in the data file; and an enum exports fully qualified, where
the file imports its enums and writes `Genre::Dubstep`.

The writer knows every class it named, so `stage-release` prints the `use` lines
`data/releases.php` is missing. That is not decoration either: the entry is written with short
names, `Section` and `ProductionTime` are new, and no entry written before them imports anything
from `Model\Production`.

**None of it is under `src/`**, for two mechanical reasons:

- `deploy.sh` rsyncs `src/` with `--delete`. Anything there **ships to Strato**, where a
  release-authoring tool has no business being.
- `phpunit.xml.dist` names `src` as its coverage source, so a class there would join the site's
  coverage figure and need tests written against shell-outs to `metaflac` and `ffprobe`.

An earlier draft of this page gave a third reason, that the verify script bans heredocs under
`src/`. That was never accurate — the check matches `<<<'?HTML` and inline markup literals — and it
is moot now: `EntryWriter` has no heredoc left. The two reasons above are decisive on their own.

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

## Phase 3 — the `.flp` itself

The section this replaces was called *Path to the `.flp`* and described the reader as future work.
It exists now, in `tools/lib/Flp/`, and `--project` is how a folder is told where to find one:

```bash
php tools/stage-release.php ~/Music/neuro.SYS/releases/ill \
    --project "~/…/neuro.SYS PROJECTS/who are u EP/ill (140 d#min skrillie dubstep).zip"
```

The flag is needed more often than not, and that is a fact about how projects are stored rather
than a shortcoming: a `.flp` references its samples by absolute path, so projects live together
under `neuro.SYS PROJECTS/` and are kept zipped, while releases live under `Music/neuro.SYS/`. The
tool looks in the release folder first and reads a project out of a zip without unpacking it.

### What it changed about the report

Three of the six facts now come from a rung above the tags:

```
  title    'ill.'                       FLAC TITLE tag
  slug     'ill'                        derived from the title
  bpm      140                          FL project tempo
  key      D# Minor                     FL piano roll key lock
  genre    Dubstep                      FL project genre
```

**The ordering is a fact about the chain, not a preference.** This page already said it — *FL Studio
writes the tags this reads* — and the projects bear it out exactly: `alien house.flp` carries the
genre `bass house?`, which is the same free text that reaches `GENRE` and the same reason `Genre`
has no fallback. A project is not a rival answer to what the tag says; it is what the tag was
written from.

**The title is the exception**, and it is the rule stated properly. Bpm, key and genre are facts
about the *music*, which the project defines and the export copies. A title is a fact about the
*release*: `ill.`'s project is called `ill`, because that is a working name, and the trailing dot is
a decision taken at export. So the tag wins and the project is the fallback for a master that was
never tagged.

### The checks it made possible

These are worth more than the facts, on the same reasoning the rest of the preflight is: they run
before an upload.

- **The project's tempo, genre and key against the tags exported from it.** A tag is written once,
  at export; the project keeps moving afterwards. A project at 150 beside a FLAC tagged 140 is a
  master that was exported before the last change, which nothing else in the folder can notice.
- **A project that sets no key lock**, which is a WARN naming what its notes suggest instead. This
  is not hypothetical: `hello world!` is a shipped release whose piano roll locks nothing, so its
  key comes from the tag and the estimate only corroborates it.
- **A project that parses but carries no tempo**, which is a FAIL rather than a release without a
  tempo — see below.

### The key has three rungs, and only two are readings

| Rung | Where | Coverage across seven real projects |
|---|---|---|
| the piano roll's key lock | a scale marker, `D# Minor Natural (Aeolian)` | 3 of 7 |
| the FLAC `INITIALKEY` tag | written at export | both shipped releases |
| the filename | `140 D#Min ill …` | the older convention |

`KeyEstimate` sits under all three and is **not a rung**. It totals each pitch class by how long it
sounds and correlates that against all 24 major and minor profiles, which agreed with three of the
four projects whose key is independently known — good enough to say out loud to a person, and not
good enough to write into `data/releases.php`. So it reaches the report as a sentence in a WARN and
never as a value. `Source` has no case for it, deliberately: that enum records where a fact *came
from*, and a number nothing wrote down did not come from anywhere.

### One byte, and why the reader asserts nothing about it

FL 26 writes a preamble event whose id sits in the four-byte band and which is **one byte wide**.
Read by the band rule it swallows the id byte of the event after it — the `FL Studio 26.1.0.5530`
string — and every event past that point is read at the wrong offset, the tempo included.

What makes it worth a paragraph is how it hides. ASCII inside UTF-16 is `char, 00, char, 00, …`, so
a parser one byte out lands on the zero high bytes and reads each character as its own well-formed
event until the string ends and the alignment comes back on its own. The file parses. It ends
*exactly* on the length `FLdt` declares. It is simply missing whatever sat in the desynchronised
stretch.

An earlier draft of `FlpFile` asserted the walk landed on that boundary and called it the guard. It
is not one — the bug it was written for lands there too, and with the per-event bounds check already
in place the assertion could never fire at all. What guards it instead is a canary: **every FL
Studio project has a tempo**, in all seven tested across four versions, so a project that parses
without one is a bad read rather than a project without a tempo, and the preflight says so in those
words.

### What it still cannot know

The same three, for the same reasons: the description is editorial, the HiDrive share ids are minted
by hand, and the SoundCloud ids do not exist until the track is up. **`madeWith` is a fourth of a
different kind.** The vendor and product names — `Serum2`, `Xfer Records`, `Kilohearts`,
`Ozone Imager 2` — really are in the project, length-prefixed inside each plugin's wrapper blob, but
at offsets that vary per plugin and per version. So the scan finds the real names and some wreckage
beside them, and the tool emits the list **commented out** for the author to trim, exactly as it
already does for `description`. Nothing guessed reaches the site.

One fact is still sitting in the folder with nowhere to go: `ill.`'s FLAC carries `DATE=2026-09-04`,
and `Release` has no date field, so the catalogue's newest-first ordering is array order maintained
by hand. Adding one was considered again here and again declined — note that the project's *own*
creation date could not have supplied it anyway: four of the seven projects share one timestamp,
because saving a copy carries the original's date along with it.
