# Adding and updating releases

Everything lives in `data/releases.php`. The router reads this file on every request — no cache to bust, no rebuild needed.

## Adding a release

Add a new entry to the array in `data/releases.php`. Each entry is a typed `Release` object:

```php
'your-slug' => new Release(
    title:               'track title',
    bpm:                 140,
    key:                 MusicalKey::FSharpMajor,
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

The slug becomes the URL: `/releases/your-slug`.

Omit a format from the `Collection` (or pass an empty string `''` as the URL) to hide that download card — useful if e.g. stems aren't ready yet.

Available formats: `ReleaseFormat::FLAC`, `MP3`, `WAV`, `AIFF`, `STEMS`, `OGG`.

Available keys: all 24 standard Western keys as `MusicalKey::CMajor`, `CSharpMajor`, … `BMinor` (see `src/NeuroSYS/Model/MusicalKey.php` for the full list).

## Getting HiDrive direct-download links

1. Upload the file to HiDrive.
2. Right-click → **Share** → **Direct download link**.
3. Paste the URL into the relevant `Format` constructor or `coverSrc` argument.

These links bypass the router entirely once clicked — the 303 redirect sends the browser straight to HiDrive.

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

## Checklist (hello world! — target 01.07.2026)

- [ ] Finish cover art + logo
- [ ] Upload cover to HiDrive, grab direct-download link → `coverSrc`
- [ ] Export final FLAC + MP3 + stems ZIP
- [ ] Upload audio files to HiDrive, grab direct-download links → `formats`
- [ ] Set up SoundCloud profile, upload track, grab full embed snippet → `soundcloudEmbedHtml`
- [ ] Deploy `public/` + updated `data/releases.php` to Strato
- [ ] Test all three download links live (check `data/logs/downloads.log` fills up)
- [ ] Mobile check
- [ ] Post the release
