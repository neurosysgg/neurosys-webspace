<?php

/**
 * Entry point for the `merge-coverage` command — see {@link \NeuroSYS\Tool\Command\MergeCoverage}.
 *
 * Usage:
 *   php tools/merge-coverage.php <unit.cov> <e2e-dump-dir> [--clover <file>] [--html <dir>]
 *
 * Normally run through `composer coverage`, which produces both inputs first.
 *
 * Composer's autoloader rather than the site's: this command is the one piece of tooling with a
 * real dependency, on `phpunit/php-code-coverage`. The tooling autoloader is still needed for the
 * command itself.
 */

declare(strict_types=1);

use NeuroSYS\Tool\Cli\Runner;
use NeuroSYS\Tool\Command\MergeCoverage;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/autoload.php';

Runner::run(new MergeCoverage(dirname(__DIR__)), $argv);
