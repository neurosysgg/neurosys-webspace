# Adding and updating releases

Everything lives in `data/releases.php`. The router reads this file on every request — no cache to bust, no rebuild needed.

## Adding a release

Add a new entry to the array in `data/releases.php`. Each entry is a typed `Release` object:

```php
'your-slug' => new Release(
    title:               'track title',
    bpm:                 140,
    key:                 MusicalKey::FSharpMajor,
    genre:               Genre::Dubstep,
    description:         'debut single',         // shown on the release card + page
    soundcloudEmbedHtml: '',                     // full embed HTML from SoundCloud (see below); '' hides the player
    coverSrc:            'https://my.hidrive.com/api/sharelink/download?id=...', // HiDrive direct-download link to cover image
    formats: new Collection(Format::class)->add(
        new Format(ReleaseFormat::FLAC,  'https://my.hidrive.com/...'),
        new Format(ReleaseFormat::MP3,   'https://my.hidrive.com/...'),
        new Format(ReleaseFormat::STEMS, 'https://my.hidrive.com/...'),
    ),
),
```

The slug becomes the URL: `/releases/your-slug`. Entries render in array order, so put the newest release first.

Omit a format from the `Collection` to hide that download card. Passing an empty string `''` as the URL is different: the card still renders, but clicking it returns a plain-text 503 ("This file isn't available yet") instead of redirecting. That's the useful state while a release is staged and the HiDrive links don't exist yet.

Available formats: `ReleaseFormat::FLAC`, `MP3`, `WAV`, `AIFF`, `STEMS`, `OGG`.

Available keys: all 24 standard Western keys as `MusicalKey::CMajor`, `CSharpMajor`, … `BMinor` (see `src/NeuroSYS/Model/MusicalKey.php` for the full list).

Available genres: `Genre::Dubstep`, `Riddim`, `Halftime`, `DrumAndBass`, `Neurofunk`, `Trap`, `Techno`, `House`, `Ambient`, `Experimental` (see `src/NeuroSYS/Model/Genre.php` — add a case when you need one).

## Titles and the accent mark

`ReleaseView` splits a trailing `!`, `.` or `?` off the title and wraps it in `.bang` so it picks up the accent colour — `hello world!` renders as `hello world` + a coloured `!`, `ill.` as `ill` + a coloured `.`. A title without trailing punctuation renders plain. Nothing to configure.

## Getting HiDrive direct-download links

1. Upload the file to HiDrive (see *Uploading* below).
2. Right-click → **Share** → **Direct download link**.
3. Paste the URL into the relevant `Format` constructor or `coverSrc` argument.

These links bypass the router entirely once clicked — the 303 redirect sends the browser straight to HiDrive. The shape is
`https://my.hidrive.com/api/sharelink/download?id=<9-char id>`.

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

Upload the cover image to HiDrive and grab a direct-download link (same process as audio files). Pass it as `coverSrc`. The view falls back to the placeholder SVG automatically if the URL is empty or the image fails to load.

Recommended: 1400×1400 px minimum, square, JPEG.

## SoundCloud embed

1. Upload the track to SoundCloud.
2. **Share → Embed** — copy the **entire iframe snippet** (not just the `src`).
3. Paste it as `soundcloudEmbedHtml` in `data/releases.php`.

The player section only renders if `soundcloudEmbedHtml` is non-empty.

## After editing releases.php

Upload it manually to `data/` on the server via the PHPStorm Remote Host panel (it's outside the standard deployment mapping — see `docs/deployment.md`).

## Checklist (ill. — releases 04.09.2026, 20:00 CEST)

The SoundCloud track is **unscheduled/private** for now — it stays that way until the live site is verified, then gets published
by hand. Source files live in `~/Music/neuro.SYS/releases/ill/`; they are uploaded to HiDrive at
`neuro.SYS tertiary backup/Releases/ill/`. `data/releases.php` is fully populated — cover, embed and all four share links — so
`/releases/ill` renders the player and every download card 303s to HiDrive.

- [x] Master + export FLAC / WAV / MP3 (24-bit/48kHz)
- [x] Build stems package → `140 D#Min ill remix package.zip` (`REMIX PACKAGE/stems/`, 143 MiB)
- [x] Prepare web covers → `web/ill. cover.png` (2048², 8-bit, 5.2 MB) and `web/ill. cover.jpg` (1400², 698 KB)
- [x] Upload all six files to HiDrive via SFTP (verified byte-for-byte; mp3 round-tripped by SHA-256)
- [~] Drop a MIDI into `REMIX PACKAGE/` and rebuild the zip — **closed for this release**: the zip is uploaded and its share
      link is live, so changing it now means a re-upload and a new link. Carry to the next release.
- [x] **Create share links in the HiDrive web UI** for cover + FLAC + WAV + MP3 + stems zip → `coverSrc` and `formats`
- [x] Grab the SoundCloud embed snippet → `soundcloudEmbedHtml` (a scheduled track has a secret-token embed before it goes public — `hello world!` uses one)
- [x] `bash test/basic_test.sh` — flipped `/releases/ill/flac` to 303; 22/22 pass
- [x] Deploy `public/` + `src/` + updated `data/releases.php` to Strato — `./deploy.sh`, 2026-09-04
- [x] Test all four download links live — all four 303 to the right HiDrive ids (logging stays off by design, see CLAUDE.md)
- [~] Mobile check — no horizontal overflow at 375px on `/`, `/releases/ill` or `/privacy`, and the consent gate swaps
      in the real iframe correctly. Not visually eyeballed; give it one look on an actual phone.
- [x] SoundCloud published 04.09.2026 — the secret-token embed still resolves after going public, no re-grab needed

## Checklist (hello world! — target 01.07.2026)

Cover, all four HiDrive links and the SoundCloud embed are populated in `data/releases.php`, and the file is deployed — so everything down to the deploy step is done. The last three are left unticked because they can't be confirmed from the repo.

- [x] Finish cover art + logo
- [x] Upload cover to HiDrive, grab direct-download link → `coverSrc`
- [x] Export final FLAC + MP3 + stems ZIP
- [x] Upload audio files to HiDrive, grab direct-download links → `formats`
- [x] Set up SoundCloud profile, upload track, grab full embed snippet → `soundcloudEmbedHtml`
- [x] Deploy `public/` + updated `data/releases.php` to Strato
- [x] Test all four download links live — verified 2026-09-04, each 303 resolves to the right file on HiDrive
- [ ] Mobile check
- [ ] Post the release
