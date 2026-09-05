<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\SoundCloud;

use NeuroSYS\Http\HeaderValue;

/**
 * The OAuthCredential class. What goes after `Authorization: ` on a request to SoundCloud.
 *
 * A {@link HeaderValue} for the reason that interface exists: a header value has a *grammar*, and
 * `'OAuth ' . $token->value` assembled at a call site is exactly where a grammar goes to be got
 * wrong. The site's {@link \NeuroSYS\Http\BasicChallenge} is the same shape pointing the other way
 * — that one is the challenge a response sends, this is the credential a request carries.
 *
 * **The scheme is `OAuth`, not `Bearer`.** SoundCloud's own examples say so, and it is the sort of
 * thing that reads as a typo to anyone who has met an API before, which is why it is written down
 * once and in a class named for it.
 */
final readonly class OAuthCredential implements HeaderValue
{
    /** SoundCloud's scheme name, however much it looks like it should be `Bearer`. */
    private const string SCHEME = 'OAuth';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param AccessToken $token
     */
    public function __construct(private AccessToken $token) {}

    /**
     * @return string
     */
    public function render(): string
    {
        return self::SCHEME . ' ' . $this->token->value;
    }
}
