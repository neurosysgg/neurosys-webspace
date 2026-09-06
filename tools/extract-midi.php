<?php

/**
 * Entry point for the `extract-midi` command — see {@link \NeuroSYS\Tool\Command\ExtractMidi}.
 *
 * Usage:
 *   php tools/extract-midi.php <folder|.flp|.zip> [--patterns] [--out <file>]
 *
 * Writes the project's notes as a standard MIDI file — the playlist arrangement by default, or
 * every pattern on its own track with `--patterns`. The report goes to stderr, because the file is
 * the product and it is binary.
 *
 * The site's autoloader rather than Composer's, the way `stage-release` does it: this runs on a
 * clone that has never seen `composer install`.
 */

declare(strict_types=1);

use NeuroSYS\Tool\Cli\Runner;
use NeuroSYS\Tool\Command\ExtractMidi;

require __DIR__ . '/../autoload.php';
require __DIR__ . '/autoload.php';

Runner::run(new ExtractMidi(), $argv);
