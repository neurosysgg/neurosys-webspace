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
        private string $ifNoneMatch = '',
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

        $ajax = RequestedWith::XmlHttpRequest->matches(self::header(RequestHeader::RequestedWith));

        $user = $_SERVER['PHP_AUTH_USER'] ?? '';
        $pass = $_SERVER['PHP_AUTH_PW']   ?? '';

        $authorization = self::authorization();

        // Authorization header fallback for hosts that strip PHP_AUTH_* vars
        if ($user === '' && str_starts_with($authorization, 'Basic ')) {
            [, $b64] = explode(' ', $authorization, 2);
            [$user, $pass] = explode(':', base64_decode($b64), 2) + ['', ''];
        }

        return new static($method, $path, $ajax, $user, $pass, self::header(RequestHeader::IfNoneMatch));
    }

    /**
     * One request header's value, or `''` if it did not arrive.
     *
     * The `$_SERVER` key is derived from the {@link RequestHeader} case rather than retyped —
     * `HTTP_` plus the name upper-cased with dashes as underscores, which is PHP's transform and
     * not ours. That is the whole reason the header names are an enum: the client sends
     * `X-Requested-With` and this reads the same string, put through the same rule.
     */
    private static function header(RequestHeader $header): string
    {
        $key = 'HTTP_' . str_replace('-', '_', strtoupper($header->value));

        return isset($_SERVER[$key]) && is_string($_SERVER[$key]) ? $_SERVER[$key] : '';
    }

    /**
     * The `Authorization` header, under either of the two names it can arrive as.
     *
     * Not {@link self::header()}, because this one is not passed through: Apache deliberately keeps
     * `Authorization` out of the CGI environment, so `public/.htaccess` puts it back with
     * `RewriteRule ^ - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`. An environment variable set
     * before an internal redirect arrives on the other side renamed with a `REDIRECT_` prefix, and
     * the request reaches PHP through exactly such a redirect — the rewrite to `index.php`.
     *
     * That rule's pattern is `^`, so in practice it fires again on the redirected request and the
     * unprefixed name is defined too. In practice, though, is not a good enough standard for the
     * one header both auth gates depend on: this fails **closed** and in silence — a 401 that
     * looks exactly like a wrong password. So both spellings are read, the way the cache tiers in
     * the same file set both `VERSIONED` and `REDIRECT_VERSIONED` for the same reason.
     */
    private static function authorization(): string
    {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            if (isset($_SERVER[$key]) && is_string($_SERVER[$key]) && $_SERVER[$key] !== '') {
                return $_SERVER[$key];
            }
        }

        return '';
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
    /**
     * Returns the `If-None-Match` validator the browser sent back, or `''` if it sent none.
     *
     * Compared verbatim by {@link ViewResponse}: a browser echoes the `ETag` it was given, and the
     * only thing worth asking is whether it is the one we would send now.
     */
    public function ifNoneMatch(): string   { return $this->ifNoneMatch; }
}
