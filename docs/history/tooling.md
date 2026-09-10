# History — the release tooling

How `stage-release`, `release-track` and the CLI layer under them were built, and what they found
wrong on the way. What is true of them now is in [../authoring.md](../authoring.md) and
[../tooling.md](../tooling.md).

## The authoring tools, phase by phase

`docs/authoring.md` was written as a sequence of phases, each one rewriting what the last had said
could not be known. The phases are recorded here; that document is now arranged by topic.

### 2026-09-05 — Phase 2: the folder before the project (`08f55ab`)

The page opened under the title *Phase 2 — generating a release from its folder*:

> The endgoal is a release built from a supplied `.flp`/zip. What exists now is the half that can be
> verified today: `tools/stage-release.php` turns a prepared folder under
> `~/Music/neuro.SYS/releases/<name>/` into the `data/releases.php` entry that releases.md
> otherwise asks a person to type, and checks the folder is fit to upload before it does.
>
> That is the right half to have built first, and not only because it is easier. FL Studio writes the
> tags this reads — every master here carries `ENCODER=FL Studio` — so the chain is
> **`.flp` → export → prepared folder → entry**. A `.flp` reader would replace the *export* step. It
> would not replace this one, and it could not be checked against anything until this one existed.

Its list of what the folder could not know had three entries, the third being the SoundCloud ids:
"they do not exist until the track is up."

### 2026-09-05 — a third reason that was never accurate (`08f55ab`)

Beside the two reasons the tooling is not under `src/`, the page once gave a third:

> An earlier draft of this page gave a third reason, that the verify script bans heredocs under
> `src/`. That was never accurate — the check matches `<<<'?HTML` and inline markup literals — and it
> is moot now: `EntryWriter` has no heredoc left. The two reasons above are decisive on their own.

### 2026-09-05 — the cover check was a string comparison (`08f55ab`)

> `Source` is the enum that pays for itself immediately. Deciding whether to warn about a cover used to
> be `$cover['from'] !== 'web/ export'` — a string on both sides of a comparison, where a typo in
> either half turned the check off in silence. It is now `$cover->isWebExport()`.

### 2026-09-05 — what was fixed in the two folders (`08f55ab`)

> Writing the preflight found four discrepancies, all since corrected **locally**. Both folders now
> report clean, and both derive every fact from a tag rather than a filename.
>
> | Was | Now |
> |---|---|
> | `ill..wav` was 16-bit/44.1kHz beside a 24-bit/48kHz FLAC, and carried no tags | re-derived from the FLAC master, bit-identical, 24/48, tagged |
> | `hello world!.flac` had no `BPM` or `INITIALKEY`; the filename was the only record | both tags written; audio verified untouched |
> | `hello world!` had no `web/` cover — its only art was embedded, and typed `image/apng` for a still | `cover.png` extracted, `web/` PNG + JPEG exported at ill.'s settings, block re-typed `image/png` |
> | `ill/STEMS/` disagreed with the `REMIX PACKAGE/stems/` inside its own zip | folder moved to match the zip, which is what ships |
>
> **The HiDrive copies are still the old bytes.** `/releases/ill/wav` will keep serving the 16-bit file
> until it is re-uploaded, and `hello world!`'s FLAC there is still the untagged one. Worth checking
> when you replace them: whether a share link survives an overwrite at the same path, or whether it has
> to be re-minted and the id in `data/releases.php` updated.

The two re-uploads are still open; they are tracked under *Outstanding* in
[../releases.md](../releases.md).

### 2026-09-05 — Phase 3: the `.flp` itself (`9e95ccc`)

> The section this replaces was called *Path to the `.flp`* and described the reader as future work.
> It exists now, in `tools/lib/Flp/`, and `--project` is how a folder is told where to find one.

and, of the report:

> Three of the six facts now come from a rung above the tags [...]
>
> **The ordering is a fact about the chain, not a preference.** This page already said it — *FL Studio
> writes the tags this reads* — and the projects bear it out exactly.

### 2026-09-05 — the emitter stopped writing strings (`9e95ccc`)

> `EntryWriter` used to be a heredoc with `%s` holes and a `sprintf` per fragment. That made a class
> name a string and an enum case `'MusicalKey::' . $key->name` — a spelling nothing checked, in the
> one file whose failure mode is a `data/releases.php` that will not parse. It is now a small
> expression tree [...]

The two `var_export()` traps — `NULL` in capitals, and an enum exported fully qualified — "were bugs
before they were tests".

### 2026-09-05 — the walk-ends-on-`FLdt` assertion (`9e95ccc`)

> An earlier draft of `FlpFile` asserted the walk landed on that boundary and called it the guard. It
> is not one — the bug it was written for lands there too, and with the per-event bounds check already
> in place the assertion could never fire at all.

### 2026-09-06 — an empty zip read as an OK (`15397cd`)

A stems zip that opens and holds no files is a FAIL. "It used to read as an OK, because an empty zip
has no root to look for a loose folder under."

### 2026-09-06 — Phase 4: the track itself (`7f52764`)

> The three facts a folder cannot know were never the same kind of unknown. The description is
> editorial and always will be. The HiDrive share ids are minted in a web UI, and releases.md says
> why that cannot be automated yet. **The SoundCloud ids are the third, and they were only
> unknowable because nothing here had ever uploaded anything** — so `tools/release-track.php` does,
> and the entry it prints has them in it.

