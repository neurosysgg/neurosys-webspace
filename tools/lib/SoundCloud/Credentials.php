<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\SoundCloud;

/**
 * The Credentials class. The app's own identity — what SoundCloud issues when an app is registered.
 *
 * **They come from the environment and never from a file in this repo.** `data/` is the only place
 * this codebase keeps a secret, and `deploy.sh` rsyncs `data/` to Strato — the two credential files
 * that live there are protected by an `--exclude` each, which is one line standing between a secret
 * and a public webroot. A client secret has no reason to be within reach of that rule, and an
 * environment variable is within reach of nothing.
 *
 * The redirect URI is a credential in the sense that matters here: it is registered with the app
 * and the authorization server compares it byte for byte, so it belongs beside the other two rather
 * than typed at a call site.
 */
final readonly class Credentials
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $clientId
     * @param string $clientSecret
     * @param string $redirectUri
     */
    public function __construct(
        public string $clientId,
        public string $clientSecret,
        public string $redirectUri,
    ) {}

    /**
     * The credentials in the environment, or null where any of the three is missing.
     *
     * Null rather than a partial object: two thirds of a credential authenticates nothing, and the
     * command's message about what to set is more useful than a 401 from the far end. Same instinct
     * as `HttpMethod::tryFrom()` returning null rather than guessing.
     *
     * @return self|null
     */
    public static function fromEnvironment(): ?self
    {
        $values = [];

        // Declaration order is the constructor's order, which is why the enum declares them in it.
        foreach (CredentialVariable::cases() as $variable) {
            if (($value = $variable->read()) === null) {
                return null;
            }

            $values[] = $value;
        }

        return new self(...$values);
    }
}
