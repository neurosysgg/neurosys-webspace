<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Export;

use NeuroSYS\Support\File;
use NeuroSYS\Tool\Release\ReleaseFolder;

/**
 * The PreparedExport class. The audio is already there; hand it back.
 *
 * The stand-in for {@link FlStudioExport} while that cannot run, and it is not only a stand-in:
 * every release so far was exported by hand out of FL and left in its folder, so this is also an
 * accurate description of how the audio has always arrived. It says so — the result carries
 * {@link ExportSource::Prepared}, and the report prints it — because the difference between a file
 * this tooling rendered a minute ago and a file somebody exported last week is the whole reason
 * that column exists.
 *
 * The ladder is the same shape as the ones in {@link ReleaseFolder}: the file named outright, then
 * the folder's file for the format asked for, then the master. First hit wins, nothing is guessed,
 * and a folder with none of them is an exception rather than a silent empty upload.
 */
final readonly class PreparedExport implements Exporter
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param File|null $file A file to use instead of anything in the folder — what `--audio`
     *                        fills in. Null to let the folder answer.
     */
    public function __construct(private ?File $file = null) {}

    /**
     * @param ReleaseFolder $folder
     * @param RenderFormat  $format
     * @return ExportedAudio
     * @throws ExportException if the folder has nothing to publish.
     */
    public function export(ReleaseFolder $folder, RenderFormat $format): ExportedAudio
    {
        $file = $this->file ?? $folder->fileFor($format->releaseFormat()) ?? $folder->master;

        if ($file === null) {
            throw new ExportException(sprintf(
                '%s holds no %s and no master, so there is nothing to upload. Export one from the '
                . 'project, or name a file with --audio.',
                $folder->directory->path,
                $format->value,
            ));
        }

        return ExportedAudio::prepared($file);
    }
}
