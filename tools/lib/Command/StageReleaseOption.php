<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Command;

use NeuroSYS\Tool\Cli\Option;

/**
 * The StageReleaseOption enum. What `tools/stage-release.php` accepts.
 *
 * @see StageRelease
 */
enum StageReleaseOption: string implements Option
{
    /** Report only. Useful before an upload, when the entry is not what you are there for. */
    case Check = 'check';

    /**
     * The FL Studio project, where it is not in the release folder — which is nearly always.
     *
     * A `.flp` references its samples by absolute path, so projects are kept together and zipped
     * rather than filed beside the masters they exported: these live under `neuro.SYS PROJECTS/`
     * while the releases live under `Music/neuro.SYS/releases/`. {@link ProjectFile} still looks in
     * the folder first, because a remix package is a reasonable place to put one, but pointing at
     * the real project is what this is for. Takes the `.flp` or the zip holding it.
     */
    case Project = 'project';

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
        return $this === self::Project;
    }
}
