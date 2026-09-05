# Contracts — the seam between PHP and TypeScript

Every fact this codebase states twice, where each half lives, and what stops the two drifting apart.

The server and the browser are written in different languages, in different directories, tested by
different runners. Where they have to agree on something, that agreement is a *contract* — and a
broken contract here almost never produces an error. It produces a page that looks nearly right.

Read this before renaming anything in `src/NeuroSYS/View/Html/`, `src/NeuroSYS/Model/Embed/`,
`src/NeuroSYS/View/Terminal/` or `assets/ts/model/`.

---

## Why there is a seam at all

The server sends **the release's facts**. The element owns **the provider's furniture**.

`SoundCloudEmbed` renders `<soundcloud-player>` with a track id, a permalink and a set of toggles.
It does not build the widget URL, the accent colour, the attribution block or the iframe — those all
live in `SoundCloudWidget.ts`, because they are SoundCloud's and not ours.

That split buys a specific guarantee: **the served page names no SoundCloud address at all.** There
is nothing for a browser to preconnect, prefetch or resolve before the visitor clicks the consent
gate. It also costs one thing, which is this document — the price of that split is a set of names
both sides have to spell identically.

---

## How a contract fails

The kind of drift decides how loudly it fails, and neither kind reaches a console on its own.

| Drift | Symptom |
|---|---|
| a wrong **value** | usually visible — a broken widget URL, a tone that does not colour |
| a wrong **name** | *nothing*. `getAttribute` returns null and the element falls back; the browser meets a tag it has never heard of and lays out an inert inline box; the SPA router finds no `#content` and quietly switches itself off with every page still working |

**The worst one is `X-Requested-With`.** Drift on either side and the server answers a SPA fetch with
a whole document, which `Navigation` writes into `<main>` — `<!DOCTYPE html><html>…` nested inside
the page. Broken in a way nothing reports, from two strings that used to sit in different languages
with nothing between them.

---

## The inventory

Every mirror, and what guards it. `test/js/enum-parity.test.mjs` compares each against its PHP
original **by name, backing value and declaration order** — declaration order because
`SoundCloudEmbed` and `SoundCloudWidget` both build the query string by iterating the cases.

### Mirrors with accessors compared too

| TypeScript | PHP | Also compares |
|---|---|---|
| `model/Platform.ts` | `Model\Platform` | `displayName()` |
| `model/SoundCloudOption.ts` | `Model\Embed\SoundCloudOption` | — (order is the query string) |
| `model/SoundCloudPlayerStyle.ts` | `Model\Embed\SoundCloudPlayerStyle` | `isVisual()` |
| `model/TerminalTone.ts` | `View\Terminal\TerminalTone` | — |

### Mirrors compared case for case

| TypeScript | PHP | Carries |
|---|---|---|
| `model/Tag.ts` | `View\Html\Tag` | every custom element the site emits or builds |
| `model/HtmlTag.ts` | `View\Html\HtmlTag` | the standard elements **either side** creates |
| `model/HtmlAttribute.ts` | `View\Html\HtmlAttribute` | the standard attributes |
| `model/SoundCloudPlayerAttribute.ts` | `Model\Embed\SoundCloudPlayerAttribute` | SoundCloud's own attributes |
| `model/EmbedAttribute.ts` | `Model\Embed\EmbedAttribute` | `height`, `loaded` |
| `model/TerminalAttribute.ts` | `View\Terminal\TerminalAttribute` | `label`, `command`, `fields`, `narrow` |
| `model/CoverArtAttribute.ts` | `View\Html\CoverArtAttribute` | `src`, `fallback`, `alt` |
| `model/LinkAttribute.ts` | `View\Html\LinkAttribute` | `data-no-spa` |
| `model/TerminalFieldKey.ts` | `View\Terminal\TerminalFieldKey` | the JSON keys a row arrives under |
| `model/CssClass.ts` | `View\Html\CssClass` | what the stylesheet selects on |
| `model/ElementId.ts` | `View\Html\ElementId` | `content` — what the SPA router swaps |
| `model/RequestHeader.ts` | `Http\RequestHeader` | `X-Requested-With` |
| `model/RequestedWith.ts` | `Http\RequestedWith` | `XMLHttpRequest` |

### Not an enum, same problem

| TypeScript | PHP | Fields |
|---|---|---|
| `Config.ts` | `Config` | `NAME`, `HANDLE`, `PLAYER_HOST` |

`PLAYER_HOST` is the one with teeth: it is also the CSP's whole `frame-src`, so a drift means the
player is blocked by our own policy — in the console, with nothing in the page to say why.

---

## Names with only one side

Three names have no counterpart, and each absence is deliberate.

| Name | Written by | Read by | Why no mirror |
|---|---|---|---|
| `tone` (`TerminalFieldAttribute`) | `TerminalWindow.ts` | the stylesheet | the server sends the tone *inside* the JSON row, not as an attribute |
| `--player-height` (`CustomProperty`) | `ConsentGatedEmbed.ts` | the stylesheet | a custom property the gate sets on itself |
| `loaded` (`EmbedAttribute::Loaded`) | `ConsentGatedEmbed.ts` | the stylesheet | client-written, but it *does* have a PHP case — see below |

The first two are named in TypeScript anyway, even though no test can follow them, because **the
stylesheet is exactly the kind of reader that fails in silence**: get `--player-height` wrong and the
stylesheet's fallback quietly takes over and the page jumps on load.

`loaded` is the interesting one. It is written only by the client, but it has a `EmbedAttribute` case
on the PHP side so the parity test has something to compare against — the same arrangement as
`ResponseHeader::PoweredBy`, which names a header the site does not send. **A view must never emit
it**: doing so would style the box as a loaded player while the gate is still the only thing in it.
It is named so `embed.css`'s `&[loaded]` has something on the other end of it.

