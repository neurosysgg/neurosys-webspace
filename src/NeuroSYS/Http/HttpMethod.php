<?php

declare(strict_types=1);

namespace NeuroSYS\Http;

/**
 * The HttpMethod enum. The HTTP methods this site recognises.
 *
 * Recognising one is not the same as accepting it: the site is read-only, so everything but
 * {@link self::isReadOnly()} is refused with a 405. A method that is not a case here — a typo, a
 * WebDAV verb, anything — is not read-only either, which is why {@link Request::method()} is
 * nullable rather than defaulting to GET.
 */
enum HttpMethod: string
{
    case Get     = 'GET';
    case Head    = 'HEAD';
    case Post    = 'POST';
    case Put     = 'PUT';
    case Patch   = 'PATCH';
    case Delete  = 'DELETE';
    case Options = 'OPTIONS';
    case Trace   = 'TRACE';

    /** True if the method only reads. */
    public function isReadOnly(): bool
    {
        return match ($this) {
            self::Get, self::Head => true,
            default               => false,
        };
    }

    /**
     * The value of the `Allow` header a 405 carries.
     *
     * Derived from {@link self::isReadOnly()} rather than written out, so the header cannot say
     * something the gate does not do. Marking a method read-only used to mean remembering to edit
     * a string in {@link \NeuroSYS\Router} as well.
     */
    public static function allowed(): string
    {
        return implode(', ', array_map(
            static fn(self $method): string => $method->value,
            array_filter(self::cases(), static fn(self $method): bool => $method->isReadOnly()),
        ));
    }
}
