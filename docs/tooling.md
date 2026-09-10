# Tooling — the commands under `tools/`

Nothing here runs on the server. `tools/` is the development side of the repository: the commands
that stage a release or a demo, push a deploy, call the API and merge coverage, and the library
under them in `tools/lib/`. What each command is *for* is documented where it is used —
[authoring.md](authoring.md) for `stage-release` and `release-track`, [demos.md](demos.md) for
`stage-demo`, [deployment.md](deployment.md) for `push-update` and `api`, [testing.md](testing.md)
for `merge-coverage`, [releases.md](releases.md) for `extract-midi`. This page is how they are built.
How they got that way is in [history/tooling.md](history/tooling.md).

## The commands

`tools/` holds seven commands and two things that are not. `stage-release`, `stage-demo`,
`release-track`, `extract-midi`, `push-update`, `api` and `merge-coverage` implement
`NeuroSYS\Tool\Cli\Command` — a name, a usage line, the `Option`s it accepts, and a `run()`
returning an `ExitCode`. `dev-router.php` and `coverage-prepend.php` implement nothing, because PHP
loads them itself: one is handed to `php -S` and one is an `auto_prepend_file`, so neither has an
argv or an exit code for an interface to attach to. Each says so in its docblock.

```
tools/
├── autoload.php          ← NeuroSYS\Tool\ → tools/lib/
├── stage-release.php     ├── release-track.php    ├── merge-coverage.php   ← entry points
├── extract-midi.php      ├── stage-demo.php       ├── push-update.php
├── api.php
└── lib/
    ├── Api/              ← the calling side: PrivateKey (the only signer in this repository),
    │                       SignedCredential, SignedRequest — one signed call, built out of the
    │                       site's own SitePath, AuthScheme and ApiAction rather than a copy
    ├── Cli/              ← Command, Option, Input, Output, ExitCode, UsageException, Runner
    ├── Command/          ← the seven commands, their option enums, and FolderReport — the report
    │                       the two that read a release folder share
    ├── Demo/             ← what puts unreleased work behind a password: Password, DemoSource,
    │                       DemoStage, DemoPreflight, DemoEntryWriter, Encoding, WaveformScan
    ├── Dsp/              ← c-µdsp in PHP: Fft, Analyze, Spectrum — the three modules the
    │                       demo waveform needs, ported with their tests
    ├── Export/           ← where a release's audio comes from: Exporter + PreparedExport and
    │                       FlStudioExport, RenderFormat, ExportedAudio/ExportSource
    ├── Flp/              ← the FL Studio project reader: FlpFile + EventId/EventWidth/Event,
    │                       Project, TimeMarker/MarkerType, ScaleNotation, KeyEstimate, Plugins
    │                       + the notes: Score, Note/PlacedNote, Pattern, Channel,
    │                       Playlist/PlaylistClip
    ├── Midi/             ← the standard MIDI file writer: MidiFile + MidiTrack/MidiNote,
    │                       TimeSignature, VariableLength
    ├── Http/             ← the only outbound requests this repo makes: Transport + CurlTransport,
    │                       Request/Response, Url, JsonBody, FormField, FilePart, OutboundHeader
    ├── Php/              ← the expression tree EntryWriter emits through, so nothing builds
    │                       PHP source from a string: Expression, Value, Call, Argument, Entry
    ├── Release/          ← ReleaseFolder, Preflight, EntryWriter, ProjectFile, ReleasesFile
    │                       + the enums they read
    ├── Update/           ← the push side: TarWriter + PackedFile.
    │                       The reader lives under src/ because the server needs it; the writer
    │                       lives here because the server must not have it
    └── SoundCloud/       ← the upload client: Client, Endpoint, Attempt, Credentials/
                            CredentialVariable/Authorization/AccessToken/OAuthCredential/TokenStore,
                            TrackUpload/TrackField/TrackKey/TokenKey/TrackSharing, UploadedTrack
```

The three Node build tools — `build-css.mjs`, `build-assets.mjs`, `build-prod.mjs` — are the same
layer in the other language, sharing `tools/build-cli.mjs`. They are described with the build in
[frontend.md](frontend.md).

## The CLI layer

**`Command::options()` is not decoration.** It is what lets `Input` refuse a flag the command never
declared: a flag dropped in silence reports success and does nothing, which for `merge-coverage`
would mean a mistyped `--clover` writing no report. `getopt()` is not the answer: it stops at the
first non-option argument, and `composer coverage` passes both of its paths first.

