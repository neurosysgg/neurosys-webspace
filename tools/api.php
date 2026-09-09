<?php

/**
 * Entry point for the `api` command — see {@link \NeuroSYS\Tool\Command\ApiCall}.
 *
 * Usage: php tools/api.php <service> <version> <action> [--url <origin>] [--key <file>]
 *
 * Declares nothing and runs one thing, which is the shape `phpcs` wants of a file with side
 * effects. Both autoloaders: the site's, because the vocabulary an address is built from is the
 * server's own, and the tooling's, because that is where the command lives.
 */

declare(strict_types=1);

use NeuroSYS\Tool\Cli\Runner;
use NeuroSYS\Tool\Command\ApiCall;

require __DIR__ . '/../autoload.php';
require __DIR__ . '/autoload.php';

Runner::run(new ApiCall(), $argv);
