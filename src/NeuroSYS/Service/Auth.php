<?php

declare(strict_types=1);

namespace NeuroSYS\Service;

use NeuroSYS\Config;
use NeuroSYS\Http\Header;
use NeuroSYS\Http\HttpStatusCode;
use NeuroSYS\Http\Request;
use NeuroSYS\Http\ResponseHeader;

/**
 * The Auth class. Provides HTTP Basic Authentication gates for the site.
 */
class Auth
{
    /**
     * The Basic Auth realm both gates challenge with.
     *
     * One constant rather than the same quoted string twice: the browser keys stored credentials by
     * realm, so two that differ by a character are two separate prompts to the visitor.
     */
    private const string CHALLENGE = 'Basic realm="' . Config::NAME . '"';

    /**
     * Enforces site-wide pre-launch authentication if a credentials file exists.
     *
     * Exits with a 401 response if credentials are wrong. Does nothing if the
     * credentials file (data/site_auth.php) is absent — used to disable pre-launch auth.
     */
    public static function requireSiteAuth(Request $request): void
    {
        $file = Config::dataPath('site_auth.php');
        if (!is_file($file)) {
            return;
        }

        $creds = require $file;

        if (
            $request->authUser() !== $creds['user']
            || !password_verify($request->authPassword(), $creds['pass_hash'])
        ) {
            header(new Header(ResponseHeader::WwwAuthenticate, self::CHALLENGE)->line());
            http_response_code(HttpStatusCode::Unauthorized->value);
            exit;
        }
    }

    /**
     * Enforces admin authentication for protected routes (e.g. /admin/stats).
     *
     * Exits with a 401 response if credentials do not match data/admin.php.
     */
    public static function requireAdminAuth(Request $request): void
    {
        $creds = require Config::dataPath('admin.php');

        $ok = $creds['pass_hash'] !== ''
           && hash_equals($creds['user'], $request->authUser())
           && password_verify($request->authPassword(), $creds['pass_hash']);

        if (!$ok) {
            header(new Header(ResponseHeader::WwwAuthenticate, self::CHALLENGE)->line());
            http_response_code(HttpStatusCode::Unauthorized->value);
            exit;
        }
    }
}
