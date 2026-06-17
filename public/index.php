<?php
declare(strict_types=1);

$_authFile = __DIR__ . '/../data/site_auth.php';
if (is_file($_authFile)) {
    if (!isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['HTTP_AUTHORIZATION'])
        && str_starts_with($_SERVER['HTTP_AUTHORIZATION'], 'Basic ')) {
        [, $_b64] = explode(' ', $_SERVER['HTTP_AUTHORIZATION'], 2);
        [$_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']] =
            explode(':', base64_decode($_b64), 2) + ['', ''];
    }
    $_auth = require $_authFile;
    $_ok   = ($_SERVER['PHP_AUTH_USER'] ?? '') === $_auth['user']
          && password_verify($_SERVER['PHP_AUTH_PW'] ?? '', $_auth['pass_hash']);
    if (!$_ok) {
        header('WWW-Authenticate: Basic realm="neuro.SYS"');
        http_response_code(401);
        exit;
    }
    unset($_authFile, $_auth, $_ok);
}

$path     = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$path     = rtrim($path, '/') ?: '/';
$segments =
    ltrim($path, '/')
        |> (fn($x) => explode('/', $x))
        |> array_filter(...)
        |> array_values(...);

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
       && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

$releases = require __DIR__ . '/../data/releases.php';

$pageTitle = 'neuro.SYS';
$template  = '404';
$vars      = [];

// --- dispatch ---------------------------------------------------------------

if (count($segments) === 0) {
    $template = 'home';

} elseif ($segments[0] === 'releases') {

    if (count($segments) === 1) {
        $pageTitle = 'releases — neuro.SYS';
        $template  = 'releases';
        $vars      = ['releases' => $releases];

    } elseif (count($segments) >= 2) {
        $slug   = $segments[1];
        $action = $segments[2] ?? 'index';

        if (!isset($releases[$slug])) {
            // 404 falls through
        } elseif ($action === 'index') {
            $pageTitle = $releases[$slug]['title'] . ' — neuro.SYS';
            $template  = 'release';
            $vars      = ['slug' => $slug, 'release' => $releases[$slug]];

        } elseif (isset($releases[$slug]['formats'][$action])) {
            $url = $releases[$slug]['formats'][$action];
            if ($url !== '') {
                log_download($slug, $action);
                header('Location: ' . $url, true, 303);
                exit;
            }
            // format exists in config but URL not filled in yet
            http_response_code(503);
            header('Content-Type: text/plain; charset=utf-8');
            echo "This file isn't available yet — check back soon.\n";
            exit;
        }
    }

} elseif ($segments[0] === 'admin' && ($segments[1] ?? '') === 'stats') {
    require_admin_auth();
    $pageTitle = 'stats — neuro.SYS';
    $template  = 'stats';
    $vars      = ['logFile' => __DIR__ . '/../data/logs/downloads.log'];
}

// --- render -----------------------------------------------------------------

http_response_code($template === '404' ? 404 : 200);

$templateFile = __DIR__ . '/../templates/' . $template . '.php';

if ($isAjax) {
    extract($vars);
    echo '<title>' . htmlspecialchars($pageTitle) . '</title>';
    require $templateFile;
} else {
    $vars['pageTitle'] = $pageTitle;
    $vars['template']  = $template;
    extract($vars);
    require __DIR__ . '/../templates/shell.php';
}

// --- helpers ----------------------------------------------------------------

function require_admin_auth(): void
{
    $creds = require __DIR__ . '/../data/admin.php';

    $user = $_SERVER['PHP_AUTH_USER'] ?? '';
    $pass = $_SERVER['PHP_AUTH_PW']   ?? '';

    $ok = $creds['pass_hash'] !== ''
       && $user === $creds['user']
       && password_verify($pass, $creds['pass_hash']);

    if (!$ok) {
        header('WWW-Authenticate: Basic realm="neuro.SYS"');
        http_response_code(401);
        exit;
    }
}

function log_download(string $slug, string $format): void
{
    $logFile = __DIR__ . '/../data/logs/downloads.log';

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (str_contains($ip, '.')) {
        $ip = preg_replace('/\.\d+$/', '.0', $ip) ?? $ip;
    } elseif (str_contains($ip, ':')) {
        $ip = explode(':', $ip)
                |> (fn($x) => array_slice($x, 0, 4))
                |> (fn($x) => (implode(':', $x)) . '::');
    }

    $line = json_encode([
        'time'     => date('c'),
        'slug'     => $slug,
        'format'   => $format,
        'ip'       => $ip,
        'referrer' => $_SERVER['HTTP_REFERER']    ?? '',
        'ua'       => $_SERVER['HTTP_USER_AGENT'] ?? '',
    ], JSON_UNESCAPED_SLASHES);

    if ($line === false) return;

    $fp = @fopen($logFile, 'ab');
    if ($fp === false) return;
    if (flock($fp, LOCK_EX)) {
        fwrite($fp, $line . "\n");
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}
