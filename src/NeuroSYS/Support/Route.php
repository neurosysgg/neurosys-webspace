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
    public function __construct(
        private string  $pattern,
        private Closure $factory,
    ) {}

    /**
     * Tests whether this route matches $path.
     *
     * @return array<int,string>|false Positional capture values on match, false otherwise.
     */
    public function matches(string $path): array|false
    {
        $regex = '@^' . preg_replace('/\{(\w+)\}/', '([^/]+)', $this->pattern) . '$@';
        if (!preg_match($regex, $path, $m)) {
            return false;
        }
        array_shift($m);
        return $m;
    }

    /** Invokes the factory with the captured params and returns the resulting Controller. */
    public function createController(array $params): Controller
    {
        return ($this->factory)(...$params);
    }
}
