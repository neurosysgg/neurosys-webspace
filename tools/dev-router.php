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

use NeuroSYS\Http\Header;
use NeuroSYS\Http\MimeType;
use NeuroSYS\Http\ResponseHeader;
use NeuroSYS\Http\TopLevelType;
use NeuroSYS\Support\Charset;

// The site's own autoloader, so a served asset declares its type the way a served document does.
// `public/index.php` requires the same file; this adds no dependency the dev server did not have.
require_once __DIR__ . '/../autoload.php';

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

// A `MimeType` and not a string with `; charset=utf-8` stapled on, for the reason that class
// exists: `nosniff` stops a browser guessing the type and nothing stops it guessing the encoding,
// so the charset is the half that earns an object. It is the same object `ViewResponse` sends,
// which is the point — this file exists to answer the way the real server does, and answering with
// four hand-written strings was the one place it did not.
//
// The octet-stream fallback deliberately carries no charset: it is reached only by an extension
// `public/.htaccess` has no `SetHandler` for, so it names bytes rather than text.
$type = match (pathinfo($file, PATHINFO_EXTENSION)) {
    'js'    => new MimeType(TopLevelType::Text, 'javascript', Charset::Utf8),
    'css'   => new MimeType(TopLevelType::Text, 'css', Charset::Utf8),
    'map'   => new MimeType(TopLevelType::Application, 'json', Charset::Utf8),
    default => new MimeType(TopLevelType::Application, 'octet-stream', null),
};

header(new Header(ResponseHeader::ContentType, $type)->line());

readfile($file);

return true;
