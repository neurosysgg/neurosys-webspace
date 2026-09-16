<?php

/**
 * Entry point for the `drop` command — see {@link \Phpanta\Tool\Command\DropCreate}.
 *
 * Usage: php tools/drop.php [<file> | -] [--text <text>] [--name <name>] [--lifetime <30m|12h|7d>]
 *        [--once] [--password-file <file>] [--dry-run] [--url <origin>] [--key <file>]
 *
 * Declares nothing and runs one thing, which is the shape `phpcs` wants of a file with side
 * effects. Both autoloaders, for {@link \Phpanta\Tool\Command\ApiCall}'s reason.
 */

declare(strict_types=1);

use NeuroSYS\Site;
use Phpanta\Tool\Cli\Runner;
use Phpanta\Tool\Command\DropCreate;

require __DIR__ . '/../autoload.php';
require __DIR__ . '/autoload.php';

Runner::run(new DropCreate(Site::ORIGIN, Site::UPDATE_KEY), $argv);
