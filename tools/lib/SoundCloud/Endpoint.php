<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\SoundCloud;

/**
 * The Endpoint enum. The three addresses this client talks to.
 *
 * Two hosts, and the split is the provider's: SoundCloud moved authorization and token exchange to
 * `secure.soundcloud.com` and left the API itself on `api.soundcloud.com`. Both are written here
 * once, for the reason {@link \NeuroSYS\Config} gives about `https://w.soundcloud.com` — an address
 * spelled twice is an address that can be changed once.
 *
 * **None of these reaches a page.** The site's output names no SoundCloud address at all, which is
 * a guarantee `docs/contracts.md` states and both test suites assert; these are a laptop talking to
 * an API, so no CSP source, no consent gate and no privacy claim follows from them.
 */
enum Endpoint: string
{
    /** Where the browser is sent once, to authorize this client against the account. */
    case Authorize = 'https://secure.soundcloud.com/authorize';

    /** Where a code becomes a token, and where a refresh token is spent for the next one. */
    case Token = 'https://secure.soundcloud.com/oauth/token';

    /** The collection an upload is `POST`ed to. */
    case Tracks = 'https://api.soundcloud.com/tracks';

    /**
     * One track's own address.
     *
     * @param int $id
     * @return string
     */
    public static function track(int $id): string
    {
        return self::Tracks->value . '/' . $id;
    }
}
