<?php

declare(strict_types=1);

/*
 * Prints the C app's data — app/gen/ and data/demos.json — from the PHP site. See
 * tools/lib/Command/AppData.php.
 *
 *   php tools/app-data.php [--check]
 */

use NeuroSYS\Tool\Command\AppData;
use Phpanta\Tool\Cli\Runner;

require __DIR__ . '/../autoload.php';
require __DIR__ . '/autoload.php';

Runner::run(new AppData(), $argv);
