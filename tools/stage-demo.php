<?php

/**
 * Entry point for the `stage-demo` command — see {@link \NeuroSYS\Tool\Command\StageDemo}.
 *
 * Usage:
 *   php tools/stage-demo.php <file>... [--title <title>] [--slug <slug>] [--check]
 *   php tools/stage-demo.php --rotate
 *
 *   # every mix on the page, newest first, in the order they are named
 *   php tools/stage-demo.php ~/Music/neuro.SYS/demos/"VR - WNA bootleg"/*" - v4.flac"
 *
 *   # report only: no password minted, no audio written, no entry printed
 *   php tools/stage-demo.php ~/Music/neuro.SYS/demos/"alien house v3.flac" --check
 *
 *   # a new password for a demo that is already staged
 *   php tools/stage-demo.php --rotate
 *
 * Declares nothing and runs one thing, which is the shape `phpcs` wants of a file with side effects.
 * Both autoloaders: the site's, because the command builds the real `Demo` and `PasswordHash` and
 * reads `Config` for where the audio goes, and the tooling's, because that is where it lives.
 */

declare(strict_types=1);

use NeuroSYS\Tool\Cli\Runner;
use NeuroSYS\Tool\Command\StageDemo;

require __DIR__ . '/../autoload.php';
require __DIR__ . '/autoload.php';

Runner::run(new StageDemo(), $argv);
