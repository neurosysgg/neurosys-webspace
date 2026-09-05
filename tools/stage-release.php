<?php

/**
 * Entry point for the `stage-release` command — see {@link \NeuroSYS\Tool\Command\StageRelease}.
 *
 * Usage:
 *   php tools/stage-release.php <folder> [--check]
 *
 *   php tools/stage-release.php ~/Music/neuro.SYS/releases/ill         # report, then the entry
 *   php tools/stage-release.php ~/Music/neuro.SYS/releases/ill --check # report only, exit 1 on FAIL
 *
 * Declares nothing and runs one thing, which is the shape `phpcs` wants of a file with side effects.
 * Both autoloaders: the site's, because the command resolves genres and keys against the real enums,
 * and the tooling's, because that is where the command itself lives.
 */

declare(strict_types=1);

use NeuroSYS\Tool\Cli\Runner;
use NeuroSYS\Tool\Command\StageRelease;

require __DIR__ . '/../autoload.php';
require __DIR__ . '/autoload.php';

Runner::run(new StageRelease(), $argv);
