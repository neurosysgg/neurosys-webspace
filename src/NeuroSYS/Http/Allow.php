<?php

declare(strict_types=1);

namespace NeuroSYS\Http;

/**
 * The Allow class. Which methods a route accepts, sent with a 405.
 *
 * Built by filtering {@link HttpMethod::cases()} on {@link HttpMethod::isReadOnly()} rather than
 * written out, so the header cannot claim something the gate does not do. That derivation is the
 * whole value of the type: a hand-written `'Allow: GET, HEAD'` and the `if (!$request->isReadOnly())`
 * in {@link \NeuroSYS\Router} are two statements of one rule, and marking a method read-only used to
 * mean remembering to edit both.
 */
final readonly class Allow implements HeaderValue
{
    /** @param list<HttpMethod> $methods */
    private function __construct(private array $methods) {}

    /** Every method that only reads — which on this site is every method the router answers. */
    public static function readOnly(): self
    {
        return new self(array_values(array_filter(
            HttpMethod::cases(),
            static fn(HttpMethod $method): bool => $method->isReadOnly(),
        )));
    }

    /** Returns the header value: `GET, HEAD`. */
    public function render(): string
    {
        return implode(', ', array_map(
            static fn(HttpMethod $method): string => $method->value,
            $this->methods,
        ));
    }
}
