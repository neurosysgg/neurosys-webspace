<?php

// Opens an outbound TCP socket. Under the filter the socket() call kills the process before it
// returns, so this script never prints; a reply means the filter did not hold.

declare(strict_types=1);

$connection = stream_socket_client('tcp://' . ($_GET['to'] ?? '1.1.1.1:80'), $errno, $error, 2);

echo $connection === false ? "refused: $error" : 'connected';
