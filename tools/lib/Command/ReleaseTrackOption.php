<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Command;

use NeuroSYS\Tool\Cli\Option;

/**
 * The ReleaseTrackOption enum. What `tools/release-track.php` accepts.
 *
 * **There is no `--public`, and that absence is the design.** Sharing is decided in SoundCloud's
 * own interface, once the site is verified and on the day the release goes out — the step
 * `docs/releases.md` describes. A flag here would make publishing a typo away, and a track made
 * public by accident has an audience before anybody notices.
 *
 * @see ReleaseTrack
 */
enum ReleaseTrackOption: string implements Option
{
    /**
     * The FL Studio project, where the folder does not hold one.
     *
     * The same flag `stage-release` takes and for the same reason — see
     * {@link StageReleaseOption::Project}. It is here because the report reads a folder the same
     * way that command does, and a project is where the bpm, the key and the genre come from.
     */
    case Project = 'project';

    /**
     * The audio to upload, instead of whatever the folder holds.
     *
     * This is the flag that stands in for a render. {@link \NeuroSYS\Tool\Export\FlStudioExport}
     * will one day answer the same question by making FL Studio produce the file; until then the
     * file is produced by hand and named here, and the report says so in as many words.
     */
    case Audio = 'audio';

    /**
     * Actually upload.
     *
     * Off by default, because everything before it is reading files on this machine and this is the
     * step that puts one on somebody else's. Without it the command reports exactly what it would
     * send and sends nothing — which is also the only mode that works until an app is registered.
     */
    case Upload = 'upload';

    /**
     * Do the one browser round trip that gets a token, and store it.
     *
     * Needs no folder: it is about the account, not about a release.
     */
    case Authorize = 'authorize';

    /**
     * @return string
     */
    public function flag(): string
    {
        return $this->value;
    }

    /**
     * @return bool
     */
    public function takesValue(): bool
    {
        return $this === self::Project || $this === self::Audio;
    }
}
