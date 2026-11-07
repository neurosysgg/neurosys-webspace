# Adding and updating releases

Everything lives in `data/releases.json`. The site reads this file on every request — no cache to bust, no rebuild needed.

## Start from the folder

If the release has been exported to `~/Music/neuro.SYS/releases/<name>/`, most of this page is already
done for you — six of the nine facts are sitting in the master's tags and in which files exist:

```bash
php tools/stage-release.php ~/Music/neuro.SYS/releases/ill
```

It prints a report of what it derived and where each fact came from, checks the folder is fit to
upload, and then writes the entry first into `data/releases.json` (`--print` prints it instead). It
writes nothing while a check fails, which is deliberate: a share link is bound to the bytes it was
minted for, so a folder worth fixing is one to fix *before* uploading — and nothing over a slug the
file already has, since that entry holds what was typed by hand. What it cannot derive — the
description, the share ids and the SoundCloud ids — it leaves as the staged states described further
down, and lists on stderr, naming the file each share id is for. See [authoring.md](authoring.md).

The rest of this page is the manual version, and what to do with the three facts the folder cannot
know.

## Adding a release

Add a new entry to the object in `data/releases.json`, keyed by its slug. `ReleaseCodec` reads each
one into a typed `Release`:

```json
"your-slug": {
    "title": "track title",
    "bpm": 140,
    "key": "F# Major",
    "genre": "Dubstep",
    "description": {"en": "card + page", "de": "Karte + Seite"},
    "cover": "J2FXbB70A",
    "formats": [
        {"type": "flac", "link": "BXRsy9S7d"},
        {"type": "mp3", "link": "CPJy7AVIu"},
        {"type": "stems", "link": "D2PUDjoII"}
    ],
    "embed": {"trackId": 2394077313, "permalink": "ill", "secretToken": "s-dIMAqki109G"},
    "arrangement": {"ppq": 96, "sections": [{"label": "INTRO", "tick": 0}, {"label": "DROP", "tick": 12288}]},
    "timeSpent": 216420,
    "madeWith": ["Serum 2", "Vocodex"]
}
```

The slug becomes the URL: `/releases/your-slug`. Entries render in file order, so put the newest release first.

**Write it the way the codec does.** The real file has four-space indents and one key per line, and
the verify script decodes and re-encodes it and fails on any byte of difference — so the compact
lines above are for reading, not for pasting in as they stand. Only source fields are stored: the
HiDrive URL, the rendered time and the rest are computed. A field the file cannot accept is refused
with the file and its path — `data/releases.json: ill.formats[2].type: …`.

- `description` is one plain string, the same in every language, or `{"en": …, "de": …}`. English is
  required; German may be left out and falls back to English. It is shown on the card and the page
  — see [language.md](../phpanta/docs/language.md).
- `arrangement` is the `.flp`'s, written by `stage-release --project`. A section's `kind` is
  optional — left out, it is derived from the label (`intro`, `build`, `drop`, `break`, `bridge`,
  `switch`, `builddown`, `outro`).
- `timeSpent` is seconds. `madeWith` is typed by hand.
- `embed` — see below; leave it out to hide the player.

Leave a format out of `formats` to hide that download card. Giving it a `null` link is different — `{"type": "flac", "link": null}` — the card still renders, but clicking it returns a plain-text 503 ("This file isn't available yet") instead of redirecting. That's the useful state while a release is staged and the HiDrive links don't exist yet. The same goes for `cover`: leave it out and the page shows the placeholder SVG.

Available formats: `flac`, `mp3`, `wav`, `aiff`, `stems`, `ogg`.

Available keys: all 24 standard Western keys by their backing value, `"C Major"`, `"C# Major"`, … `"B Minor"` (see `src/NeuroSYS/Model/MusicalKey.php` for the full list).

Available genres: `Dubstep`, `Riddim`, `Halftime`, `Drum & Bass`, `Neurofunk`, `Trap`, `Future Bass`, `Techno`, `House`, `Ambient`, `Experimental` (see `src/NeuroSYS/Model/Genre.php` — add a case when you need one).

## Titles and the accent mark

`ReleaseView` splits a trailing `!`, `.` or `?` off the title and wraps it in `.bang` so it picks up the accent colour — `hello world!` renders as `hello world` + a coloured `!`, `ill.` as `ill` + a coloured `.`. A title without trailing punctuation renders plain. Nothing to configure.

## Getting HiDrive direct-download links

1. Upload the file to HiDrive (see *Uploading* below).
2. Right-click → **Share** → **Direct download link**.
3. Take the **`id=` value off the end** of that URL and write it as the `link` — not the whole URL.

```
https://my.hidrive.com/api/sharelink/download?id=BXRsy9S7d
                                                 └── this bit ──┘

{"type": "flac", "link": "BXRsy9S7d"}
```

`HiDriveLink` builds the endpoint back around the id, so the URL shape lives in one place
(`src/NeuroSYS/Model/Link/HiDriveLink.php`) instead of being repeated per file. Ids are exactly 9 alphanumeric
characters and anything else throws when `data/releases.json` is read — a truncated id fails immediately and
loudly rather than 404ing from HiDrive when someone clicks. If HiDrive ever changes the id format, widen
`ID_PATTERN` in that class.

