<?php

declare(strict_types=1);

namespace NeuroSYS\Http\Security;

/**
 * The CspScheme enum. Scheme sources — a whole URL scheme allowed regardless of host.
 *
 * The trailing colon is part of the token. `data` without it is parsed as a host name.
 */
enum CspScheme: string implements CspSource
{
    /** Inline `data:` URIs. Needed by the cover placeholder SVG's own referenced assets. */
    case Data = 'data:';

    /** Any origin, as long as it is HTTPS. Broad — prefer a named host. */
    case Https = 'https:';

    /** Object URLs created by `URL.createObjectURL`. */
    case Blob = 'blob:';

    public function source(): string
    {
        return $this->value;
    }
}
