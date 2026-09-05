<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Export;

use NeuroSYS\Tool\Release\ReleaseFolder;

/**
 * The Exporter interface. Where the audio for a release comes from.
 *
 * One method, and it is a port in the strict sense: the two implementations do not differ in how
 * they render — one of them does not render at all — they differ in *which machine the audio is on*
 * and how it gets to this one. {@link PreparedExport} answers from the release folder;
 * {@link FlStudioExport} would answer by making FL Studio render the project, on the Windows
 * machine where FL Studio lives.
 *
 * That is why the seam is here rather than inside a single class with a flag: the command that
 * calls this does not change at all when the second implementation starts working.
 */
interface Exporter
{
    /**
     * The audio to publish for a release.
     *
     * @param ReleaseFolder $folder
     * @param RenderFormat  $format What to render to, where rendering is what happens.
     * @return ExportedAudio
     * @throws ExportException if there is no audio to hand back.
     */
    public function export(ReleaseFolder $folder, RenderFormat $format): ExportedAudio;
}
