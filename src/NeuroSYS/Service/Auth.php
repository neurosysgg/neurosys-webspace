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
 *
 * The decision and the 401 are separate, the same way {@link \NeuroSYS\Http\SecurityHeaders}
 * separates `headers()` from `send()`, and for the same reason: a gate that ends the request
 * cannot be asserted against in-process, so everything worth asserting lives in
 * {@link self::accepts()} and the two `require*` methods are only the challenge around it.
 *
 * That split is what the {@link \NeuroSYS\Test\Unit\AuthTest} needs to exist. Before it, the
 * credential comparison had never run under either suite: `data/admin.php` ships with an empty
 * `pass_hash`, so the guard short-circuits and neither `hash_equals()` nor `password_verify()`
 * is reached — which means the two `/admin/stats → 401` checks in `test/basic_test.sh` prove the
 * route is gated, not that the comparison works.
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
     * True if $request carries the credentials $file holds.
     *
     * Both gates ask the same question of the same shape of file, so they ask it in one place.
     * Every comparison is constant-time: the password because that is what `password_verify()`
     * is, the user name because it is compared on every request just the same.
     *
     * @param Request $request The request whose Basic Auth credentials to check.
     * @param string  $file    A credentials file returning `['user' => …, 'pass_hash' => …]`.
     */
    public static function accepts(Request $request, string $file): bool
    {
        /** @var array{user: string, pass_hash: string} $creds */
        $creds = require $file;

        // An empty hash is an unconfigured gate rather than one that accepts an empty password.
        // password_verify() against '' is false anyway; the guard is here to say so out loud.
        return $creds['pass_hash'] !== ''
            && hash_equals($creds['user'], $request->authUser())
            && password_verify($request->authPassword(), $creds['pass_hash']);
    }

    /**
     * Enforces site-wide pre-launch authentication if a credentials file exists.
     *
     * Exits with a 401 if the credentials are wrong. Does nothing at all if the credentials file
     * is absent — that absence is how pre-launch auth is switched off, and `data/site_auth.php`
     * is gitignored precisely so the repo copy cannot switch it on.
     *
     * @param Request     $request The incoming request.
     * @param string|null $file    The credentials file; defaults to `data/site_auth.php`.
     */
    public static function requireSiteAuth(Request $request, ?string $file = null): void
    {
        $file ??= Config::dataPath('site_auth.php');

        if (!is_file($file)) {
            return;
        }

        if (!self::accepts($request, $file)) {
            self::challenge();
        }
    }

    /**
     * Enforces admin authentication for protected routes (e.g. /admin/stats).
     *
     * Exits with a 401 if the credentials do not match. Unlike the site gate there is no absent-file
     * case: a missing `data/admin.php` is a broken deployment, and `require` says so loudly rather
     * than leaving the admin routes open.
     *
     * @param Request     $request The incoming request.
     * @param string|null $file    The credentials file; defaults to `data/admin.php`.
     */
    public static function requireAdminAuth(Request $request, ?string $file = null): void
    {
        if (!self::accepts($request, $file ?? Config::dataPath('admin.php'))) {
            self::challenge();
        }
    }

    /** Sends the Basic Auth challenge and ends the request. */
    private static function challenge(): never
    {
        header(new Header(ResponseHeader::WwwAuthenticate, self::CHALLENGE)->line());
        http_response_code(HttpStatusCode::Unauthorized->value);
        exit;
    }
}
