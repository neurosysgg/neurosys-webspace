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
        private ?HttpMethod $method,
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
        // tryFrom, not from: REQUEST_METHOD is whatever the client sent, and an unrecognised one
        // has to be refused rather than throw. Null is not read-only, which is the safe default.
        $method   = HttpMethod::tryFrom(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $path     = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $path     = rtrim($path, '/') ?: '/';

        // The header name in $_SERVER form: HTTP_ + upper-cased with dashes as underscores, which
        // is PHP's transform rather than ours, so it is derived from the case instead of retyped.
        $requestedWith = 'HTTP_' . str_replace('-', '_', strtoupper(RequestHeader::RequestedWith->value));

        $ajax = isset($_SERVER[$requestedWith])
             && RequestedWith::XmlHttpRequest->matches($_SERVER[$requestedWith]);

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

    /** Returns the HTTP method, or null if it is not one {@link HttpMethod} recognises. */
    public function method(): ?HttpMethod  { return $this->method; }
    /**
     * Returns true if the method only reads. The whole site is read-only, so everything
     * else is refused with a 405 rather than silently treated as a GET.
     */
    public function isReadOnly(): bool     { return $this->method?->isReadOnly() ?? false; }
    /** Returns the normalized request path without trailing slash. */
    public function path(): string         { return $this->path; }
    /** Returns true if the request was made via XMLHttpRequest. */
    public function isAjax(): bool         { return $this->ajax; }
    /** Returns the HTTP Basic Auth username, or an empty string if not provided. */
    public function authUser(): string     { return $this->authUser; }
    /** Returns the HTTP Basic Auth password, or an empty string if not provided. */
    public function authPassword(): string { return $this->authPassword; }
}
