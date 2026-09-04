# Adding and updating releases

Everything lives in `data/releases.php`. The router reads this file on every request — no cache to bust, no rebuild needed.

## Adding a release

Add a new entry to the array in `data/releases.php`. Each entry is a typed `Release` object:

```php
'your-slug' => new Release(
    title:       'track title',
    bpm:         140,
    key:         MusicalKey::FSharpMajor,
    genre:       Genre::Dubstep,
    description: 'debut single',         // shown on the release card + page
    cover:       new HiDriveLink('J2FXbB70A'),   // share id, see below
    formats: new Collection(Format::class)->add(
        new Format(ReleaseFormat::FLAC,  new HiDriveLink('BXRsy9S7d')),
        new Format(ReleaseFormat::MP3,   new HiDriveLink('CPJy7AVIu')),
        new Format(ReleaseFormat::STEMS, new HiDriveLink('D2PUDjoII')),
    ),
    embed: new SoundCloudEmbed(          // see below; omit the argument to hide the player
        trackId:     2394077313,
        permalink:   'ill',
        secretToken: 's-dIMAqki109G',
    ),
),
```

The slug becomes the URL: `/releases/your-slug`. Entries render in array order, so put the newest release first.

Omit a format from the `Collection` to hide that download card. Omitting just its `HiDriveLink` is different — `new Format(ReleaseFormat::FLAC)` — the card still renders, but clicking it returns a plain-text 503 ("This file isn't available yet") instead of redirecting. That's the useful state while a release is staged and the HiDrive links don't exist yet. The same goes for `cover`: leave it off and the page shows the placeholder SVG.

Available formats: `ReleaseFormat::FLAC`, `MP3`, `WAV`, `AIFF`, `STEMS`, `OGG`.

Available keys: all 24 standard Western keys as `MusicalKey::CMajor`, `CSharpMajor`, … `BMinor` (see `src/NeuroSYS/Model/MusicalKey.php` for the full list).

Available genres: `Genre::Dubstep`, `Riddim`, `Halftime`, `DrumAndBass`, `Neurofunk`, `Trap`, `FutureBass`, `Techno`, `House`, `Ambient`, `Experimental` (see `src/NeuroSYS/Model/Genre.php` — add a case when you need one).

## Titles and the accent mark

`ReleaseView` splits a trailing `!`, `.` or `?` off the title and wraps it in `.bang` so it picks up the accent colour — `hello world!` renders as `hello world` + a coloured `!`, `ill.` as `ill` + a coloured `.`. A title without trailing punctuation renders plain. Nothing to configure.

## Getting HiDrive direct-download links

1. Upload the file to HiDrive (see *Uploading* below).
2. Right-click → **Share** → **Direct download link**.
3. Take the **`id=` value off the end** of that URL and pass it to `HiDriveLink` — not the whole URL.

```
https://my.hidrive.com/api/sharelink/download?id=BXRsy9S7d
                                                 └── this bit ──┘

new HiDriveLink('BXRsy9S7d')
```

`HiDriveLink` builds the endpoint back around the id, so the URL shape lives in one place
(`src/NeuroSYS/Model/Link/HiDriveLink.php`) instead of being repeated per file. Ids are exactly 9 alphanumeric
characters and anything else throws when `data/releases.php` loads — a truncated paste fails immediately and
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

Upload the cover image to HiDrive and grab a direct-download link (same process as audio files). Pass its share id as `cover`. Leave `cover` off entirely and the view renders the placeholder SVG; it also falls back to the placeholder if a configured image fails to load.

Recommended: 1400×1400 px minimum, square, JPEG.

## SoundCloud embed

The embed HTML is **generated**, not pasted — `SoundCloudEmbed` builds it from three ids. You only need to dig
them out of SoundCloud's embed snippet once, then throw the snippet away.

