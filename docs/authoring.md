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
├── Export/             ← where the audio comes from — see "Phase 4" below
├── Flp/                ← the project reader — see "Phase 3" below
├── Http/               ← the one outbound-request site — see "Phase 4" below
├── Php/                ← Expression, Value, ClassConstant, Argument, Call, Entry
├── Release/
│   ├── ReleaseFolder.php   ← reads a folder, and records where each fact came from
│   ├── ProjectFile.php     ← finds the .flp, loose or inside a zip
│   ├── Preflight.php       ← judges it
│   ├── EntryWriter.php     ← emits the data/releases.php block
│   ├── ReleasesFile.php    ← reads data/releases.php, only to say which imports it lacks
│   ├── Probe.php           ← the one metaflac/ffprobe shell-out site
│   ├── AudioStream.php  Cover.php  Finding.php     ← what those three hand back
│   └── Fact.php  Source.php  FlacTag.php  Level.php  KeyNotation.php
└── SoundCloud/         ← the upload client — see "Phase 4" below
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

### The two trees are not one tree

`Php\Expression` and `View\Html\Node` state the same contract — first line unindented, every line
after it at the caller's column, a child rendered one step deeper — and differ only in how the
caller names that column: a `string` of literal indent here, an `int` of two-space steps there.
Either form would serve either tree, which is what makes the question worth answering out loud.

**They stay two types.** Nothing anywhere holds "either kind of node", which is the same test that
makes `Support\TypedItems` a trait rather than a base class: `extends` would announce a common type
nothing wants. And a shared parent would have to live under `src/` to be reachable from both — for
the two reasons directly above, which apply to a supertype whose only second implementor is a tool
exactly as they apply to the tool. The kinship is stated in both interfaces' docblocks instead,
which is the most a language can carry across a boundary the deployment draws.

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

## Phase 4 — the track itself

The three facts a folder cannot know were never the same kind of unknown. The description is
editorial and always will be. The HiDrive share ids are minted in a web UI, and
[releases.md](releases.md) says why that cannot be automated yet. **The SoundCloud ids are the third, and
they were only unknowable because nothing here had ever uploaded anything** — so
`tools/release-track.php` does, and the entry it prints has them in it.

```bash
php tools/release-track.php ~/Music/neuro.SYS/releases/ill              # report; sends nothing
php tools/release-track.php ~/Music/neuro.SYS/releases/ill --upload     # and actually upload
php tools/release-track.php ~/Music/neuro.SYS/releases/ill --audio ~/render/ill..wav --upload
php tools/release-track.php --authorize                                 # once, per machine
```

It reads and judges the folder exactly as `stage-release` does — same `ReleaseFolder`, same
`Preflight` — because an upload is bound to the bytes it was made from the same way a share link is.
Then it reports every multipart field it would send, by name:

```
  audio    ill..wav                       51.4 MB    prepared by hand

  track[title]           ill.
  track[asset_data]      ill..wav (51.4 MB)
  track[sharing]         private
  track[permalink]       ill
  track[genre]           Dubstep
  track[artwork_data]    ill. cover.jpg (681.5 KB)

  nothing was sent — add --upload to do that.
```

**Printing the field names is the point of that block.** An API drops a field it does not recognise
rather than refusing the request over one, so a name that goes out misspelled is a track with
something quietly missing and a 201 to say it went fine. That is why `TrackField` is an enum and why
the report reads it back — and it is also why the OAuth parameter names beside it are deliberately
*not* enumerated: a misspelled `code_challenge_method` is refused in words, in a browser, before
anything has been sent. **A name is typed here when getting it wrong is silent.**

### Private, with no flag that says otherwise

Every upload is `sharing=private`, and there is no option to change it. Publishing is a decision
about a release date taken in SoundCloud's own interface once the live site is verified — the step
[releases.md](releases.md) already describes — and a flag here would put it one typo away. A test is
named for the absence, because an absence is what nobody notices has gone.

The private track's `secret_token` is what the release page's player needs before the track is
public, and it is **read back from the track where the creation response does not carry one**.
Whether it does is the sort of thing only a live account settles.

