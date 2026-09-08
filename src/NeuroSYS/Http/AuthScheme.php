<?php

declare(strict_types=1);

namespace NeuroSYS\Http;

use NeuroSYS\Support\BareArray;

/**
 * The AuthScheme enum. The authentication scheme both gates speak.
 *
 * One case, like {@link RequestedWith} and {@link \NeuroSYS\View\Html\LinkTarget} — it exists to
 * make the token a type, not to offer a choice. What made it worth writing is that the token was
 * spelled **twice, in two files, on the two sides of one handshake**: {@link BasicChallenge} wrote
 * `Basic realm="…"` into the 401, and {@link Request::fromGlobals()} matched `'Basic '` on the way
 * back in. Neither knew about the other.
 *
 * **And a mismatch there is the quietest failure on the site.** `Request::authorization()`'s own
 * docblock already names it about the header's *name*: the parse fails closed, so a visitor who
 * typed the right password is told, in the only way a browser can tell them, that they typed the
 * wrong one. It would look identical on both gates, on every attempt, with nothing in any log —
 * and the first guess at the cause would be the credentials file.
 *
 * The scheme is still not a parameter anywhere, which is the point {@link BasicChallenge} makes:
 * `Digest` and `Bearer` have different grammars, and offering one would be a second named
 * constructor rather than a second string. What the enum adds is that the grammar this one *does*
 * have — `Basic SP base64(user ":" pass)` — is written down once, in {@link self::credentials()},
 * instead of as two `explode()` calls in the middle of building a request.
 */
enum AuthScheme: string
{
    /**
     * HTTP Basic. Base64, not encryption — which is why
     * {@link Security\StrictTransportSecurity} is not optional here; see the site's `.htaccess`.
     */
    case Basic = 'Basic';

    /**
     * True if $authorization is a credential in this scheme.
     *
     * The space is part of the question and not decoration: `Basicxyz` starts with `Basic` and is
     * not a Basic credential. RFC 9110 separates the scheme from the parameters with at least one
     * space, and one is what every client sends.
     *
     * @param string $authorization A raw `Authorization` header value, or `''` if none arrived.
     * @return bool
     */
    public function carries(string $authorization): bool
    {
        return str_starts_with($authorization, $this->value . ' ');
    }

    /**
     * The user name and password inside $authorization, or two empty strings.
     *
     * `['', '']` for a header in another scheme, or for base64 that does not decode. That is the
     * same answer {@link Request} already gives for credentials that did not arrive at all, and it
     * is the right one: an unreadable credential is not a credential, and
     * {@link \NeuroSYS\Service\Auth} compares whatever it is handed in constant time either way.
     * Nothing here decides anything; it only reads.
     *
     * @param string $authorization A raw `Authorization` header value.
     * @return array{string, string} The user name and the password, in that order.
     */
    #[BareArray(
        'a tuple, not a group: a user name and a password are two roles rather than two items, and '
        . 'the destructuring at the call site is what says which is which.',
    )]
    public function credentials(string $authorization): array
    {
        if (!$this->carries($authorization)) {
            return ['', ''];
        }

        // Limit 2, so a password containing a space survives: the scheme is separated from its
        // parameters by the first space and by no other.
        [, $encoded] = explode(' ', $authorization, 2);

        // strict: true, so base64 that is not base64 comes back false rather than being silently
        // repaired into some other user's name. Same instinct as HttpMethod::tryFrom() refusing to
        // guess GET.
        $decoded = base64_decode($encoded, true);

        if ($decoded === false) {
            return ['', ''];
        }

        // Limit 2, and for a stronger reason than above: a colon is legal in a password and illegal
        // in a user name, so the first one is the separator and every later one is content.
        //
        // Padded rather than guarded, which is the behaviour this has always had and is pinned by a
        // test named for it: a payload with no colon at all is a user name and no password. That
        // reads as generous and is not — an empty password matches no stored bcrypt digest, so the
        // credential still fails; it just fails in Auth, where every other wrong credential does.
        [$user, $password] = explode(':', $decoded, 2) + ['', ''];

        return [$user, $password];
    }
}
