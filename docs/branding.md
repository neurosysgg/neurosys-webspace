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

An empty URL hides that link — same convention as release formats. All six platforms have their icon vendored, so a
URL is the only thing that decides whether a link renders.

To add a platform: add a case to `src/NeuroSYS/Model/Platform.php` (label, icon path, height), vendor its asset into
`public/assets/img/brand/`, and add a row to `data/profiles.php`.

## Vendored assets and their sources

| File | Source | Licence / terms |
|---|---|---|
| `spotify.svg` | `2024-spotify-logo-icon.zip` → `Primary_Logo_White_RGB.svg`, from [developer.spotify.com/documentation/design](https://developer.spotify.com/documentation/design) | Spotify brand guidelines |
| `apple-music-badge.svg` | "Listen on Apple Music" badge (black), [Apple Services Marketing Toolbox](https://toolbox.marketingtools.apple.com/en-us/apple-music) | [Apple Music Identity Guidelines](https://apple.com/itunes/marketing-on-music/identity-guidelines.html) |
| `github.svg` | `mark-github-24.svg` from [primer/octicons](https://github.com/primer/octicons) | MIT |
| `soundcloud.webp` | white cloud mark, [soundcloud.com/press](https://soundcloud.com/press) | SoundCloud brand guidelines |
| `youtube.png` | white icon, [brand.youtube](https://brand.youtube/) | YouTube brand guidelines |
| `x.svg` | white X mark, [X brand toolkit](https://about.x.com/en/who-we-are/brand-toolkit) | X brand guidelines |

Spotify's and Apple's files are committed **byte-for-byte unmodified** — both sets of terms forbid altering the marks.
Only the GitHub octicon is recoloured (to `#e8e8f0`), which its MIT licence permits.

SoundCloud and YouTube only offered raster downloads (WebP and PNG), which is fine — the `<img>` doesn't care, both are
far above the rendered size, and a raster file can't carry a script the way an SVG can. `x.svg` was checked and is a
single `<path fill="white">` with no scripts, handlers or external references.

Every vendored SVG is checked for `<script>`, event handlers and external references before committing. They are served
from our own origin, so a hostile SVG would run in our security context.

### Clear space is baked into the files — mind `iconHeight()`

The YouTube and SoundCloud downloads carry their required clear space as transparent margin, so the mark fills only part
of the canvas: 54% of the height for YouTube, **32%** for SoundCloud. A flat `height: 24px` would render the SoundCloud
mark about 8px tall. `Platform::iconHeight()` scales the *file* (56px and 37px) so the *visible* marks land near 18px and
20px, matching GitHub's 24px square optically. The files stay byte-for-byte unmodified and their clear space scales with
them. Don't "fix" this by trimming the transparent margin — that's the clear space the guidelines require.

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

## Outstanding: Spotify

Spotify is still waiting on DistroKid delivery — the icon is vendored, but `data/profiles.php` has no URL for it yet, so
`ProfileRepository::all()` skips it. Paste the profile URL once the profile exists and it appears.

Apple Music landed on 04.09.2026 as artist id `6808396360`. It is stored as `https://music.apple.com/artist/6808396360`
— **no storefront segment and no name slug**. Apple redirects that to the visitor's own store, and it keeps working
through an artist rename, which `…/us/artist/neuro-sys/6808396360` would not.

The footer currently shows **SoundCloud, Apple Music, YouTube, X and GitHub**.

## Not covered here

The **YouTube channel itself** needs its own Impressum under § 5 DDG once it isn't purely private — monetisation,
sponsorships or affiliate links all trigger it. Add it under Customize → Basic Info. A stage name is not sufficient:
it needs the full legal name and a deliverable address. Spotify and Apple Music artist profiles have no equivalent
field; the usual approach is linking back to neurosys.gg, which carries the Impressum.
