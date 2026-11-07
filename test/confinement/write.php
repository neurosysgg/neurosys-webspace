<?php

// Tries to create, then remove, a file in the directory the query names. Prints "written" or the
// error — never anything else, so the suite can compare the answer exactly.

declare(strict_types=1);

$directory = $_GET['dir'] ?? '';
$file      = rtrim($directory, '/') . '/.confine-probe-' . getmypid();

$written = file_put_contents($file, 'x');

if ($written === false) {
    echo error_get_last()['message'] ?? 'failed';
} else {
    unlink($file);
    echo 'written';
}