### The credentials, and where the token lives

Three environment variables — `NEUROSYS_SOUNDCLOUD_CLIENT_ID`, `_CLIENT_SECRET`, `_REDIRECT_URI` —
and all three or nothing: two thirds of a credential authenticates nothing, and being told which
ones are missing beats a 401 from the far end.

**Not `data/`, which is the only place this project keeps a secret today.** `deploy.sh` rsyncs that
directory to Strato and keeps `admin.php` and `site_auth.php` off it with an `--exclude` each — one
line per file, added by hand, and the file is deployed the day somebody forgets. The rotating OAuth
token goes to `~/.config/neurosys/soundcloud.json` at mode 0600, outside the repository, where no
`.gitignore` entry and no rsync flag is what stands between it and a webroot.

**`--authorize` exists because uploads need a user.** A `client_credentials` token belongs to nobody
and `POST /tracks` answers it with a 401, so the client authorizes against the account once in a
browser, with PKCE, and lives on refresh tokens after that. Those are single-use and rotate: the
response carrying the next one has already voided the one that was spent, so the store writes it in
the same call that spends it, to a temporary file that is renamed into place. A reader sees the old
token or the new one, never half of either.

### Where the audio comes from, and why that half refuses

`Exporter` is a port with two implementations, and they do not differ in how they render — one of
them does not render at all. They differ in **which machine the audio is on**.

`PreparedExport` hands back a file already in the folder, or the one `--audio` names. That is not
only a stand-in: every release so far was exported from FL by hand and left there, so it is an
accurate description of how the audio has always arrived. The report says so in its own column —
*prepared by hand* — because a file this tooling rendered a minute ago and a file exported last week
are not the same claim.

`FlStudioExport` throws, and the refusal is the feature. **FL Studio runs in a Windows VM**, so an
export is not a process to start but a message to another computer, and each way of sending one is a
different set of moving parts: a guest-side agent on a socket, SSH into the guest, a watched shared
folder, or the hypervisor's own guest-exec channel. Three constraints are why none of them is
obviously right:

1. **The project has to render where it lives.** A `.flp` references its samples by absolute path —
   the reason `--project` exists at all — so copying it here and rendering it here would render
   silence where a sample used to be, quietly.
2. **The render uses the project's own saved export settings.** Bit depth, tail length and format
   are decisions already taken in the project, and this tool has no business having a second
   opinion.
3. **FL opens its interface during a command-line render**, so the guest needs a logged-in session.
   A VM that boots on demand, headless, is not enough on its own.

The half that *is* settled is written down and tested: `FlStudioExport::commandLine()` builds the
`FL64.exe /R /E<format> <project>` invocation from Image-Line's manual, as an argument list rather
than a string, because a path with a space in it is the normal case here. It has never been run,
which is the one thing in `tools/` that is documentation rather than observation, and it is labelled
as such.

There is a wine prefix on this machine with FL Studio 2025 in it, and it is **not** a shortcut. It
is not the install the projects were made in, so a render out of it is a different plugin set and a
different result — and anything that looked like it worked would be the worst outcome available.

### Where the tests stop, and why

`SoundCloudTest` answers every request from an array, because `Http\Transport` is an interface and
`Client` takes one. No app is registered yet, so nothing has ever been uploaded; what is worth
pinning is what goes out — the URL, the `OAuth` scheme, every field under its declared name, and the
order in which a rotated refresh token reaches disk.

`ReleaseTrackTest` stops where `ReleaseFolderTest` stops. The command's uploading branch runs only
on a folder that passes its preflight, and passing it means real audio that `metaflac` and `ffprobe`
can read — not something a unit test should reach for, and the music folder is not in the repo. So
the branch below it is covered where it lives, the entry is covered at `EntryWriter` where it is
generated, and what is left for the command is every path that *refuses*, which is the half that
decides whether anything is sent at all.

The verify script adds two hygiene checks of its own: `curl_` appears under `tools/lib/` in exactly
one class, the way shelling out appears in exactly one class; and **nothing under `src/` makes an
outbound request at all**, which is a property the privacy policy rests on rather than an accident.
