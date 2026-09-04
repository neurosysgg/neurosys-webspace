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

    public function headerName(): string
    {
        return $this->value;
    }
}
