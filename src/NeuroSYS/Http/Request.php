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
        private string $method,
        private string $path,
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
        $method   = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path     = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $path     = rtrim($path, '/') ?: '/';

        $ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
             && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        $user = $_SERVER['PHP_AUTH_USER'] ?? '';
        $pass = $_SERVER['PHP_AUTH_PW']   ?? '';

        // Authorization header fallback for hosts that strip PHP_AUTH_* vars
        if (
            $user === '' && isset($_SERVER['HTTP_AUTHORIZATION'])
            && str_starts_with($_SERVER['HTTP_AUTHORIZATION'], 'Basic ')
        ) {
            [, $b64] = explode(' ', $_SERVER['HTTP_AUTHORIZATION'], 2);
            [$user, $pass] = explode(':', base64_decode($b64), 2) + ['', ''];
        }

        return new static($method, $path, $ajax, $user, $pass);
    }

    /** Returns the HTTP method, upper-cased; defaults to GET. */
    public function method(): string       { return $this->method; }
    /**
     * Returns true if the method only reads. The whole site is read-only, so everything
     * else is refused with a 405 rather than silently treated as a GET.
     */
    public function isReadOnly(): bool     { return $this->method === 'GET' || $this->method === 'HEAD'; }
    /** Returns the normalized request path without trailing slash. */
    public function path(): string         { return $this->path; }
    /** Returns true if the request was made via XMLHttpRequest. */
    public function isAjax(): bool         { return $this->ajax; }
    /** Returns the HTTP Basic Auth username, or an empty string if not provided. */
    public function authUser(): string     { return $this->authUser; }
    /** Returns the HTTP Basic Auth password, or an empty string if not provided. */
    public function authPassword(): string { return $this->authPassword; }
}
