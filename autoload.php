<?php

declare(strict_types=1);

// The framework first — `Phpanta\` → `phpanta/src/` — so that everything below can name it.
require_once __DIR__ . '/phpanta/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'NeuroSYS\\';
    $base   = __DIR__ . '/src/NeuroSYS/';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = $prefix
        |> strlen(...)
        |> (fn($x) => substr($class, $x))
        |> (fn($x) => str_replace('\\', '/', $x) . '.php');

    // TEMPORARY — the move's alias, removed once the server's data/ is redeployed.
    //
    // A class that moved into the framework is still named by its old name in the data files on the
    // server, which only deploy.sh rewrites — a push never ships data/. So an old name whose
    // framework class exists is aliased to it, and asked *before* the old file, because an old copy
    // can still be on the server between the push that adds phpanta/ and the one that mirrors src/:
    // loading it would put two classes with one shape side by side, and every type check between
    // them would fail.
    if (is_file(__DIR__ . '/phpanta/src/' . $relative)) {
        class_alias('Phpanta\\' . substr($class, strlen($prefix)), $class);

        return;
    }

    if (is_file($base . $relative)) {
        require $base . $relative;
    }
});

// Every entry point loads this file, so every entry point is booted: the site, the dev router,
// the tools and each `php -r` line in the verify script. Booting does nothing but construct the
// object (see App), and is idempotent, which the dev router needs — it loads this file before
// index.php loads it again.
NeuroSYS\Site::boot();
