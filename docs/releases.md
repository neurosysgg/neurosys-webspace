# Adding and updating releases

Everything lives in `data/releases.php`. The router reads this file on every request — no cache to bust, no rebuild needed.

## Adding a release

Add a new entry to the array in `data/releases.php`:

```php
'your-slug' => [
    'title'       => 'track title',
    'bpm'         => 140,
    'key'         => 'F# major',
    'description' => 'debut single',    // shown on the release card + page
    'soundcloud'  => '',                // SoundCloud embed src, or '' to hide the player
    'formats'     => [
        'flac'  => 'https://hidrive.ionos.com/...',
        'mp3'   => 'https://hidrive.ionos.com/...',
        'stems' => 'https://hidrive.ionos.com/...',
    ],
],
```

The slug becomes the URL: `/releases/your-slug/(index)`.

Leave a format's URL as `''` to hide that download card entirely — useful if e.g. stems aren't ready yet.

## Getting HiDrive direct-download links

1. Upload the file to HiDrive.
2. Right-click → **Share** → **Direct download link**.
3. Paste the URL into the relevant `formats` entry.

These links bypass the router entirely once clicked — the 303 redirect sends the browser straight to HiDrive.

## Cover art

Drop `{slug}-cover.jpg` into `public/assets/img/`. The template tries `/assets/img/{slug}-cover.jpg` and falls back to the placeholder SVG automatically via `onerror`.

Recommended: 1400×1400 px minimum, square, JPEG.

## SoundCloud embed

1. Upload the track to SoundCloud.
2. **Share → Embed** — copy just the `src` attribute from the iframe snippet.
3. Paste it into `soundcloud` in `data/releases.php`.

The player section only renders if `soundcloud` is non-empty.

## Checklist (hello world! — target 01.07.2026)

- [ ] Finish cover art + logo
- [ ] Drop `hello-world-cover.jpg` into `public/assets/img/`
- [ ] Export final FLAC + MP3 + stems ZIP
- [ ] Upload files to HiDrive, grab direct-download links
- [ ] Fill in `formats` URLs in `data/releases.php`
- [ ] Set up SoundCloud profile, upload track, grab embed src
- [ ] Fill in `soundcloud` in `data/releases.php`
- [ ] Deploy `public/` + updated `data/releases.php` to Strato
- [ ] Test all three download links live (check `data/logs/downloads.log` fills up)
- [ ] Mobile check
- [ ] Post the release
