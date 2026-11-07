<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Command;

use Phpanta\Tool\Cli\Option;

/** The flags `app-data` takes. */
enum AppDataOption: string implements Option
{
    /** Write nothing; fail when a file in `app/gen/` is not what would be written. */
    case Check = 'check';

    /** @return string */
    public function flag(): string
    {
        return $this->value;
    }

    /** @return bool */
    public function takesValue(): bool
    {
        return false;
    }
}
