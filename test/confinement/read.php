<?php

// Tries to read the file the query names. Prints "readable" or the error.

declare(strict_types=1);

$contents = file_get_contents($_GET['file'] ?? '');

echo $contents === false ? (error_get_last()['message'] ?? 'failed') : 'readable';
