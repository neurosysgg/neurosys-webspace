<?php

// What a confined worker can see of itself and its surroundings, as one JSON object.

declare(strict_types=1);

$status = [];

foreach (file('/proc/self/status') as $line) {
    [$key, $value] = explode(':', $line, 2) + [1 => ''];
    $status[$key] = trim($value);
}

$interfaces = [];

foreach (array_slice(file('/proc/self/net/dev') ?: [], 2) as $line) {
    $interfaces[] = trim(explode(':', $line, 2)[0]);
}

$pids = array_values(array_filter(scandir('/proc') ?: [], 'ctype_digit'));

echo json_encode([
    'pid'               => getmypid(),
    'uid'               => posix_geteuid(),
    'user'              => posix_getpwuid(posix_geteuid())['name'] ?? null,
    'seccomp'           => (int) ($status['Seccomp'] ?? -1),
    'filters'           => (int) ($status['Seccomp_filters'] ?? -1),
    'no_new_privs'      => (int) ($status['NoNewPrivs'] ?? -1),
    'cap_eff'           => $status['CapEff'] ?? null,
    'interfaces'        => $interfaces,
    'visible_pids'      => count($pids),
    'disable_functions' => ini_get('disable_functions'),
    'allow_url_fopen'   => ini_get('allow_url_fopen'),
    'pcre_jit'          => ini_get('pcre.jit'),
    'confine'           => extension_loaded('confine'),
    'xdebug'            => extension_loaded('xdebug'),
    'opcache'           => function_exists('opcache_get_status')
        && (opcache_get_status(false)['opcache_enabled'] ?? false),
], JSON_UNESCAPED_SLASHES);