## The CLI layer

### 2026-09-05 — namespaced functions first (`08f55ab`)

> `phpcs` holds `tools/` to PSR-12, where a class-like symbol needs a namespace *and* a file of its
> own — which is why this started as namespaced functions with documented array shapes, and why
> one-class-per-file stopped being a cost the moment there was a set of commands to share the loader.

### 2026-09-05 — the hand-rolled argv parsers (`08f55ab`)

> Both hand-rolled parsers this replaced dropped an unrecognised flag in silence, which for
> `merge-coverage` meant a mistyped `--clover` reported success and wrote no report. `getopt()` is
> still not the answer, for the reason the old code gave when it declined it [...]

The same fault in the three Node build tools is recorded in [frontend.md](frontend.md).

### 2026-09-06 — `FolderReport` (`15397cd`)

`stage-release` and `release-track` are the same command up to their last step, "so four blocks were
written twice and `reportFindings()` was byte-identical in both files."

## The SoundCloud client

### 2026-09-06 — reading keys and bodies by string (`c8bd783`)

- `UploadedTrack`, of a misspelled `permalink_url` reading as an empty string: "`UploadedTrack`'s
  docblock had said exactly that for as long as it read the five keys as literals."
- `JsonBody`'s two readers answer `''` and `0` for a key that is absent or wrongly typed, "which is
  what the nine hand-written `is_string($body['x'] ?? null)` reads it replaced each did for
  themselves."
- A request's body is a `Collection<FormField>` "including the token exchange, which took an
  `array<string, string>` and looped it into one inside `Client`."
- `Attempt`: "It was a `string $what` threaded through two private methods, and
  `SoundCloudException::refused()` had to describe the format it wanted in prose — which is what a
  missing type looks like."

### 2026-09 — the one address nothing checked

Of `Url`: "The site checks every address it emits — `Location`, `CspHost`, `Element`'s scheme
allowlist — and this was the one with nothing looking at it, on the one request that carries a client
secret and a rotating refresh token."

## `extract-midi`

### 2026-09-06 — why it was written (`885f4a7`)

> Written because a remix package wants MIDI and the only way to get it was FL's own export dialog in
> the Windows VM — which is why `hello world!`'s package has one and `ill`'s does not.

## From the code comments

*Moved out of comments under `tools/` and `test/` when those were brought to the present tense.
Quoted as they stood; an ellipsis marks where a sentence ran on into the rule it supported, which
stays in the code.*

### 2026-09-05 — an apostrophe in a title (`9e95ccc`)

From `ReleaseFolderTest`:

> one with an apostrophe in it used to be a data file that would not parse.

### 2026-09-05 — provenance keyed by strings (`08f55ab`)

> The report used to key its provenance by field-name strings…

### 2026-09-06 — `--clover=` with nothing after it (`15397cd`)

From `CliTest` and `Cli/Input.php`:

> `--clover=` stored an empty string, so `has()` said the flag was given and `value()` handed the
> empty path on — `merge-coverage` then died inside a report writer with a stack trace rather than
> here with a sentence.

> Only one of them used to reach this: the other stored an empty string, `has()` said the flag was
> given, and `merge-coverage` handed the empty path to a report writer that died with a stack trace.

### 2026-09-06 — an `unpack()` per offset (`15397cd`)

From the `.flp` reader:

> It used to `unpack('P', substr(…))` at each of those offsets — two allocations a byte…

### 2026-09-06 — an empty zip read as an OK (`15397cd`)

From `Release/Preflight.php`:

> It used to reach the count below and read as an OK — `0 files in the zip, with no loose folder to
> disagree`…

### 2026-09-06 — pending arguments rendered anyway (`15397cd`)

From `ReleaseFolderTest` and `Release/EntryWriter.php`:

> Both used to render, and neither result was a call anybody meant. `Argument::comment()` came out as
> `new Format('a', , 'b')` … The commented-out one came out as `new Format('a', b: 'b')`, which
> **parses**

### 2026-09-06 — the embed line was a placeholder, and its names a list (`7f52764`)

From `Release/EntryWriter.php`:

> The line that used to be a placeholder, written out because there are now ids for it.

> A `list<string>` before this, which meant the three names existed twice…

### 2026-09-06 — the response keys were literals (`c8bd783`)

From `SoundCloud/UploadedTrack.php`:

> This paragraph said exactly that while the five keys below were still string literals; the enum is
> it being acted on.

### 2026-09-07 — `--rotate` with a file named fell through to a full staging run (`8e60bbf`)

From `Command/StageDemo.php` and its test:

> `--rotate` used to be reachable only when no file was named, so `--rotate v4.flac` matched neither
> branch and fell through to a full staging run: every mix transcoded again, a *new* password
> minted, and a whole new entry printed

### 2026-09-07 — the MIDI writer's three slips (`ebf98f0`)

From `MidiTest`, `Midi/` and `Command/ExtractMidi.php`:

> a tempo slow enough to need the fourth used to lose it silently — 3 BPM writing itself as roughly
> 18.6 … every other value this package cannot hold throws and this one was the exception.

> …and used to spell the type as a bare `0x2F` — the one meta type in that file not named…

> Both used to increment one counter under a line reading 'clamped to 127'…