1. Upload the track to SoundCloud.
2. **Share → Embed** — copy the snippet somewhere scratch and read three things out of it:

   | Argument | Where it is in the snippet | Example |
   |---|---|---|
   | `trackId` | the digits in the iframe `src`, after `soundcloud%3Atracks%3A` | `2394077313` |
   | `permalink` | the track's own slug in the attribution link, `soundcloud.com/neurosysgg/<permalink>` | `'ill'` |
   | `secretToken` | `secret_token%3D` in the `src`, or the trailing `/s-…` on the attribution link | `'s-dIMAqki109G'` |

3. Pass them as `embed:` in `data/releases.php`. Omit the `embed:` argument entirely to hide the player.

`secretToken` only exists while a track is **private or scheduled**. Leave it off for a track that was public from
the start. A token grabbed before release keeps working after the track goes public, so there's no need to re-grab
it on release day — `ill.` went public on 04.09.2026 on its original token.

The player is deliberately **not** loaded until the visitor clicks the consent gate — nothing is requested from
SoundCloud on page load (see `docs/branding.md` for why). Autoplay is on, because clicking *Load player* is the
request to play.

Two things are fixed site-wide inside `SoundCloudEmbed` rather than per release: the artist handle
(`neurosysgg`) and the player accent `#9e55e6` — a lighter purple than the site's `--accent: #6a00ff`, which
reads as near-black against SoundCloud's own dark player. Change them there, not in `data/releases.php`.

Layout and toggles are enums with defaults you shouldn't normally need:

```php
embed: new SoundCloudEmbed(
    trackId:   2394077313,
    permalink: 'ill',
    style:     SoundCloudPlayerStyle::Classic,        // Visual (default, 300px) | Classic (166px)
    options:   [SoundCloudOption::ShowUser],          // listed = on, everything else off
),
```

Default options are `AutoPlay`, `ShowComments`, `ShowUser`, `ShowTeaser`. The full set is in
`src/NeuroSYS/Model/Embed/SoundCloudOption.php`.

### Another platform

Write a class implementing `Embed` (`platform()` + `toHtml(string $title)`) next to `SoundCloudEmbed`, and add
the platform to the `Platform` enum if it isn't there. Nothing in `Release` or `ReleaseView` needs to change —
the consent gate names the provider from `platform()->displayName()`.

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
- [x] **Create share links in the HiDrive web UI** for cover + FLAC + WAV + MP3 + stems zip → `cover` and `formats`
- [x] Grab the SoundCloud track id / permalink / secret token → `embed` (a scheduled track has a secret-token embed before it goes public — `hello world!` uses one)
- [x] `bash test/basic_test.sh` — flipped `/releases/ill/flac` to 303; all checks pass
- [x] Deploy `public/` + `src/` + updated `data/releases.php` to Strato — `./deploy.sh`, 2026-09-04
- [x] Test all four download links live — all four 303 to the right HiDrive ids (logging stays off by design, see CLAUDE.md)
- [~] Mobile check — no horizontal overflow at 375px on `/`, `/releases/ill` or `/privacy`, and the consent gate swaps
      in the real iframe correctly. Not visually eyeballed; give it one look on an actual phone.
- [x] SoundCloud published 04.09.2026 — the secret-token embed still resolves after going public, no re-grab needed

## Checklist (hello world! — target 01.07.2026)

Cover, all four HiDrive links and the SoundCloud embed are populated in `data/releases.php`, and the file is deployed — so everything down to the deploy step is done. The last three are left unticked because they can't be confirmed from the repo.

- [x] Finish cover art + logo
- [x] Upload cover to HiDrive, grab direct-download link → `cover`
- [x] Export final FLAC + MP3 + stems ZIP
- [x] Upload audio files to HiDrive, grab direct-download links → `formats`
- [x] Set up SoundCloud profile, upload track, grab the embed ids → `embed`
- [x] Deploy `public/` + updated `data/releases.php` to Strato
- [x] Test all four download links live — verified 2026-09-04, each 303 resolves to the right file on HiDrive
- [ ] Mobile check
- [ ] Post the release
