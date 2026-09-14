<?php

declare(strict_types=1);

namespace NeuroSYS\Service;

use NeuroSYS\Model\Demo;
use NeuroSYS\Site;
use NoDiscard;
use Phpanta\Http\BasicChallenge;
use Phpanta\Http\Request;
use Phpanta\Http\Response;
use Phpanta\Service\Auth;
use Phpanta\Support\PasswordHash;

/**
 * The DemoGate class. One demo's password, in front of its page and its audio.
 *
 * This site's gate, built on the framework's: {@link Auth::matches()} is still the only place a
 * credential is compared, and {@link Auth::challenge()} the only place a 401 is built. What is this
 * site's is everything that knows what a {@link Demo} is — a password per demo, a realm per slug,
 * and a refusal that cannot tell a missing demo from a wrong password.
 *
 * The decision and the 401 are split the way {@link Auth} splits them: {@link self::admits()} is
 * what a test can assert, and {@link self::enter()} is only the challenge around it.
 */
final class DemoGate
{
    /**
     * True if $request carries $demo's password.
     *
     * The user name is {@link Site::DEMO_USER} for every demo and is not a secret — the password
     * is the whole credential, and the realm is what keeps one demo's from being offered for
     * another. So the timing argument on {@link Auth::matches()} does not apply here in the way it
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
        return Auth::matches($request, Site::DEMO_USER, $demo->password);
    }

    /**
     * Enforces one demo's own password, and hands back the demo it let through.
     *
     * There is no unconfigured case here: a {@link Demo} cannot be constructed without a
     * {@link PasswordHash}, so a demo that is reachable is a demo that is gated. A credential's
     * absence must never be how a gate is switched off.
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
     * {@link Auth::matches()}'s refusal to short-circuit, applied one level out.
     *
     * It answers the demo, or the 401 to return in its place. A caller that has been let through
     * holds a non-null {@link Demo} and never re-checks for a null that cannot occur; a caller that
     * has not holds the refusal and returns it — `if ($demo instanceof Response) { return $demo; }`
     * — which the attribute makes impossible to drop without a failing test.
     *
     * @param Request   $request The incoming request.
     * @param string    $slug    The demo's slug, which is what its realm is named for.
     * @param Demo|null $demo    The demo being asked for, or null where the slug names none.
     * @return Demo|Response
     */
    #[NoDiscard('the refusal is only sent if it is returned; dropping it is a door left open')]
    public static function enter(Request $request, string $slug, ?Demo $demo): Demo|Response
    {
        if ($demo === null) {
            // Spend what a real comparison would have. See the note above; the (void) is there to
            // say the answer is not the point, because the answer is always false.
            (void) Auth::matches($request, Site::DEMO_USER, PasswordHash::unmatchable());

            return Auth::challenge(self::realm($slug));
        }

        return self::admits($request, $demo) ? $demo : Auth::challenge(self::realm($slug));
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
     * into a 500 where a 401 belongs. `rawurlencode()` means there is nothing to refuse.
     *
     * It is also the treatment {@link \NeuroSYS\Support\SitePath::to()} already gives the same
     * value on the way out, and a no-op for every slug `tools/stage-demo.php` can mint — so the
     * realm a real demo is keyed by is unchanged, which is what matters for a credential a browser
     * has already saved.
     *
     * @param string $slug
     * @return BasicChallenge
     */
    private static function realm(string $slug): BasicChallenge
    {
        return new BasicChallenge(Site::NAME . ' demo: ' . rawurlencode($slug));
    }
}
