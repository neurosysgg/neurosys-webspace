<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'NeuroSYS\\';
    $base   = __DIR__ . '/src/NeuroSYS/';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = $base . ($prefix
            |> strlen(...)
            |> (fn($x) => substr($class, $x))
            |> (fn($x) => str_replace('\\', '/', $x) . '.php'));

    if (is_file($file)) {
        require $file;
    }
});

// Every entry point loads this file, so every entry point is booted: the site, the dev router,
// the tools and each `php -r` line in the verify script. Booting does nothing but construct the
// object (see App), and is idempotent, which the dev router needs — it loads this file before
// index.php loads it again.
NeuroSYS\Site::boot();
