<?php

declare(strict_types=1);

namespace NeuroSYS\Service;

use NeuroSYS\Config;
use NeuroSYS\DataFile;
use NeuroSYS\Http\BasicChallenge;
use NeuroSYS\Http\Header;
use NeuroSYS\Http\HttpStatusCode;
use NeuroSYS\Http\Request;
use NeuroSYS\Http\ResponseHeader;
use NeuroSYS\Model\Demo;
use NeuroSYS\Support\File;
use NeuroSYS\Support\PasswordHash;
use NoDiscard;

/**
 * The Auth class. Provides HTTP Basic Authentication gates for the site.
 *
 * The decision and the 401 are separate, the same way {@link \NeuroSYS\Http\SecurityHeaders}
 * separates `headers()` from `send()`, and for the same reason: a gate that ends the request
 * cannot be asserted against in-process, so everything worth asserting lives in
 * {@link self::accepts()} and {@link self::admits()}, and the three `require*` methods are only the
 * challenge around them.
 *
 * That split is what the {@link \NeuroSYS\Test\Unit\AdminTest} needs to exist. Before it, the
 * credential comparison had never run under either suite: `data/admin.php` ships with an empty
 * `pass_hash`, so the guard short-circuits and neither `hash_equals()` nor `password_verify()`
 * is reached — which means the two `/admin/stats → 401` checks in `test/basic_test.sh` prove the
 * route is gated, not that the comparison works.
 *
 * **Three gates now, and they differ in where the credential comes from rather than in what is
 * done with it.** The site gate and the admin gate read a `data/` file returning a user and a hash;
 * a demo carries its own hash as a {@link PasswordHash} on the {@link Demo} object, one per demo.
 * All three end up in {@link self::matches()}, which is the only place a credential is compared.
 */
class Auth
{
    /**
     * The challenge the two file-backed gates answer a 401 with.
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
     * The challenge one demo answers a 401 with — its own realm, not the site's.
     *
     * **The realm is the per-demo part of the credential**, and getting it wrong would be quiet:
     * the browser keys saved credentials by realm, so one shared realm across every demo means the
     * password saved for the first is offered automatically for the second, and the second's gate
     * refuses it with a prompt that looks like the visitor typed something wrong. Worse in the
     * other direction — it is the mechanism by which a browser would volunteer a credential to a
     * page it was never given for.
     *
     * **The slug is encoded, because it is the one part of a realm a visitor writes.** It arrives
     * from the URL, and a demo that does not exist is challenged exactly like one that does — so
     * this is reached for any `/demos/…` target at all, including one no route was meant to claim.
     * {@link BasicChallenge} refuses a realm holding a `"` or a `\`, which is the right answer for a
     * realm built wrong in this repository and the wrong one here: it would turn a hostile target
     * into a 500 where a 401 belongs, which is both a crash and louder than the malformed header it
     * replaced. `rawurlencode()` means there is nothing to refuse.
     *
     * It is also the treatment {@link \NeuroSYS\Support\SitePath::to()} already gives the same
     * value on the way out, and a no-op for every slug `tools/stage-demo.php` can mint — so the
     * realm a real demo is keyed by is unchanged, which is what matters for a credential a browser
     * has already saved.
     *
     * @param string $slug
     * @return BasicChallenge
     */
    private static function demoRealm(string $slug): BasicChallenge
    {
        return new BasicChallenge(Config::NAME . ' demo: ' . rawurlencode($slug));
    }

    /**
     * True if $request carries the credentials $file holds.
     *
     * Both file-backed gates ask the same question of the same shape of file, so they ask it in one
     * place. The comparison itself is {@link self::matches()}.
     *
     * @param Request $request The request whose Basic Auth credentials to check.
     * @param File    $file    A credentials file returning `['user' => …, 'pass_hash' => …]`.
     * @return bool
     */
    #[NoDiscard('this is the gate\'s decision and nothing else; dropping it is a door left open')]
    public static function accepts(Request $request, File $file): bool
    {
        // `require` is a language construct and takes a path: a credentials file is PHP this
        // executes, not bytes it reads, and File::read() is deliberately not a way to run one.
        /** @var array{user: string, pass_hash: string} $creds */
        $creds = require $file->path;

        // An empty hash is an unconfigured gate rather than one that accepts an empty password, and
        // PasswordHash::configured() is where that distinction now lives — it answers null for the
        // empty string and throws for a digest that is neither empty nor bcrypt. The guard stays
        // ahead of the comparison because there is no timing to protect on a gate that is not
        // configured: there is no right answer for the difference to be measured against.
        $hash = PasswordHash::configured($creds['pass_hash']);

        return $hash !== null && self::matches($request, $creds['user'], $hash);
    }

    /**
     * True if $request carries $demo's password.
     *
     * The decision half of the demo gate, public for the reason {@link self::accepts()} is: it is
     * the only part a test can assert, because {@link self::requireDemoAuth()} ends the request.
     *
     * The user name is {@link Config::DEMO_USER} for every demo and is not a secret — the password
     * is the whole credential, and the realm is what keeps one demo's from being offered for
     * another. So the timing argument on {@link self::matches()} does not apply here in the way it
     * does to the admin gate, where the user name is something an attacker would like to learn. The
     * comparison runs both halves anyway, because it is the same routine.
     *
     * @param Request $request
     * @param Demo    $demo
     * @return bool
     */
    #[NoDiscard('this is the demo gate\'s decision and nothing else; dropping it is a door left open')]
    public static function admits(Request $request, Demo $demo): bool
    {
        return self::matches($request, Config::DEMO_USER, $demo->password);
    }

