# Demos

Unreleased work, behind a password, at `/demos/{slug}`. One password per demo, minted at random,
shown once.

It is the half of the catalogue that comes before a release, and it is arranged the opposite way
round on purpose. A release is a public page pointing at HiDrive; a demo is a private page **and**
private bytes — the audio lives under `data/`, outside the webroot, so the only route to it is a PHP
route that asks for the password first. That is the difference in one sentence: **a share link
outlives the password, and a demo route does not.**

Nothing about a demo is published. There is no `/demos` index, no link to one anywhere, and
`data/demos.php` is gitignored because `origin` is a public GitHub repository and these are titles
of tracks that are not out. Even the *existence* of a demo is unreadable: an unknown slug is refused
exactly like a wrong password, in the same time.

---

## Staging one

```bash
php tools/stage-demo.php <file>… [--title <title>] [--slug <slug>] [--check]
php tools/stage-demo.php --rotate
```

Every file you name becomes one mix on the page, in the order you name them — the first is what the
page leads with. **Nothing is discovered.** `~/Music/neuro.SYS/demos/` is a working directory: eight
bounces of one bootleg, four release candidates, a mastering export and a zero-byte file. No rule
over it picks the two worth sending, so you pick them.

```bash
# one loose master
php tools/stage-demo.php ~/Music/neuro.SYS/demos/"alien house v3.flac"

# two versions on one page, newest first
php tools/stage-demo.php \
    ~/Music/neuro.SYS/demos/"VR - WNA bootleg/… - v4.flac" \
    ~/Music/neuro.SYS/demos/"VR - WNA bootleg/… - v3.flac" \
    --slug wna-bootleg --title "Virtual Riot — We're Not Alone [neuro.SYS bootleg]"

# read the report and stop: nothing written, no password minted
php tools/stage-demo.php ~/Music/neuro.SYS/demos/"alien house v3.flac" --check
```

The command prints its report and the password to **stderr** and the entry to **stdout**, so
`> entry.php` captures the entry and never the password.

### What it derives, and what it does not

| Fact | Where it comes from |
|---|---|
| title | the FLAC's `TITLE` comment, else the file name with its version marker taken off |
| slug | the title, lowercased and hyphenated — same rule as a release's |
| label | the version marker in the file name: `v3`, `v12-rc`, else `mix` |
| duration | `ffprobe` |
| password | minted here, and nowhere else |
| description | **nothing.** It is the one editorial field, written out as `null` to fill in |

`--title` and `--slug` override the first two, and they are not exotic cases:
`alien house v4.flac` carries an **empty** `TITLE`, and
`Virtual Riot - We're Not Alone [neuro.SYS Bootleg]` slugs into something nobody wants to read out.

### What it writes

- `data/demos/{slug}/{label}.mp3` — the audio, 192 kbps CBR, metadata and artwork stripped.
  An already-lossy master is **remuxed** rather than re-encoded, so there is no generation loss;
  a FLAC or WAV is encoded, because 30 MB per listen through PHP on shared hosting is not a plan.
- Nothing else. It **prints** the `data/demos.php` entry rather than writing it, the same way
  `stage-release` does: that file is hand-ordered, and a file either orders entries or is generated.

Paste the entry into `data/demos.php`, newest first, creating the file if it is not there.

---

## The password

Four groups of five Crockford base32 characters — `NDPVW-GJJRB-36N84-XP0QB` — drawn from
`random_int()`. No `I`, `L`, `O` or `U`, because it gets read off a screen and typed into a browser
prompt, and sometimes read down a phone.

**Only the bcrypt hash is kept.** The plaintext is printed once and written nowhere: not to `data/`,
not to a ledger, not to a file the entry can be redirected into. There is no way to look one up
later, and that is the point — a copy of this repository, or of the server, contains no working
password.

Lost one, or gave it to the wrong person:

```bash
php tools/stage-demo.php --rotate
```

That prints a new password and the one line of the entry to replace. It touches no audio and loses
no description, which is why it is not a restage.

### What to send

```
url:       https://neurosys.gg/demos/wna-bootleg
user:      demo
password:  4RND5-M2TZ3-ZNVCE-GZ8JV
```

The user name is the same for every demo and is not a secret — the password is the whole credential.
What keeps one demo's saved password from being offered at another demo's prompt is the **realm**,
which is per slug: a browser keys stored credentials by realm.

---

## What the gate does and does not protect

**Does:** the page, and every byte of audio on it. `data/` is above the webroot on Strato and
outside `public/` locally, so Apache cannot serve those files at all — `DemoAudioController` is the
only path to them and it asks the same question the page does. Someone given a demo link and a
password cannot forward a URL that keeps working after the password changes, because there is no
such URL.

**Does not:** the file once it has been downloaded, or a recording of it playing. The staged MP3
carries no metadata, which is the most that can be done about a file that has left.

Three smaller things it also does, each attached to the gate rather than to what happens to be
behind it right now:

- `Cache-Control: no-store, private` on the page **and** on the audio, so a borrowed machine's cache
  keeps neither.
- No `ETag`, and therefore no `304` — a gated page cannot be handed back on a guessed validator.
- `X-Robots-Tag: noindex, nofollow, noarchive`. Not what keeps demos out of search results — a
  crawler gets a `401` and never sees a page — but it covers the narrower case of a page that has
  already been opened. **There is deliberately no `robots.txt` entry:** a `Disallow: /demos/` is a
  public file naming the private half of the site.

### A demo is unreachable while the pre-launch site gate is on

`Auth::requireSiteAuth()` runs on every request that reaches PHP, and both gates are HTTP Basic — a
request carries exactly **one** `Authorization` header. So while `data/site_auth.php` exists, a
request can satisfy the site gate or a demo gate and never both, and `/demos/{slug}` answers `401`
whatever you send it.

This does not bite today: the site is public and the gate is switched off (the file is dot-prefixed
to `.site_auth.php`). It would bite the day it is switched back on, so the verify script skips the
demo HTTP checks and says why when it sees that file. If demos ever have to work behind the
pre-launch gate, the fix is a decision rather than a patch — the demo password is the stronger of the
two credentials, so the site gate could reasonably stand down for `/demos/`, at the cost of the
blanket property that it covers everything.

---

## Deploying

Nothing special. `deploy.sh` rsyncs `data/` from the working tree without consulting git, so both
`data/demos.php` and `data/demos/` reach the server despite being gitignored — that pairing is
deliberate, and it is the opposite of `data/admin.php` and `data/site_auth.php`, which are
*excluded* from the rsync because the repo copies are placeholders.

`--delete` is on, so a demo removed locally is removed on the server. Removing one is: delete its
entry from `data/demos.php`, delete `data/demos/{slug}/`, deploy. Its URL then answers `401` like any
slug that never existed.

---

## Adding a second kind of gated thing

The parts split the way the release side does. `Demo` is the value object, `DemoTrack` is one mix,
`PasswordHash` is the credential, `FileResponse` is the byte-serving response, and `ByteRange` is the
seeking. Only the first two are about demos; the last three are about "a file behind a password" and
would be reused as they stand.

See also: [authoring.md](authoring.md) for the release-side tools this mirrors,
[security.md](security.md) for where the gate sits in the request, and
[testing.md](testing.md) for which half of the demo path each suite can see.
