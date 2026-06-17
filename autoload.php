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