    /**
     * Compares a request's Basic Auth credentials against a user name and a hash.
     *
     * The one place a credential is checked, whichever gate asked. Every comparison is
     * constant-time: the password because that is what `password_verify()` is, the user name
     * because it is compared on every request just the same.
     *
     * **And neither is skipped when the other fails.** Chaining the two with `&&` made the pair
     * leak what each one individually does not: bcrypt is deliberately slow, so a wrong user name
     * came back in microseconds while a right one paid the full cost, and that difference is
     * measurable across a network. It tells an attacker which half of the credential they have
     * already got — which is the half they cannot otherwise find out, since the password is the one
     * a brute-force attempt gets feedback on. So both run, every time, and the results are combined
     * afterwards.
     *
     * @param Request      $request
     * @param string       $user The user name this gate expects.
     * @param PasswordHash $hash The digest to verify the password against.
     * @return bool
     */
    private static function matches(Request $request, string $user, PasswordHash $hash): bool
    {
        // Both, always. See the note above on why this is not one `&&` chain.
        $userMatches     = hash_equals($user, $request->authUser());
        $passwordMatches = $hash->matches($request->authPassword());

        return $userMatches && $passwordMatches;
    }

    /**
     * Enforces site-wide pre-launch authentication if a credentials file exists.
     *
     * Exits with a 401 if the credentials are wrong. Does nothing at all if the credentials file
     * is absent — that absence is how pre-launch auth is switched off, and `data/site_auth.php`
     * is gitignored precisely so the repo copy cannot switch it on.
     *
     * @param Request   $request The incoming request.
     * @param File|null $file    The credentials file; defaults to `data/site_auth.php`.
     * @return void
     */
    public static function requireSiteAuth(Request $request, ?File $file = null): void
    {
        $file ??= Config::dataFile(DataFile::SiteAuth);

        if (!$file->exists()) {
            return;
        }

        if (!self::accepts($request, $file)) {
            self::challenge(self::challengeValue());
        }
    }

    /**
     * Enforces admin authentication for protected routes (e.g. /admin/stats).
     *
     * Exits with a 401 if the credentials do not match. Unlike the site gate there is no absent-file
     * case: a missing `data/admin.php` is a broken deployment, and `require` says so loudly rather
     * than leaving the admin routes open.
     *
     * @param Request   $request The incoming request.
     * @param File|null $file    The credentials file; defaults to `data/admin.php`.
     * @return void
     */
    public static function requireAdminAuth(Request $request, ?File $file = null): void
    {
        if (!self::accepts($request, $file ?? Config::dataFile(DataFile::Admin))) {
            self::challenge(self::challengeValue());
        }
    }

    /**
     * Enforces one demo's own password, and hands back the demo it let through.
     *
     * There is no unconfigured case here, the way there is for the site gate: a {@link Demo} cannot
     * be constructed without a {@link PasswordHash}, so a demo that is reachable is a demo that is
     * gated. `data/site_auth.php`'s absence switching a gate *off* is deliberate there and must not
     * be possible here.
     *
     * **This is the gate on the audio as well as on the page.** The files live under `data/`, which
     * Apache does not serve, so {@link \NeuroSYS\Controller\DemoAudioController} calls this too —
     * the two routes ask the same question, and neither is the one that matters more.
     *
     * **A demo that does not exist is refused in exactly the same way as one whose password was
     * wrong**, which is why `$demo` is nullable and why this is a gate rather than a lookup. A 404
     * for an unknown slug and a 401 for a known one would let anyone read the catalogue off the
     * status code — and the catalogue is a list of unreleased tracks, which is the one thing this
     * whole arrangement exists to keep quiet. `/demos` is not a route and `data/demos.php` is
     * gitignored for the same reason; a status code that answers the question anyway would undo
     * both.
     *
     * The wasted comparison against {@link PasswordHash::unmatchable()} is the other half of that,
     * and it is not decoration: returning early on a null demo answers in microseconds where a real
     * demo pays bcrypt, so the uniform 401 would be undone by a stopwatch. Same reasoning as
     * {@link self::matches()}'s refusal to short-circuit, applied one level out.
     *
     * It returns the demo because the `never` below is something the type system cannot see past:
     * a caller that has been let through holds a non-null {@link Demo}, and saying so here is
     * better than re-checking for a null that cannot occur.
     *
     * @param Request   $request The incoming request.
     * @param string    $slug    The demo's slug, which is what its realm is named for.
     * @param Demo|null $demo    The demo being asked for, or null where the slug names none.
     * @return Demo
     */
    public static function requireDemoAuth(Request $request, string $slug, ?Demo $demo): Demo
    {
        if ($demo === null) {
            // Spend what a real comparison would have. See the note above; the (void) is there to
            // say the answer is not the point, because the answer is always false.
            (void) self::matches($request, Config::DEMO_USER, PasswordHash::unmatchable());
            self::challenge(self::demoRealm($slug));
        }

        if (!self::admits($request, $demo)) {
            self::challenge(self::demoRealm($slug));
        }

        return $demo;
    }

    /**
     * Sends the Basic Auth challenge and ends the request.
     *
     * @param BasicChallenge $challenge The realm to prompt in — the site's, or one demo's.
     * @return never
     */
    private static function challenge(BasicChallenge $challenge): never
    {
        header(new Header(ResponseHeader::WwwAuthenticate, $challenge)->line());
        http_response_code(HttpStatusCode::Unauthorized->value);
        exit;
    }
}
