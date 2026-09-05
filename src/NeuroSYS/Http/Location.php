<?php

declare(strict_types=1);

namespace NeuroSYS\Http;

use NeuroSYS\Exception\SecurityPolicyException;

/**
 * The Location class. Where a redirect points.
 *
 * The one header value on this site that carries a URL, and therefore the one where the type buys a
 * check rather than only a grammar: {@link self::verify()} refuses anything that is not an absolute
 * `https://` address. That is narrower than the spec allows — a relative `Location` is legal — and
 * narrower on purpose, for the same reason {@link \NeuroSYS\Model\Profile} is narrower than an
 * `href` in general. Every redirect this site issues goes to the file host, off-origin and over
 * TLS, so anything else is a mistake rather than a case to support.
 *
 * It is the counterpart to {@link \NeuroSYS\View\Html\Element}'s scheme check, one layer along:
 * that one governs a URL the browser is asked to *render*, this one a URL it is told to *follow*.
 * A `Location` was the one address the site emits that nothing had ever looked at.
 */
final readonly class Location implements HeaderValue
{
    /**
     * An absolute `https://` URL: a host, then optionally a path, query or fragment.
     *
     * `\S` throughout so no whitespace survives anywhere, and `\z` rather than `$` because `$` also
     * matches before a trailing newline — the same two details, for the same two reasons, as
     * {@link \NeuroSYS\Model\Profile::URL_PATTERN}. A newline in particular is what would turn a
     * redirect into header injection if PHP's own `header()` did not already refuse one.
     */
    private const string URL_PATTERN = '#^https://[^\s/]+(?:[/?\#]\S*)?\z#i';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $url The absolute address to redirect to.
     *
     * @throws SecurityPolicyException if it is not an absolute https:// URL.
     */
    public function __construct(private string $url)
    {
        $this->verify();
    }

    /**
     * Returns the header value: the URL, as given.
     *
     * @return string
     */
    public function render(): string
    {
        return $this->url;
    }

    /**
     *
     * @return void
     * @throws SecurityPolicyException
     */
    private function verify(): void
    {
        if (preg_match(self::URL_PATTERN, $this->url) !== 1) {
            throw new SecurityPolicyException(sprintf(
                "Location must be an absolute https:// URL, got '%s'.",
                $this->url,
            ));
        }
    }
}
