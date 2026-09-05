<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Command;

use NeuroSYS\Tool\Cli\Option;

/**
 * The MergeCoverageOption enum. The report formats `tools/merge-coverage.php` can write.
 *
 * Both take a path, and both used to be read out of a string-keyed array — so a mistyped `--clover`
 * meant the command reported success and wrote nothing. `Input` now refuses the flag by name.
 *
 * @see MergeCoverage
 */
enum MergeCoverageOption: string implements Option
{
    /** An XML file, for anything that reads Clover. */
    case Clover = 'clover';

    /** A directory of browsable HTML. */
    case Html = 'html';

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
        return true;
    }
}
