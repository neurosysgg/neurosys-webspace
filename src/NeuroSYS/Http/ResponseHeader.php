<?php

declare(strict_types=1);

namespace NeuroSYS\Http;

/**
 * The ResponseHeader enum. The headers a response sends that are not security headers.
 *
 * Those live in {@link SecurityHeader}, which is deliberately exhaustive — see {@link HeaderName}
 * for why the two lists stay apart.
 */
enum ResponseHeader: string implements HeaderName
{
    /** What the body is, and in which encoding. */
    case ContentType = 'Content-Type';

    /** Where a redirect points. */
    case Location = 'Location';

    /** Which methods a route accepts, sent with a 405. */
    case Allow = 'Allow';

    /** The Basic Auth challenge, sent with a 401. */
    case WwwAuthenticate = 'WWW-Authenticate';

    /**
     * Whether a response may be reused, and on what terms.
     *
     * Two answers on this site, and they are opposites. Every public document says `no-cache`,
     * which is not `no-store`: keep it, but ask before reusing it — see {@link ViewResponse}.
     * The one page behind a gate says `no-store, private` instead, and {@link
     * \NeuroSYS\Controller\StatsController} sets that itself.
     */
    case CacheControl = 'Cache-Control';

    /**
     * A validator for the body, so a revalidation can come back as a 304 instead of the page.
     *
     * {@link ViewResponse} hashes what it is about to send. That is the whole trick behind the
     * caching here: a document embeds every versioned asset URL, so a rebuild changes the body,
     * which changes this, which retires the cached copy — no coupling to the build stamp needed,
     * because the dependency is already in the bytes.
     */
    case ETag = 'ETag';

    /**
     * Which request headers the stored response depends on.
     *
     * Load-bearing here rather than decorative: {@link ViewResponse} answers one URL with two
     * different bodies depending on `X-Requested-With` — a whole document to a browser, a
     * fragment to `Navigation`. Without this a cache is entitled to hand either one to the other,
     * and the fragment landing on a navigation is a blank page. It was harmless while nothing
     * cached; it stopped being harmless the moment {@link self::ETag} appeared.
     */
    case Vary = 'Vary';

    /**
     * The one case here that names a header the site does **not** send.
     *
     * PHP adds it, with its exact patch version, before any of our code runs.
     * {@link SecurityHeaders::send()} removes it. It is named here rather than written as a
     * string literal there for the same reason every other header name is.
     */
    case PoweredBy = 'X-Powered-By';

    /**
     * @return string
     */
    public function headerName(): string
    {
        return $this->value;
    }
}
