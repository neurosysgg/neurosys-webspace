<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Export;

use NeuroSYS\Model\ReleaseFormat;
use NeuroSYS\Support\File;

/**
 * The RenderFormat enum. What a project can be rendered to.
 *
 * **Not {@link ReleaseFormat}, which is the neighbouring list and a different question.** That enum
 * says what a release is *distributed* as, and its `STEMS` case is a zip of files rather than a
 * format anything renders to — asking an exporter for stems would be asking for a different feature
 * in a different dialog. So the two lists are related by {@link self::releaseFormat()} and are not
 * the same list.
 *
 * The backing value is both the file extension and the token FL Studio's `/E` switch takes, which
 * is convenient rather than load-bearing: if the two ever disagree, this is one enum with two
 * accessors and not one value doing two jobs.
 */
enum RenderFormat: string
{
    /** What a master is rendered to, and what everything else is made from. */
    case Wav = 'wav';

    /** Lossless and smaller; what the site offers as a download. */
    case Flac = 'flac';

    /** The lossy copy. */
    case Mp3 = 'mp3';

    /** Rendered by FL, offered by nothing here — present because the list is the format's, not ours. */
    case Ogg = 'ogg';

    /**
     * The release format this renders to.
     *
     * @return ReleaseFormat
     */
    public function releaseFormat(): ReleaseFormat
    {
        return match ($this) {
            self::Wav  => ReleaseFormat::WAV,
            self::Flac => ReleaseFormat::FLAC,
            self::Mp3  => ReleaseFormat::MP3,
            self::Ogg  => ReleaseFormat::OGG,
        };
    }

    /**
     * The format a file's extension names, or null where it is not one of these.
     *
     * @param File $file
     * @return self|null
     */
    public static function of(File $file): ?self
    {
        return self::tryFrom($file->extension());
    }
}
