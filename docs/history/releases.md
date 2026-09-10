# History — releases

The checklists of releases that have shipped, as they stood in `docs/releases.md` when they were
moved here. The generic checklist and whatever is still open are in [../releases.md](../releases.md).

## hello world!

### 2026-06-17 — checklist, target 01.07.2026 (`c54310b`, last updated `06ca2c9`)

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
- [ ] **Re-upload `hello world!.flac`** — it carried no `BPM` or `INITIALKEY`, so the filename was the only
      record of either. Both written locally on 05.09.2026 (audio verified untouched), along with the cover
      block being re-typed `image/png` from `image/apng`. Same share-link question as `ill.`'s WAV above.
- [x] Web covers exported to `hello world!/web/` — there were none; the only art was the picture embedded in
      the FLAC. Now a 2048² PNG and a 1400² JPEG at the same settings as `ill.`'s

## ill.

### 2026-09-04 — checklist, released 04.09.2026, 20:00 CEST (`06ca2c9`)

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
- [ ] **Re-upload `ill..wav`** — it was 16-bit/44.1kHz beside a 24-bit/48kHz FLAC, and carried no tags, which
      is what `tools/stage-release.php`'s preflight was written to catch. Corrected locally on 05.09.2026 by
      re-deriving it from the FLAC master (bit-identical audio, verified), so the folder is right and the
      HiDrive copy is not — `/releases/ill/wav` still serves the 16-bit file. Check whether the share link
      survives an overwrite at the same path, or whether it has to be re-minted and `RVg8LBS4A` updated.
- [x] `ill/STEMS/` moved to `ill/REMIX PACKAGE/stems/`, matching the zip that ships and the `hello world!`
      folder — a layout difference only, nothing uploaded changed

## How the manual `data/` upload was described

Until this split, `releases.md` ended its editing instructions with: "Upload it manually to `data/`
on the server via the PHPStorm Remote Host panel (it's outside the standard deployment mapping — see
`docs/deployment.md`)." `deploy.sh` has rsynced `data/` since before `ill.` shipped — the `ill.`
checklist above deploys `data/releases.php` with it.
