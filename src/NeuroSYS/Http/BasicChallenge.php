<?php

declare(strict_types=1);

namespace NeuroSYS\Http;

/**
 * The BasicChallenge class. The `WWW-Authenticate` value both gates answer a 401 with.
 *
 * `Basic realm="…"`, and the quotes around the realm are grammar rather than decoration — the same
 * reason {@link ETag} owns its own. What makes it worth a type beyond that is the realm itself:
 * **the browser keys stored credentials by realm**, so two challenges differing by a character are
 * two separate password prompts to the same visitor, and neither the site gate nor the admin gate
 * would look wrong on its own.
 *
 * Only `Basic` is offered because only Basic is used, and because the scheme is not a detail to
 * pass in: a `Digest` or `Bearer` challenge has a different grammar and would be a different named
 * constructor rather than a different string.
 */
final readonly class BasicChallenge implements HeaderValue
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $realm What the browser labels and keys the saved credentials by. On this site
     *                      it is {@link \NeuroSYS\Config::NAME}, for both gates.
     */
    public function __construct(private string $realm) {}

    /**
     * Returns the header value: `Basic realm="neuro.SYS"`.
     *
     * @return string
     */
    public function render(): string
    {
        return 'Basic realm="' . $this->realm . '"';
    }
}
