<?php
declare(strict_types=1);

namespace NeuroSYS\Http;

/**
 * The Request class. Represents an incoming HTTP request.
 *
 * Constructed from PHP's global server variables via {@link fromGlobals()}.
 */
readonly class Request
{
    /**
     * Constructs an instance of {@link self}.
     */
    private function __construct(
        private string $path,
        private array  $segments,
        private bool   $ajax,
        private string $authUser,
        private string $authPassword,
    ) {}

    /**
     * Creates an instance from PHP's global server variables.
     *
     * Handles the Authorization header fallback required on some shared hosts
     * where Apache strips PHP_AUTH_* variables before they reach PHP.
     */
    public static function fromGlobals(): static
    {
        $path     = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $path     = rtrim($path, '/') ?: '/';
        $segments = array_values(array_filter(explode('/', ltrim($path, '/'))));

        $ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
             && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        $user = $_SERVER['PHP_AUTH_USER'] ?? '';
        $pass = $_SERVER['PHP_AUTH_PW']   ?? '';

        // Authorization header fallback for hosts that strip PHP_AUTH_* vars
        if ($user === '' && isset($_SERVER['HTTP_AUTHORIZATION'])
            && str_starts_with($_SERVER['HTTP_AUTHORIZATION'], 'Basic ')) {
            [, $b64] = explode(' ', $_SERVER['HTTP_AUTHORIZATION'], 2);
            [$user, $pass] = explode(':', base64_decode($b64), 2) + ['', ''];
        }

        return new static($path, $segments, $ajax, $user, $pass);
    }

    /** Returns the normalized request path without trailing slash. */
    public function path(): string         { return $this->path; }
    /** Returns the path segments split on '/'. */
    public function segments(): array      { return $this->segments; }
    /** Returns true if the request was made via XMLHttpRequest. */
    public function isAjax(): bool         { return $this->ajax; }
    /** Returns the HTTP Basic Auth username, or an empty string if not provided. */
    public function authUser(): string     { return $this->authUser; }
    /** Returns the HTTP Basic Auth password, or an empty string if not provided. */
    public function authPassword(): string { return $this->authPassword; }
}
