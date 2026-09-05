<?php

declare(strict_types=1);

namespace NeuroSYS\Http;

use Uri\Rfc3986\Uri;

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
        $path     = self::normalisePath($_SERVER['REQUEST_URI'] ?? '/');

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

    /**
     * The request target's path, with any trailing slash taken off.
     *
     * This was `parse_url()` and an `is_string()` guard, and the guard was the whole point of the
     * method: `parse_url()` signals failure with **false**, not null, so the `?? '/'` it replaced
     * read as a guard and was not one, and under `strict_types=1` the false went on to `rtrim()`
     * as an uncaught TypeError. What produced it was not exotic — `GET ///` was enough — and it
     * was a 500 where the router's 404 belongs, raised here in {@link self::fromGlobals()}, ahead
     * of {@link \NeuroSYS\Router::dispatch()} and so ahead of the read-only method gate too.
     *
     * PHP 8.5's URI parser removes the trap rather than guarding against it: {@link Uri::parse()}
     * returns **null** on a target it cannot read, which is what `??` was always looking for. It
     * also reads one this could not: `///` is the root written wastefully and now comes back as
     * the root, instead of failing and falling through.
     *
     * **The fallback is the target verbatim, not `/`.** A target this could not read is not a
     * request for the home page, and answering one with the home page is the quiet kind of wrong:
     * no route pattern matches a malformed target, so handing it through unchanged 404s the way
     * every other unknown path does. Same instinct as `HttpMethod::tryFrom()` returning null rather
     * than guessing GET. An *absent* `REQUEST_URI` is the different case and is still the root —
     * that default is applied by the caller, before this ever sees it.
     *
     * Raw, not decoded: a route matches the target as it was sent, the way `parse_url()` gave it.
     *
     * @param string $uri The raw request target, as `REQUEST_URI` carries it.
     */
    private static function normalisePath(string $uri): string
    {
        // `?:` so a target of only slashes comes back as the root rather than as an empty string.
        return rtrim(Uri::parse($uri)?->getRawPath() ?? $uri, '/') ?: '/';
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