**Take the *direct download* link, not the share page link.** HiDrive's UI offers both and they look alike, but
the share page (`https://my.hidrive.com/share/…`) serves an HTML viewer, not the file — it works as neither an
`<img src>` nor a download redirect.

These links bypass the router entirely once clicked — the 303 redirect sends the browser straight to HiDrive.

**Share links have to be made in the web UI.** They're a HiDrive service feature, not a filesystem one, so SFTP can't mint them.
The REST API (`https://api.hidrive.strato.com/2.1/sharelink`) can, but it needs OAuth2 client credentials that STRATO issues manually —
*up to 72 hours* after you register an app at <https://developer.hidrive.com/get-api-key/>. Worth doing once if you want this automated
for future releases; not a same-day option.

## Uploading to HiDrive

SSH key auth is set up (`~/.ssh/id_ed25519_hidrive`, `Host hidrive` in `~/.ssh/config`). The account is a restricted shell — no
interactive login — but sftp, scp, rsync and git all work over it. Release files live under
`/users/ecki590/neuro.SYS tertiary backup/Releases/<title>/`.

```bash
rsync -rt --partial --info=progress2 -e ssh \
    ~/Music/neuro.SYS/releases/ill/ \
    "hidrive:/users/ecki590/neuro.SYS tertiary backup/Releases/ill/"
```

Name the uploaded files the way the release is titled — `ill..flac`, `ill. cover.png` — matching the `hello world!` folder.

## Cover art

Upload the cover image to HiDrive and grab a direct-download link (same process as audio files). Write its share id as `cover`. Leave `cover` out entirely and the view renders the placeholder SVG; it also falls back to the placeholder if a configured image fails to load.

Recommended: 1400×1400 px minimum, square, JPEG.

## SoundCloud embed

The embed HTML is **generated**, not stored — `SoundCloudEmbed` builds it from three ids. There are two ways to
get them.

### The tool, once an app is registered

```bash
php tools/release-track.php ~/Music/neuro.SYS/releases/ill --upload
```

