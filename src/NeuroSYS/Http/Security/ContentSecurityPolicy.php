<?php

declare(strict_types=1);

namespace NeuroSYS\Http\Security;

use NeuroSYS\Exception\SecurityPolicyException;

/**
 * The ContentSecurityPolicy class. A Content-Security-Policy assembled from typed parts.
 *
 * Replaces the hand-written string this used to be — a policy is now a set of
 * {@link CspDirective}s, each mapped to {@link CspSource}s, and the header text is generated.
 * A misspelled directive or an unquoted `self` stops being possible.
 *
 * Immutable: {@link self::allow()} returns a new instance, so a policy can be built up in a
 * readable chain without any step being able to mutate an earlier one.
 */
final readonly class ContentSecurityPolicy
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param array<string, list<CspSource>> $directives Source lists keyed by directive value.
     *                                                   Normally left empty and built with
     *                                                   {@link self::allow()}.
     */
    public function __construct(private array $directives = []) {}

    /**
     * Returns a copy of this policy with $directive allowed to load from $sources.
     *
     * @param CspSource ...$sources At least one. Use {@link CspKeyword::None} to allow nothing.
     *
     * @throws SecurityPolicyException if $sources is empty, or $directive is already set.
     */
    #[\NoDiscard('allow() returns a copy carrying the directive; a discarded one never reaches the header')]
    public function allow(CspDirective $directive, CspSource ...$sources): self
    {
        if ($sources === []) {
            throw new SecurityPolicyException(sprintf(
                "CSP directive '%s' needs at least one source; use CspKeyword::None to allow nothing.",
                $directive->value,
            ));
        }

        if (isset($this->directives[$directive->value])) {
            throw new SecurityPolicyException(sprintf(
                "CSP directive '%s' is already set. A browser honours the first occurrence and "
                . 'ignores the rest, so the second one would silently do nothing.',
                $directive->value,
            ));
        }

        return new self([...$this->directives, $directive->value => array_values($sources)]);
    }

    /** Returns the header value: `default-src 'self'; script-src 'self'; …`. */
    public function render(): string
    {
        $rendered = [];

        foreach ($this->directives as $directive => $sources) {
            $rendered[] = $directive . ' ' . implode(
                ' ',
                array_map(static fn(CspSource $source): string => $source->source(), $sources),
            );
        }

        return implode('; ', $rendered);
    }

    /**
     * Returns every host this policy names, for anything that needs to reason about the
     * origins the page may reach — the test suite asserts no unexpected one creeps in.
     *
     * @return list<string>
     */
    public function hosts(): array
    {
        $hosts = [];

        foreach ($this->directives as $sources) {
            foreach ($sources as $source) {
                if ($source instanceof CspHost) {
                    $hosts[] = $source->origin;
                }
            }
        }

        return array_values(array_unique($hosts));
    }
}
