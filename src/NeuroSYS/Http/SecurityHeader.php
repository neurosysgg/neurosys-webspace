<?php

declare(strict_types=1);

namespace NeuroSYS\Http;

/**
 * The SecurityHeader enum. The response headers {@link SecurityHeaders} sends.
 *
 * Backed by the literal header name, so the wire format lives here rather than in a string
 * scattered through a `header()` call.
 */
enum SecurityHeader: string
{
    /** Restricts where the page may load anything from. */
    case ContentSecurityPolicy = 'Content-Security-Policy';

    /** Controls how much of the current URL is sent as `Referer` on an outgoing request. */
    case ReferrerPolicy = 'Referrer-Policy';

    /** Stops the browser second-guessing a declared Content-Type. */
    case ContentTypeOptions = 'X-Content-Type-Options';

    /** Switches off browser features the site never uses. */
    case PermissionsPolicy = 'Permissions-Policy';

    /** Formats the header for {@link \header()}: `Name: value`. */
    public function line(string $value): string
    {
        return $this->value . ': ' . $value;
    }
}
