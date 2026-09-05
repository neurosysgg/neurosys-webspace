<?php

declare(strict_types=1);

namespace NeuroSYS\Http;

use NeuroSYS\Exception\SecurityPolicyException;

/**
 * The Vary class. Which request headers a stored response depends on.
 *
 * Built from {@link RequestHeader} cases rather than from their names, which is the point: `Vary`
 * names *other headers*, so writing it as a string means one header's name spelled twice in two
 * places — exactly the drift `RequestHeader` exists to stop. The one this site sends is
 * `X-Requested-With`, and it is load-bearing: {@link ViewResponse} answers one URL with a whole
 * document or a content fragment depending on that header, so a cache that did not know would be
 * free to hand either to the other.
 *
 * Variadic and refusing an empty list, the same shape as {@link CacheControl::of()} and
 * {@link Security\PermissionsPolicy::deny()}. `Vary: *` is deliberately not offered — it means "do
 * not reuse this at all", which is {@link CacheControl}'s job to say and says it better.
 */
final readonly class Vary implements HeaderValue
{
    /** @param list<RequestHeader> $headers */
    private function __construct(private array $headers) {}

    /**
     *
     * @param RequestHeader ...$headers
     * @return self
     * @throws SecurityPolicyException if no header is given — an empty `Vary` is malformed, and a
     *                                 response that depends on nothing simply omits the header.
     */
    public static function on(RequestHeader ...$headers): self
    {
        if ($headers === []) {
            throw new SecurityPolicyException(
                'Vary::on() needs at least one header; omit the header instead.',
            );
        }

        return new self(array_values($headers));
    }

    /**
     * Returns the header value: `X-Requested-With`.
     *
     * @return string
     */
    public function render(): string
    {
        return implode(', ', array_map(
            static fn(RequestHeader $header): string => $header->value,
            $this->headers,
        ));
    }
}
