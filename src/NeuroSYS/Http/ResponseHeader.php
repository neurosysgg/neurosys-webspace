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

    /** How long a response may be kept. Sent with the pages that sit behind a gate. */
    case CacheControl = 'Cache-Control';

    /**
     * The one case here that names a header the site does **not** send.
     *
     * PHP adds it, with its exact patch version, before any of our code runs.
     * {@link SecurityHeaders::send()} removes it. It is named here rather than written as a
     * string literal there for the same reason every other header name is.
     */
    case PoweredBy = 'X-Powered-By';

    public function headerName(): string
    {
        return $this->value;
    }
}
