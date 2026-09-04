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

/** Absolute path to the repository root, for tests that need the real data files. */
define('NEUROSYS_ROOT', dirname(__DIR__));