It uploads the track **private** and writes `trackId`, `permalink` and `secretToken` into the release's entry in
`data/releases.json`, touching nothing else — or writes the whole entry first if there is none yet; `--print`
prints it instead. No embed dialog, no snippet to read. It sends nothing without `--upload`, and
there is no flag that can make a track public: that stays the step below, taken on the day. `--authorize` does
the one browser round trip it needs, once per machine. It wants three environment variables —
`NEUROSYS_SOUNDCLOUD_CLIENT_ID`, `_CLIENT_SECRET` and `_REDIRECT_URI` — which come from an app registered with
SoundCloud. **None of that is set up on this machine** (checked 2026-09-10: the variables are unset and there
is no token store at `~/.config/neurosys/soundcloud.json`), and whether an app exists on SoundCloud's side is
recorded nowhere here. Until the three are set and `--authorize` has run, the path below is the one that works.
See [authoring.md](authoring.md#uploading-the-track).

### By hand, from the embed snippet

You only need to dig them out of SoundCloud's embed snippet once, then throw the snippet away.

1. Upload the track to SoundCloud.
2. **Share → Embed** — copy the snippet somewhere scratch and read three things out of it:

   | Argument | Where it is in the snippet | Example |
   |---|---|---|
   | `trackId` | the digits in the iframe `src`, after `soundcloud%3Atracks%3A` | `2394077313` |
   | `permalink` | the track's own slug in the attribution link, `soundcloud.com/neurosysgg/<permalink>` | `"ill"` |
   | `secretToken` | `secret_token%3D` in the `src`, or the trailing `/s-…` on the attribution link | `"s-dIMAqki109G"` |

3. Write them as the entry's `embed` in `data/releases.json`. Leave `embed` out entirely to hide the player.

`secretToken` only exists while a track is **private or scheduled**. Leave it out for a track that was public from
the start. A token grabbed before release keeps working after the track goes public, so there's no need to re-grab
it on release day — verified with `ill.`, which went public on 04.09.2026 on its pre-release token.

The player is deliberately **not** loaded until the visitor clicks the consent gate — nothing is requested from
SoundCloud on page load (see `docs/branding.md` for why). Autoplay is on, because clicking *Load player* is the
request to play.

Two things are fixed site-wide inside `SoundCloudEmbed` rather than per release: the artist handle
(`neurosysgg`) and the player accent `#9e55e6` — a lighter purple than the site's `--accent: #6a00ff`, which
reads as near-black against SoundCloud's own dark player. Change them there, not in `data/releases.json`.

Layout and toggles are enums with defaults you shouldn't normally need, and the file leaves them out
at their defaults:

```json
"embed": {
    "trackId": 2394077313,
    "permalink": "ill",
    "style": "classic",
    "options": ["show_user"]
}
```

`style` is `visual` (the default, 300px) or `classic` (166px); `options` lists what is on, and
everything else is off. Default options are `auto_play`, `show_comments`, `show_user`,
`show_teaser`. The full set is in `src/NeuroSYS/Model/Embed/SoundCloudOption.php`.

### Another platform

Write a class implementing `Embed` (`platform()` + `height()` + `toElement(string $title)`) next to
`SoundCloudEmbed`, and add the platform to the `Platform` enum if it isn't there. Nothing in `Release` or
`ReleaseView` needs to change — the consent gate names the provider from `platform()->displayName()`.

### Not the same thing: the profile player

`SoundCloudProfileEmbed` is the home page's player — the whole account's latest tracks rather than one
release. **It is not something a release can hold**, and it deliberately does not implement `Embed`: a
provider is SoundCloud versus somebody else, whereas one track versus the whole account is a different axis
entirely, and `Release::$embed` is typed for the first one. There is nothing to configure per release, and
nothing here to edit when you add one — SoundCloud resolves the profile URL, so a new track appears in it on
its own.

## MIDI for the remix package

`php tools/extract-midi.php <folder|.flp|.zip>` writes the project's notes as a standard MIDI file,
so a remix package does not need FL's own export dialog in the Windows VM.

```bash
php tools/extract-midi.php ~/"neuro.SYS PROJECTS/who are u EP/ill (140 d#min skrillie dubstep).zip" --out "ill MIDI.mid"
```

It takes a release folder, a loose `.flp`, or the zip a project is usually kept in — the same
discovery `stage-release` uses — and prints its report to stderr, because the file itself is binary.
The default is the **arrangement**: the playlist expanded, one track per rack channel, named for
that channel. `--patterns` writes every pattern on its own track instead, at the ticks the pattern
holds, which is the export to hand someone who wants a chord progression rather than a song.

Two things to expect, both of them properties of the project rather than of the tool:

- **Track names are whatever the channels are called in FL.** `hello world!`'s read
  `[Serum 2] SYN phat saw stack`; `ill`'s read `Serum 2 #5`, because those channels were never
  renamed. Renaming them in the rack and re-running is the fix, and it is worth doing before
  shipping a package — the names are the only labelling a remixer gets.
- **It is checked against FL's own export, not assumed to match it.** Where the two differ it is
  written down rather than smoothed over; see [tooling.md](tooling.md#extract-midi) for the
  one-tick question and the two extra tracks, and `ExtractMidi::DIVERGENCE` for the decision.

## After editing releases.json

Deploy with `./deploy.sh`, which rsyncs `data/` along with everything else (skipping only `logs/` and
the credential files). `php tools/push-update.php` does **not** carry it: a push writes only
`public/`, `src/` and `autoload.php`, and `data/` is deliberately not one of its roots. See
[deployment.md](deployment.md).

## Release checklist

Built from the ones `ill.` and `hello world!` shipped with — both are kept in
[history/releases.md](history/releases.md).

- [ ] Master, and export FLAC / WAV / MP3 from FL — the preflight checks they agree with the FLAC's rate
      and depth, and with each other's duration
- [ ] `php tools/extract-midi.php` into `REMIX PACKAGE/` **before** zipping it — once the zip's share link is
      live, adding a file means a re-upload and possibly a new link
- [ ] Build the stems package → `<bpm> <key> <title> remix package.zip` (`REMIX PACKAGE/stems/` + the MIDI)
- [ ] Export web covers → `web/<title> cover.png` (2048²) and `web/<title> cover.jpg` (1400²)
- [ ] `php tools/stage-release.php <folder> --check` — clean before anything is uploaded
- [ ] Upload the folder to HiDrive over SFTP (see [Uploading to HiDrive](#uploading-to-hidrive)), verified
      byte-for-byte
- [ ] **Create share links in the HiDrive web UI** for the cover and every format → `cover` and `formats`
- [ ] SoundCloud: upload private (`release-track --upload`, or by hand) and take the three ids → `embed`
- [ ] `php tools/stage-release.php <folder>` writes the entry; fill in what it lists on stderr — share ids,
      description, credits
- [ ] `composer verify`
- [ ] Deploy — `./deploy.sh`, because it ships `data/`
- [ ] Test every download link live — each 303s to the right HiDrive id
- [ ] Mobile check on an actual phone — no horizontal overflow at 375px, and the consent gate swaps in the
      real iframe
- [ ] Publish the track in SoundCloud's own interface on release day — the secret-token embed keeps working
- [ ] Post the release

## Outstanding

Carried over from the shipped releases' checklists, still open:

- **`ill.` — re-upload `ill..wav`.** The HiDrive copy is the old 16-bit/44.1kHz, untagged file; the folder
  holds the corrected 24/48 one, re-derived from the FLAC master (bit-identical audio). Check whether the share
  link survives an overwrite at the same path, or whether it has to be re-minted and `RVg8LBS4A` updated.
- **`ill.` — mobile check on a real phone.** 375px overflow and the consent-gate swap were checked, but never
  looked at on an actual device.
- **`hello world!` — re-upload `hello world!.flac`.** The HiDrive copy carries no `BPM` or `INITIALKEY`, and
  its cover block is still typed `image/apng`; the local file is corrected. Same share-link question as above.
- **`hello world!` — mobile check, and posting the release.** Both unticked because neither can be confirmed
  from the repo.
