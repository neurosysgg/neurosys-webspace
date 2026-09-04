# Brand assets and profile links

Profile links live in the footer. They are **plain hyperlinks to locally vendored icons** — nothing is requested from
SoundCloud, Spotify, Apple, YouTube, X or GitHub until a visitor actually clicks.

## Why the icons are vendored

Hot-linking an icon from a platform's own CDN (`<img src="https://…spotify.com/icon.svg">`) fires a request to that
platform **on page load**, before any interaction. Under CJEU C-40/17 (*Fashion ID*) that makes the site operator a joint
controller for the resulting data transfer and requires a consent gate — the same treatment the SoundCloud embed gets in
`ReleaseView::playerHtml()`.

Serving the file from our own origin removes the transfer entirely. Plain profile links then need no consent, no banner,
and no entry in the Datenschutzerklärung.

**Never replace a vendored file with a remote URL.** If a platform is added, download its asset and commit it.

## Adding a profile link

Paste the URL into `data/profiles.php`:

```php
Platform::Spotify->value => 'https://open.spotify.com/artist/…',
```

An empty URL hides that link — same convention as release formats. A platform whose icon isn't vendored is skipped even
if a URL is set, so nothing renders broken.

To add a platform: add a case to `src/NeuroSYS/Model/Platform.php` (label, icon path, height), vendor its asset into
`public/assets/img/brand/`, and add a row to `data/profiles.php`.

## Vendored assets and their sources

| File | Source | Licence / terms |
|---|---|---|
| `spotify.svg` | `2024-spotify-logo-icon.zip` → `Primary_Logo_White_RGB.svg`, from [developer.spotify.com/documentation/design](https://developer.spotify.com/documentation/design) | Spotify brand guidelines |
| `apple-music-badge.svg` | "Listen on Apple Music" badge (black), [Apple Services Marketing Toolbox](https://toolbox.marketingtools.apple.com/en-us/apple-music) | [Apple Music Identity Guidelines](https://apple.com/itunes/marketing-on-music/identity-guidelines.html) |
| `github.svg` | `mark-github-24.svg` from [primer/octicons](https://github.com/primer/octicons) | MIT |

Spotify's and Apple's files are committed **byte-for-byte unmodified** — both sets of terms forbid altering the marks.
Only the GitHub octicon is recoloured (to `#e8e8f0`), which its MIT licence permits.

Every vendored SVG is checked for `<script>`, event handlers and external references before committing. They are served
from our own origin, so a hostile SVG would run in our security context.

## Per-platform rules that constrain the design

- **SoundCloud** — the primary presence, so it renders first in the footer. Use the official mark from SoundCloud's press
  resources, unmodified; take a monochrome/white variant for our dark background. Note this is a *profile* link and is
  unrelated to the embedded player, which stays behind its own consent gate in `ReleaseView::playerHtml()`.
- **Spotify** — icon never below 21px (full logo never below 70px). The green mark is permitted **only** on black or
  white; on any other background use monochrome, white on dark. Our `--bg` is `#0b0c10`, not black, so the **white**
  variant is the correct one. Rendered at 24px.
- **Apple Music** — linking must use the official *"Listen on Apple Music"* badge lockup, not a square icon of your own.
  It's a wide 140.62 × 41 asset, so it renders slightly taller (30px) than the square marks to match their optical weight.
- **YouTube** — the logo or icon may be made into a link **only** when the destination is a YouTube channel. That's
  exactly our case, so it's permitted.
- **X** — use the official X mark from the brand toolkit, in black or white only; it must not be recoloured, restyled or
  redrawn. On our `--bg` that means the white variant. Don't substitute the old bird mark.
- **GitHub** — permissive for linking; use the official mark unmodified in shape.

## Outstanding: SoundCloud, YouTube and X

All three have their profile URL set in `data/profiles.php` already, but **none of their icons is vendored**, so
`ProfileRepository::all()` skips them and nothing renders broken. Each needs the same two steps:

| Platform | Get the asset from | Save as | Then |
|---|---|---|---|
| SoundCloud | <https://soundcloud.com/press> | `public/assets/img/brand/soundcloud.svg` | fill in `Platform::SoundCloud`'s `iconSrc()` |
| YouTube | <https://brand.youtube/> — JS app, not fetchable programmatically | `public/assets/img/brand/youtube.svg` | fill in `Platform::YouTube`'s `iconSrc()` |
| X | <https://about.x.com/en/who-we-are/brand-toolkit> | `public/assets/img/brand/x.svg` | fill in `Platform::X`'s `iconSrc()` |

Take the **white** variant of each — our `--bg` is dark, and all three sets of guidelines restrict recolouring. Check the
file for `<script>`, event handlers and external references before committing, as with every other vendored asset. The
links appear in the footer the moment `iconSrc()` returns a path.

Until then the footer shows **GitHub only** (Spotify and Apple Music are waiting on DistroKid delivery).

## Not covered here

The **YouTube channel itself** needs its own Impressum under § 5 DDG once it isn't purely private — monetisation,
sponsorships or affiliate links all trigger it. Add it under Customize → Basic Info. A stage name is not sufficient:
it needs the full legal name and a deliverable address. Spotify and Apple Music artist profiles have no equivalent
field; the usual approach is linking back to neurosys.gg, which carries the Impressum.
