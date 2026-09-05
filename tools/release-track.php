<?php

/**
 * Entry point for the `release-track` command — see {@link \NeuroSYS\Tool\Command\ReleaseTrack}.
 *
 * Usage:
 *   php tools/release-track.php <folder> [--audio <file>] [--project <file>] [--upload]
 *   php tools/release-track.php --authorize
 *
 *   php tools/release-track.php ~/Music/neuro.SYS/releases/ill            # report; sends nothing
 *   php tools/release-track.php ~/Music/neuro.SYS/releases/ill --upload   # and actually upload
 *
 * Declares nothing and runs one thing, which is the shape `phpcs` wants of a file with side
 * effects. Both autoloaders, for the reason `stage-release.php` gives: the command resolves genres
 * and builds a real `SoundCloudEmbed` out of the site's own classes, and lives in the tooling's.
 */

declare(strict_types=1);

use NeuroSYS\Tool\Cli\Runner;
use NeuroSYS\Tool\Command\ReleaseTrack;

require __DIR__ . '/../autoload.php';
require __DIR__ . '/autoload.php';

Runner::run(new ReleaseTrack(), $argv);
