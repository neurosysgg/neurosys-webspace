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
        return false;
    }
}
