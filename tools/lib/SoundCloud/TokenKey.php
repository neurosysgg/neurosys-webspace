<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\SoundCloud;

/**
 * The TokenKey enum. The keys a token is written under, on the wire and on disk.
 *
 * Three of these were the most-repeated strings in this namespace: `access_token` appeared four
 * times in {@link AccessToken} alone, `refresh_token` three more there and twice again in
 * {@link Client}. That is the criterion `\NeuroSYS\Config`'s docblock names — *already stated twice*
 * — met several times over, and it is met inside the one class where a misread key means the token
 * silently reads as absent and the account has to be authorized by hand again.
 *
 * **One enum and not two, though {@link self::ExpiresIn} is SoundCloud's and {@link self::ExpiresAt}
 * is ours.** They are the two forms of one fact: a duration when the token endpoint states it, an
 * instant once {@link AccessToken::fromResponse()} has added it to the clock. Splitting them into a
 * wire enum and a store enum would put the two halves of that conversion in different files and
 * leave three cases duplicated across both. Keeping them here is what makes the difference visible
 * at the moment somebody reaches for the wrong one.
 *
 * Read and written through {@link \NeuroSYS\Tool\Http\JsonBody} and
 * {@link AccessToken::toArray()}, neither of which will take a string.
 */
enum TokenKey: string
{
    /** The bearer value itself, sent back as `OAuth <value>`. */
    case AccessToken = 'access_token';

    /** **SoundCloud's**: how many seconds the value is good for, counted from the answer. */
    case ExpiresIn = 'expires_in';

    /** **Ours**: the Unix time it stops being good, which is what {@link TokenStore} keeps. */
    case ExpiresAt = 'expires_at';

    /** Single-use and rotating — the valuable half, and the reason the store's write is atomic. */
    case RefreshToken = 'refresh_token';

    /** What the token is good for, as the server stated it. Recorded, never asserted against. */
    case Scope = 'scope';
}
