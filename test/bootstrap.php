<?php

/**
 * PHPUnit bootstrap.
 *
 * Loads Composer's autoloader, which provides PHPUnit and (via the psr-4 entry in
 * composer.json) the project's own classes. The hand-rolled autoloader in autoload.php
 * is what production actually uses; it is exercised end-to-end by test/basic_test.sh
 * instead, so the two suites cover it from different sides.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

/**
 * The development tooling under `tools/lib/`, which composer's psr-4 entry does not cover: it maps
 * `NeuroSYS\` to `src/NeuroSYS/` only, and `tools/` is deliberately not part of what ships.
 */
require __DIR__ . '/../tools/autoload.php';

/** Absolute path to the repository root, for tests that need the real data files. */
define('NEUROSYS_ROOT', dirname(__DIR__));
