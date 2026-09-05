<?php

/**
 * Router for the `php -S` dev server, so it answers a versioned asset URL the way Apache does.
 *
 * `tools/build-assets.mjs` puts the build stamp in the path — `/assets/js/v-a1b2c3d4/main.js` — so
 * that a relative import specifier carries it without anything having to rewrite the file. The
 * segment is not a real directory; the server strips it. In production `public/.htaccess` does that
 * with a RewriteRule. The built-in server reads no `.htaccess` at all, so without this every
 * versioned URL would 404 locally while working live — the exact shape of bug this project has
 * already been bitten by once, when Strato's handler list differed from the local setup.
 *
 * **This is one half of a mirror**, and the verify script pins that both halves strip the same
 * pattern. Change the shape in one and the check fails rather than the dev server quietly diverging.
 *
 * **Not a `NeuroSYS\Tool\Cli\Command`**, and cannot be: `php -S` loads this file per request and
 * reads a `bool` back. There is no argv and no exit code for a command interface to attach to.
 *
 * Dev-only: it is passed to `php -S` by test/basic_test.sh and by anyone running the local server.
 * It is never deployed — `deploy.sh` ships `public/`, `src/`, `autoload.php` and `data/`, and this
 * is in none of them.
 *
 * Usage:
 *   php -S localhost:8080 -t public tools/dev-router.php
 */

declare(strict_types=1);

/** The version segment, directly under the asset root. Mirrored in public/.htaccess. */
const VERSION_SEGMENT = '#^/assets/(js|css)/v-[0-9a-f]{8}/#';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$bare = preg_replace(VERSION_SEGMENT, '/assets/$1/', $path, 1, $stripped);

// Not a versioned URL — hand it back to the built-in server, which serves real files and falls
// through to index.php for everything else. That is the whole of the normal path.
if ($stripped !== 1) {
    return false;
}

$public = dirname(__DIR__) . '/public';
$file   = realpath($public . $bare);

// realpath before is_file, and containment before either: everything after the version segment came
// out of a URL, so `..` in it would otherwise read whatever the process can reach.
if ($file === false || !str_starts_with($file, $public . DIRECTORY_SEPARATOR) || !is_file($file)) {
    http_response_code(404);

    return true;
}

header('Content-Type: ' . match (pathinfo($file, PATHINFO_EXTENSION)) {
    'js'    => 'text/javascript; charset=utf-8',
    'css'   => 'text/css; charset=utf-8',
    'map'   => 'application/json; charset=utf-8',
    default => 'application/octet-stream',
});

readfile($file);

return true;
