<?php

declare(strict_types=1);

namespace NeuroSYS\Service;

use NeuroSYS\Config;
use NeuroSYS\Http\BasicChallenge;
use NeuroSYS\Http\Header;
use NeuroSYS\Http\HttpStatusCode;
use NeuroSYS\Http\Request;
use NeuroSYS\Http\ResponseHeader;
use NoDiscard;

/**
 * The Auth class. Provides HTTP Basic Authentication gates for the site.
 *
 * The decision and the 401 are separate, the same way {@link \NeuroSYS\Http\SecurityHeaders}
 * separates `headers()` from `send()`, and for the same reason: a gate that ends the request
 * cannot be asserted against in-process, so everything worth asserting lives in
 * {@link self::accepts()} and the two `require*` methods are only the challenge around it.
 *
 * That split is what the {@link \NeuroSYS\Test\Unit\AdminTest} needs to exist. Before it, the
 * credential comparison had never run under either suite: `data/admin.php` ships with an empty
 * `pass_hash`, so the guard short-circuits and neither `hash_equals()` nor `password_verify()`
 * is reached — which means the two `/admin/stats → 401` checks in `test/basic_test.sh` prove the
 * route is gated, not that the comparison works.
 */
class Auth
{
    /**
     * The challenge both gates answer a 401 with.
     *
     * One value rather than the same one built twice: the browser keys stored credentials by realm,
     * so two challenges differing by a character are two separate prompts to the same visitor.
     * {@link BasicChallenge} owns the quoting around the realm, which is grammar rather than
     * decoration.
     *
     * @return BasicChallenge
     */
    private static function challengeValue(): BasicChallenge
    {
        return new BasicChallenge(Config::NAME);
    }

    /**
     * True if $request carries the credentials $file holds.
     *
     * Both gates ask the same question of the same shape of file, so they ask it in one place.
     * Every comparison is constant-time: the password because that is what `password_verify()`
     * is, the user name because it is compared on every request just the same.
     *
     * **And neither is skipped when the other fails.** Chaining the two with `&&` made the pair
     * leak what each one individually does not: bcrypt is deliberately slow, so a wrong user name
     * came back in microseconds while a right one paid the full cost, and that difference is
     * measurable across a network. It tells an attacker which half of the credential they have
     * already got — which is the half they cannot otherwise find out, since the password is the one
     * a brute-force attempt gets feedback on. So both run, every time, and the results are combined
     * afterwards.
     *
     * @param Request $request The request whose Basic Auth credentials to check.
     * @param string  $file    A credentials file returning `['user' => …, 'pass_hash' => …]`.
     * @return bool
     */
    #[NoDiscard('this is the gate\'s decision and nothing else; dropping it is a door left open')]
    public static function accepts(Request $request, string $file): bool
    {
        /** @var array{user: string, pass_hash: string} $creds */
        $creds = require $file;

        // An empty hash is an unconfigured gate rather than one that accepts an empty password.
        // password_verify() against '' is false anyway; the guard is here to say so out loud. It
        // stays ahead of the comparisons because there is no timing to protect on a gate that is
        // not configured — there is no right answer for the difference to be measured against.
        if ($creds['pass_hash'] === '') {
            return false;
        }

        // Both, always. See the note above on why this is not one `&&` chain.
        $userMatches     = hash_equals($creds['user'], $request->authUser());
        $passwordMatches = password_verify($request->authPassword(), $creds['pass_hash']);

        return $userMatches && $passwordMatches;
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
     * @return void
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
     * @return void
     */
    public static function requireAdminAuth(Request $request, ?string $file = null): void
    {
        if (!self::accepts($request, $file ?? Config::dataPath('admin.php'))) {
            self::challenge();
        }
    }

    /**
     * Sends the Basic Auth challenge and ends the request.
     *
     * @return never
     */
    private static function challenge(): never
    {
        header(new Header(ResponseHeader::WwwAuthenticate, self::challengeValue())->line());
        http_response_code(HttpStatusCode::Unauthorized->value);
        exit;
    }
}