`CardAttribute` runs the other way: `slug` and `format` are written by the server and read by nobody
client-side, because the card tags are guards. No mirror, and none needed.

---

## What deliberately stays a literal

The absence of a constant is a decision here, not an oversight.

- **The platform's own vocabulary** — `'click'`, `'error'`, `'popstate'`, `'same-origin'`.
  TypeScript's DOM types already carry those.
- **User-facing copy.** The consent notice's wording lives where it is written and is asserted
  there.
- **SoundCloud's furniture.** The player reproduces the embed dialog's output exactly — `allow`,
  `scrolling`, `frameborder`, the `url`/`color`/`visual` query keys, the accent, the attribution's
  font stack. None of it is a contract with our own code. `SoundCloudOption` is enumerated only
  because the *server* says which options are on.
- **What means nothing outside the file that owns it** — `CspHost`'s origin pattern, `HiDriveLink`'s
  share-id pattern, `Navigation`'s event name. Moving those to `Config` would only make them
  reachable from everywhere.

---

## The wire formats

Four shapes cross the seam. Attributes are most of it.

### 1. Attributes — the normal case

The server writes typed attributes; the element reads them and builds everything else.

```php
new Element(Tag::SoundCloudPlayer)
    ->attr(SoundCloudPlayerAttribute::TrackId, $this->trackId)
    ->attr(SoundCloudPlayerAttribute::SecretToken, $this->secretToken ?: null)
    ->attr(EmbedAttribute::Height, $this->height());
```

```ts
this.getAttribute(SoundCloudPlayerAttribute.TrackId) ?? ''
```

**`''` and `null` are different, and the client depends on it.** A public track has no secret token,
so `SoundCloudEmbed` sends `null` — `attr()` then omits the attribute entirely. `secret-token=""` is
not the same thing to `SoundCloudPlayer.resourceUrl()`, which appends `?secret_token=` when the value
is non-empty.

### 2. JSON in an attribute — the terminal rows

The only place a structure crosses rather than a scalar, because it is the only shape that stays
generic: a release lists five metadata rows and a 404 lists one error line.

```php
// Terminal::toElement()
json_encode(array_map(fn (TerminalField $f) => $f->toArray(), $this->fields->all()), …)
```

```ts
// TerminalWindow.fields() — validated through the same enum that wrote it
row[TerminalFieldKey.Key], row[TerminalFieldKey.Value], row[TerminalFieldKey.Tone]
```

`TerminalWindow` **throws** on anything malformed rather than rendering a half-terminal: this
attribute is written by our own server, so a bad one is a bug worth hearing about, not input worth
tolerating. If `TerminalFieldKey` drifts, the type guard rejects every row and the window throws —
loud, but for a reason nobody would guess from the message.

### 3. The fragment request

```
Navigation.go()   →  X-Requested-With: XMLHttpRequest
Request::fromGlobals()  →  $ajax
ViewResponse::send()    →  <title>…</title> + the content fragment, not a Document
```

The server derives the `$_SERVER` key from the enum case rather than retyping it —
`'HTTP_' . str_replace('-', '_', strtoupper(RequestHeader::RequestedWith->value))` — so PHP's
transform is applied to the same string the client sends.

The fragment leads with a `<title>`, which `Navigation` reads out, decodes and strips. It is an
element like any other, so the title is escaped by the same rule as everything else — which is why
the client has to decode it, or a track called "rock & roll" shows up in the tab as `rock &amp;
roll`.

### 4. Tag names and CSS classes

Not passed at all — *agreed on*. The server writes them into markup, the client registers, creates
and selects on them, and the stylesheet matches them. Three checks cover the tag half from three
directions:

| Check | Catches |
|---|---|
| `ViewTest` | a view emitting a tag outside the pinned set |
| the verify script | a custom tag in a real response that is not a `Tag` case |
| `vocabulary.test.mjs` | a `Tag` case not registered once `main.js` has run |

The third is the one that matters most, because the tags an element builds itself appear in no server
response for the verify script to see.

`HtmlTest` closes the stylesheet half: it parses the generated `style.css` and asserts that every
`Tag` case is styled by exactly one part, and that the `CssClass` set matches the rules in both
directions — a class with no rule and a rule with no class both fail.

---

## Renaming checklist

Anything in the inventory above. Miss a step and the test suite tells you — that is what it is for —
but the order below makes it one pass instead of three.

1. **The PHP case.** Name and backing value.
2. **The TypeScript mirror.** Same name, same value, **same position** — order is compared.
3. **`main.ts`**, if it was a `Tag` and the module moved.
4. **The stylesheet**, if a `Tag` or `CssClass` selects on it. Every `Tag` case must be styled by
   exactly one part.
5. `npm run build` — TypeScript *and* CSS. `npm run watch` does not do the second.
6. `npm test` then `composer test`.

```bash
npm run build && npm test && composer test
```

### Adding rather than renaming

A new mirrored enum needs one more step: add it to `MIRRORED_NAMES` in
`test/js/enum-parity.test.mjs`, or the mirror exists with nothing comparing it. If the client also
mirrors an accessor — the way `Platform` mirrors `displayName()` — it needs a test of its own beside
the four that already have one.

---

## Further reading

- [architecture.md](architecture.md) — the PHP side and what each layer knows
- [frontend.md](frontend.md) — the element model, the build, and the SPA router
- [testing.md](testing.md) — the two suites, the invariants, and what each one can and cannot see
- `CLAUDE.md` — the long-form rationale behind the split
