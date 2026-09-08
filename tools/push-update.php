<?php

/**
 * Entry point for the `push-update` command — see {@link \NeuroSYS\Tool\Command\PushUpdate}.
 *
 * Usage: php tools/push-update.php [--dry-run] [--no-mirror] [--url <url>] [--key <file>]
 *
 * Declares nothing and runs one thing, which is the shape `phpcs` wants of a file with side
 * effects. Both autoloaders: the site's, because the payload is framed by the very class the server
 * parses it with and packed against Support\File and Support\Directory, and the tooling's, because
 * that is where the command lives.
 *
 * Run `npm run build:prod` first — this ships build/dist/, the same tree deploy.sh does.
 */

declare(strict_types=1);

use NeuroSYS\Tool\Cli\Runner;
use NeuroSYS\Tool\Command\PushUpdate;

require __DIR__ . '/../autoload.php';
require __DIR__ . '/autoload.php';

Runner::run(new PushUpdate(), $argv);
