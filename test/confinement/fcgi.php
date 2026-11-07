<?php

/**
 * A FastCGI client just large enough to ask one pool one question.
 *
 * The confinement suite talks to the confined pool's socket directly, with probes that live under
 * test/ and so behind no URL: the questions it asks — which process am I, can I write here, can I
 * dial out — are ones no page should answer. One responder request, no keep-alive.
 *
 * Usage: php fcgi.php <socket> <script> [query]
 * Prints the response body. Exit 0 when the pool answered, 2 when the connection closed without an
 * end-of-request record — the worker died mid-request, which is what a killed probe looks like.
 */

declare(strict_types=1);

const FCGI_VERSION        = 1;
const FCGI_BEGIN_REQUEST  = 1;
const FCGI_END_REQUEST    = 3;
const FCGI_PARAMS         = 4;
const FCGI_STDIN          = 5;
const FCGI_STDOUT         = 6;
const FCGI_STDERR         = 7;
const FCGI_RESPONDER      = 1;
const FCGI_REQUEST_ID     = 1;

// One record of the request, its content unpadded.
$record = static fn(int $type, string $content): string =>
    pack('CCnnCx', FCGI_VERSION, $type, FCGI_REQUEST_ID, strlen($content), 0) . $content;

// One name-value pair, each length in one byte below 128 and four above.
$length = static fn(int $n): string => $n < 128 ? chr($n) : pack('N', $n | 0x80000000);
$pair   = static fn(string $name, string $value): string =>
    $length(strlen($name)) . $length(strlen($value)) . $name . $value;

[, $socket, $script] = $argv + [null, null, null];
$query = $argv[3] ?? '';

if ($socket === null || $script === null) {
    fwrite(STDERR, "usage: php fcgi.php <socket> <script> [query]\n");
    exit(64);
}

$connection = stream_socket_client('unix://' . $socket, $errno, $error, 5);

if ($connection === false) {
    fwrite(STDERR, "cannot connect to $socket: $error\n");
    exit(1);
}

$environment = [
    'GATEWAY_INTERFACE' => 'CGI/1.1',
    'SERVER_PROTOCOL'   => 'HTTP/1.1',
    'REQUEST_METHOD'    => 'GET',
    'SCRIPT_FILENAME'   => $script,
    'SCRIPT_NAME'       => '/' . basename($script),
    'REQUEST_URI'       => '/' . basename($script) . ($query === '' ? '' : '?' . $query),
    'QUERY_STRING'      => $query,
    'DOCUMENT_ROOT'     => dirname($script),
    'SERVER_NAME'       => 'localhost',
    'REMOTE_ADDR'       => '127.0.0.1',
];
$params      = '';

foreach ($environment as $name => $value) {
    $params .= $pair($name, $value);
}

fwrite($connection, $record(FCGI_BEGIN_REQUEST, pack('nCx5', FCGI_RESPONDER, 0))
    . $record(FCGI_PARAMS, $params) . $record(FCGI_PARAMS, '') . $record(FCGI_STDIN, ''));

$out   = '';
$ended = false;

while (!$ended && ($header = fread($connection, 8)) !== false && strlen($header) === 8) {
    $fields  = unpack('Cversion/Ctype/nid/nlength/Cpadding', $header);
    $content = $fields['length'] > 0 ? stream_get_contents($connection, $fields['length']) : '';

    if ($fields['padding'] > 0) {
        (void) stream_get_contents($connection, $fields['padding']);
    }

    match ($fields['type']) {
        FCGI_STDOUT      => $out .= $content,
        FCGI_STDERR      => fwrite(STDERR, $content),
        FCGI_END_REQUEST => $ended = true,
        default          => null,
    };
}

fclose($connection);

$split = strpos($out, "\r\n\r\n");
echo $split === false ? $out : substr($out, $split + 4);

exit($ended ? 0 : 2);
