<?php

declare(strict_types=1);

namespace NeuroSYS\Support;

use Closure;
use NeuroSYS\Controller\Controller;

/**
 * A registered route — a URL pattern paired with a factory that produces a Controller.
 *
 * Pattern syntax: static segments and `{param}` placeholders, e.g. `/releases/{slug}/{format}`.
 */
readonly class Route
{
    /**
     * @param string $pattern
     * @param Closure $factory
     */
    public function __construct(
        private string  $pattern,
        private Closure $factory,
    ) {}

    /**
     * Tests whether this route matches $path.
     *
     * @param string $path
     * @return array<int,string>|false Positional capture values on match, false otherwise.
     */
    public function matches(string $path): array|false
    {
        // \z rather than $: `$` also matches immediately before a trailing newline, so `$` would
        // let `/releases/ill\n` match and capture the newline into the slug. Not reachable today —
        // parse_url() does not decode %0a and Apache refuses a raw one in the request line — but
        // the anchor that means "the end" should be the one that says so.
        $regex = '@^' . preg_replace('/\{(\w+)\}/', '([^/]+)', $this->pattern) . '\z@';
        if (!preg_match($regex, $path, $m)) {
            return false;
        }
        array_shift($m);
        return $m;
    }

    /**
     * Invokes the factory with the captured params and returns the resulting Controller.
     *
     * @param array $params
     * @return Controller
     */
    public function createController(array $params): Controller
    {
        return ($this->factory)(...$params);
    }
}
