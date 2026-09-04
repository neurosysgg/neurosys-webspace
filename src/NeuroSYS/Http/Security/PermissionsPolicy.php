<?php

declare(strict_types=1);

namespace NeuroSYS\Http\Security;

use NeuroSYS\Exception\SecurityPolicyException;

/**
 * The PermissionsPolicy class. A `Permissions-Policy` header built from typed features.
 *
 * The site asks for none of these, so the only thing it ever expresses is denial — hence a
 * single {@link self::deny()} constructor rather than a general allow-list builder. If a
 * feature ever needs granting, that is a new named constructor, not a string edit.
 */
final readonly class PermissionsPolicy
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param list<PermissionsPolicyFeature> $denied
     */
    private function __construct(private array $denied) {}

    /**
     * Denies the given features to every origin, this one included.
     *
     * @throws SecurityPolicyException if no features are given — an empty Permissions-Policy
     *                                 header is not a weaker policy, it is a malformed one.
     */
    public static function deny(PermissionsPolicyFeature ...$features): self
    {
        if ($features === []) {
            throw new SecurityPolicyException(
                'PermissionsPolicy::deny() needs at least one feature; omit the header instead.',
            );
        }

        return new self(array_values($features));
    }

    /** Denies every feature {@link PermissionsPolicyFeature} knows about. */
    public static function denyAll(): self
    {
        return self::deny(...PermissionsPolicyFeature::cases());
    }

    /** Returns the header value: `geolocation=(), camera=(), …`. */
    public function render(): string
    {
        return implode(', ', array_map(
            static fn(PermissionsPolicyFeature $feature): string => $feature->denied(),
            $this->denied,
        ));
    }
}