The verify script asserts every class under `tools/lib/` loads, the way it already does for `src/`.
Nothing else reaches them — the CLI layer is outside the coverage source and the commands are run by
hand — so a namespace disagreeing with its path would otherwise surface the first time someone ran
the tool.

## A second autoloader, and it is not optional

The site's maps `NeuroSYS\` to `src/NeuroSYS/`, and `deploy.sh` uploads `src/` with `--delete` — so a
tooling class under it would ship to Strato and join `phpunit.xml.dist`'s coverage source. Composer's
`autoload-dev` was the other candidate and was turned down for the reason `autoload.php` exists at
all: `stage-release` runs on a clone that has never seen `composer install`.

That autoloader is also what makes the typed design affordable. `phpcs` holds `tools/` to PSR-12,
where a class-like symbol needs a namespace *and* a file of its own, and one class per file costs
nothing when a set of commands shares one loader.

Two dependencies are declared rather than inherited:

- **`merge-coverage` needs `vendor/`**, and the library it needs is **`phpunit/php-code-coverage`,
  a `require-dev` entry in its own right** rather than whatever PHPUnit happens to drag in. It reads
  twelve classes out of it, `Serialization\Unserializer` among them; left transitive, a PHPUnit major
  bumping that constraint would break `composer coverage` with a class-not-found and nothing in
  `composer.json` to explain it. A direct dependency is declared or it is luck.
- **`release-track` needs `ext/curl`**, which is a `require-dev` entry for exactly that reason —
  `composer.json`'s `require` states what the *site* needs, and the site makes no outbound request at
  all. That is a property the verify script asserts, alongside the one that says curl is called in
  exactly one class, the way `Probe` is the one class that shells out.

## The two release commands, and `FolderReport`

**`FolderReport` is what `stage-release` and `release-track` have in common.** They are the same
command up to their last step — read a folder, judge it, say what is wrong with it, print an entry —
so the report is written once. It is a class rather than a trait on the test `Support\TypedItems` is
on the other side of: that is a trait because nothing anywhere holds "either kind of collection",
while these two *are* both `Command`s and `Runner` holds either one. What they share is not a kind of
command, it is a report. It is also the one place `Option` is used as a *type* rather than as a list
of cases — each command declares its own `--project` on its own enum, and the interface is what says
the two are interchangeable there. What stays at the call sites is the sentence each command prints
when a check fails, because those differ and a `string $remedy` parameter would be the mistake
`Attempt` exists to avoid.

## `release-track`

