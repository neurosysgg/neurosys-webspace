<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Command;

use Phpanta\Tool\Cli\Option;

/** The flags `app-data` takes. */
enum AppDataOption: string implements Option
{
    /** Write nothing; fail when a file in `app/gen/` is not what would be written. */
    case Check = 'check';

    /**
     * Print for the prod tree: the app's sources and an `app/gen/` built against
     * `build/dist/`'s manifest, into `build/dist/app/` — which `npm run build:prod` runs last.
     */
    case Prod = 'prod';

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
