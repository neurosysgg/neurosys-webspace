<?php

/**
 * Autoloader for the development tooling — `NeuroSYS\Tool\` → `tools/lib/`.
 *
 * A second autoloader rather than an entry in the site's own, because these classes are not the
 * site: `deploy.sh` uploads `src/` and never `tools/`, so pointing the deployed autoloader at a
 * directory the server has never seen would be a claim that is false everywhere it matters.
 *
 * Not composer's `autoload-dev` either. `tools/stage-release.php` runs on a clone that has never
 * seen `composer install`, which is the same property the root `autoload.php` exists to keep.
 * `tools/merge-coverage.php` does need `vendor/`, but for the coverage library — its own
 * dependency, not this layer's.
 *
 * Loaded by each command's entry point, and by `test/bootstrap.php`.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'NeuroSYS\\Tool\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__ . '/lib/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