**`release-track` is `stage-release` with its last hole filled.** That command emits an entry whose
`embed:` argument is commented out, because the three SoundCloud ids do not exist until the track is
uploaded; this one uploads it and hands the resulting `SoundCloudEmbed` back to the same
`EntryWriter`, so the entry printed after an upload and the entry printed before one are the same
code with one argument different. The workflow is in [authoring.md](authoring.md#uploading-the-track);
the decisions worth knowing before touching the client:

- **It sends nothing without `--upload`.** Everything before that flag reads files on this machine;
  that flag is the step that puts one on somebody else's. Without it the command prints every
  multipart field it would send, by name, and stops.
- **Uploads are private and there is no flag that says otherwise.** Publishing is decided in
  SoundCloud's own interface on the day, which is the step [releases.md](releases.md) describes.
  A `--public` would make publishing a typo away, and that mistake has an audience.
- **The credentials are environment variables**, never `data/`. `deploy.sh` rsyncs `data/` to
  Strato and keeps secrets off it with an `--exclude` each — one line per secret, added by hand.
  The rotating OAuth token lives at `~/.config/neurosys/soundcloud.json`, outside the repo
  entirely, so no `.gitignore` entry and no rsync flag is what stands between it and a webroot.
- **The multipart field names are an enum and the OAuth parameter names are not**, and the rule is
  the one this codebase applies everywhere: a name is typed when getting it wrong is *silent*. An
  API drops a field it does not recognise, so `track[titel]` uploads the file and leaves an
  untitled track; a misspelled `code_challenge_method` is refused in words, in a browser, before
  anything is sent. `Client::authorized()` is where all three readings of `refresh_token` meet — a
  grant type, a request field name and a `TokenKey` — and a comment there says they coincide rather
  than repeat.
- **The keys a response is *read* under are enums too, for the stronger half of the same reason.**
  `TrackKey` and `TokenKey` name what comes back, where `TrackField` names what goes out — the same
  fact under the provider's two spellings (`track[permalink]` up, `permalink` down). This is the one
  place a misread name is silent *and* plausible: `permalink_url` misspelled reads as an empty
  string, and an empty string looks like a track that simply has no page. `TokenKey` holds both
  `expires_in` (SoundCloud's, a duration on the wire) and `expires_at` (ours, an instant in the
  store), because they are two forms of one fact and splitting them is how the store and the wire
  drift apart.
- **Nothing reaches into a decoded body by string.** `Response::json()` hands back a `JsonBody`,
  which takes a `BackedEnum` and nothing else — deliberately narrower than `FormField::of()`, since a
  field name is sometimes an OAuth parameter this repo leaves literal and a response key never is.
  Its two readers answer `''` and `0` for a key that is absent or wrongly typed, so no caller writes
  its own `is_string($body['x'] ?? null)`.
- **A request's headers are the site's own `Header`s**, so `Accept` is a `MimeType` that renders
  `application/json; charset=utf-8` and `Authorization` is an `OAuthCredential` — SoundCloud's
  scheme is `OAuth`, not `Bearer`, which is exactly the sort of thing that reads as a typo and so is
  written down once in a class named for it. Its body is a `Collection<FormField>`, checked at the
  boundary the way every other collection here is, the token exchange's included.
- **Its target is a `Url`**, absolute and https, parsed by `ext/uri` rather than matched by a
  pattern. `Endpoint::url()` and `Endpoint::track()` build them, and `Request` takes nothing else.
  The site checks every address it emits — `Location`, `CspHost`, `Element`'s scheme allowlist — and
  this is the one request that carries a client secret and a rotating refresh token. It is not
  `Location`: that is a header the *site* sends and it lives under `src/`, which `deploy.sh` uploads.
- **`Attempt` names what the client was doing when the API said no.** Four operations, each backed
  by the phrase its failure message reads it back as. A `string $what` parameter in its place would
  leave `SoundCloudException::refused()` describing the format it wants in prose — which is what a
  missing type looks like.

## `stage-demo`

**`stage-demo` is the one command that mints a credential, and the one that writes audio.**
`stage-release` only ever prints; this transcodes each named master into `data/demos/{slug}/` — which
is why it calls `Directory::create()` out loud rather than letting a `File` quietly make its own
parent, the rule `Support\File` states in the negative. Four things it does differently from every
other command here, each argued for in [demos.md](demos.md): it takes **many** positional arguments
because the mixes on a page are chosen rather than discovered; `--check` returns **before the
password is minted**, so a run made only to read the report cannot leave a real password on screen
that no entry matches; `--rotate` takes **no** files at all, because changing a password is one
line of an entry and restaging would rewrite every file and lose the description; and `--waveforms`
has `--rotate`'s shape rather than a staging run's: it reads the operands as **slugs**, analyses
audio that is already staged, and touches no password, no entry and no audio.

It shares `Finding` and `Level` with the release preflight and nothing else. Not `FolderReport`:
that class is built around a `ReleaseFolder` — one path, checked to be a directory, with imports
reconciled against `data/releases.php` — and none of those three is true here. What is left in
common is one `foreach` printing a level and a message, and a shared parent for that would announce
a kind these two are not.

One check in it is worth knowing because the obvious version does not work: **`ffprobe` exits 0 on a
text file named `bounce v3.flac`.** It takes the codec from the extension, reports `flac`, and
answers `N/A` for everything it would have had to decode to know — so `Probe::stream()` hands back a
well-formed `AudioStream` describing nothing, and the demo stages "successfully" with a player that
will not start. `DemoSource::isReadable()` asks for a **sample rate**, which is the thing a name
cannot supply.

## The DSP port

**`tools/lib/Dsp/` is a port rather than a design**, which is why it is the one directory here whose
layout was decided somewhere else. `Fft`, `Analyze` and `Spectrum` are `c-µdsp`'s `fft.c`,
`analyze.c` and `spectrum.c` transliterated, with that library's own tests ported beside them in
`DspTest` — known input, known output, a 1 kHz tone pinned to the same bar index it lands in over
there. Three deviations and no more, all stated on `Fft`: a `float` is a double, a length argument
is gone wherever the array already carries it, and two functions return where the C wrote into a
caller's scratch buffer, because PHP has no scratch buffer to own. **The rule that made the C
portable is kept**: nothing under `Dsp/` opens a file or decides a column count. `WaveformScan` is
the consumer, exactly as `ctui-mus`'s `audiovis-core` is over there.

The three modules with no caller here — `loudness.c`, `scope.c`, `spectrogram.c` — stay in C. The
verify script asserts every class under `tools/lib/` loads, so a port nothing calls would arrive
carrying an assertion about itself and nothing else.

**The decode is `Probe::decode()`, and it cannot be `Probe::run()`.** That one is `exec()`, which
splits stdout into lines; float bytes contain newlines as often as any other byte. `popen()` keeps
the stream whole and still hands back an exit status. What comes back is one **string** rather than
an array of samples, which is the constraint the whole design turned on: a three-minute track is 6.9
million samples, roughly 550 MB as a PHP array and 28 MB as bytes. Reading one window at a time out
of it is also precisely the contract `c-µdsp` states — the caller owns the buffer and supplies a
window.

## `extract-midi`

**`extract-midi` reads the notes, which is the half of the project the rest of the reader skips.**
`Project` answers what a release entry needs; `Score` answers what a MIDI file needs — the channels,
the patterns, and the playlist that says which pattern plays where. `php tools/extract-midi.php
<folder|.flp|.zip>` writes the playlist-expanded arrangement, one track per rack channel named for
it, and `--patterns` writes every pattern instead, at the ticks the pattern itself holds. A remix
package wants MIDI, and the alternative is FL's own export dialog in the Windows VM.

**It was built against that export rather than against a specification**, which is the only reason
its rules can be stated as measurements. Both were run over two projects and diffed note for note:
7,522 of 7,564 notes identical on one and 3,975 of 3,977 on the other, with every note's position,
pitch, velocity and channel grouping agreeing. Four things are worth knowing before touching it:

- **A playlist clip's width is version-dependent and the file does not state it** — 32 bytes in
  FL 12.4, 80 in FL 25 and 26. Read at the wrong width the arrangement is not an error, it is the
  wrong music, so `Playlist` probes it against a canary the format hands over: a clip's `u16` at
  offset 4 is 20480 in every project tested across both. The smallest width that divides the event
  *and* holds the canary at every clip wins, and a playlist that matches none comes back null
  rather than half-read. Same shape of trap as `EventWidth::NARROW_DWORD`, and the same fix.
- **A length of zero is normal, and a note of zero length is not.** 1,398 notes of one project —
  every hat and every foley hit — carry no length, because FL plays a sample for as long as the
  sample lasts. Written literally they are all silent. `Score::sounded()` gives each the gap to the
  next note on its channel, or the rest of its clip when it is the last; that rule was recovered
  from FL's export and agreed on all 1,398.
- **A note ending exactly on a clip boundary keeps its full length**, where FL shortens some by a
  tick and not others. 51 notes of the 7,564 end on a boundary, FL shortens 42 and leaves 9, and no
  rule separates them. Writing the project's own length is the deliberate choice: a rule wrong nine
  times in fifty-one is worse than none, and a tick at 96 ppq is a thousandth of a bar.
  `ExtractMidi::DIVERGENCE` is that decision, printed in the report.
- **Two tracks are written that FL omits**, on the second project. One has clips whose gain field
  reads `0.0` where every other clip reads `1.0`; the other does not and is dropped anyway. Two
  behaviours and one guess, so no rule is written — a remixer can delete a track and cannot recover
  one that was never there.

## The audio port

**The audio is a port, and the FL Studio half of it deliberately refuses.** `Exporter` has two
implementations: `PreparedExport`, which hands back a file already in the release folder — which is
how every release so far was actually made — and `FlStudioExport`, which throws. FL runs in a
**Windows VM**, so rendering is not a process to start but a message to another computer, and which
mechanism (a guest agent, SSH into the guest, a watched shared folder, the hypervisor's guest-exec
channel) is undecided. The half that *is* settled is written down and tested:
`FlStudioExport::commandLine()` builds the `FL64.exe /R /E<format>` invocation from Image-Line's
manual. The three constraints that keep any of the four options from being obviously right are in
[authoring.md](authoring.md#where-the-audio-comes-from-and-why-that-half-refuses). The wine prefix
on this machine is **not** a shortcut — it is not the install the projects were made in, and a
render out of it that looked like it worked would be the worst available outcome.
