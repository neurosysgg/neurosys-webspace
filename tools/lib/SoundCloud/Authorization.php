<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\SoundCloud;

use NeuroSYS\Tool\Http\Url;

/**
 * The Authorization class. One authorization attempt: the secret it keeps and the URL it sends you
 * to.
 *
 * This is the half of OAuth that happens once. A browser is sent to SoundCloud, a human says yes,
 * and the redirect carries back a code that is worth nothing without the verifier this object is
 * still holding — which is what PKCE is for: the code travels through a browser, an address bar and
 * possibly a log, and the thing that redeems it never leaves the process that asked for it.
 *
 * **The parameter names are literals here, and enums elsewhere in this client.** The rule is the one
 * `docs/tooling.md` states about this client: a name is typed when getting it wrong is
 * *silent*. Misspell `code_challenge_method` and the authorization server refuses in words, on the
 * first attempt, in a browser. Misspell {@link TrackField::Title} and the upload succeeds with an
 * untitled track. Those are not the same risk and they do not get the same treatment.
 *
 * Both secrets are generated with `random_bytes()`, which is the only source in PHP that is a
 * cryptographic one by contract rather than by implementation.
 */
final readonly class Authorization
{
    /**
     * The response type asked for, and the only one this client can complete.
     *
     * SoundCloud's other flows either do not reach an upload — `client_credentials` gets a 401 on
     * `POST /tracks`, since an upload needs a user to belong to — or are the implicit flow, which
     * hands a token to a browser and is retired everywhere for the reason PKCE exists.
     */
    private const string RESPONSE_TYPE = 'code';

    /** SHA-256, the only method worth offering; `plain` is the fallback for platforms without one. */
    private const string CHALLENGE_METHOD = 'S256';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $verifier The secret redeemed with the code. Never leaves the process.
     * @param string $state    Echoed back on the redirect, and compared, so a redirect that
     *                         belongs to somebody else's attempt is not mistaken for this one.
     */
    private function __construct(public string $verifier, public string $state) {}

    /**
     * Begins an attempt.
     *
     * Both values may be supplied, and only a test should: an authorization whose verifier is
     * predictable is an authorization whose code is worth stealing.
     *
     * @param string|null $verifier
     * @param string|null $state
     * @return self
     */
    public static function begin(?string $verifier = null, ?string $state = null): self
    {
        return new self($verifier ?? self::secret(), $state ?? self::secret());
    }

    /**
     * The challenge that goes in the URL: the verifier's SHA-256, base64url, unpadded.
     *
     * Unpadded is not cosmetic — RFC 7636 defines the encoding without `=`, and a server comparing
     * the strings byte for byte rejects a padded one.
     *
     * @return string
     */
    public function challenge(): string
    {
        return self::base64Url(hash('sha256', $this->verifier, true));
    }

    /**
     * The URL to open in a browser.
     *
     * A {@link Url} rather than a string, for the same reason a request target is one: this
     * address carries the client id and the challenge, and it is pasted into a browser by hand.
     *
     * @param Credentials $credentials
     * @return Url
     */
    public function url(Credentials $credentials): Url
    {
        return new Url(Endpoint::Authorize->value . '?' . http_build_query([
            'client_id'             => $credentials->clientId,
            'redirect_uri'          => $credentials->redirectUri,
            'response_type'         => self::RESPONSE_TYPE,
            'code_challenge'        => $this->challenge(),
            'code_challenge_method' => self::CHALLENGE_METHOD,
            'state'                 => $this->state,
        ]));
    }

    /**
     * The code out of the URL the browser was redirected to, checking the state as it goes.
     *
     * Takes the whole redirected URL rather than the code, because that is what a person can copy:
     * the browser lands on an address that does not resolve — the redirect URI is registered, not
     * served — and the address bar is where the answer is. A bare code is accepted too, for the
     * case where somebody has already picked it out.
     *
     * @param string $redirected The URL the browser ended on, or the code alone.
     * @return string
     * @throws SoundCloudException if the URL carries an error, no code, or somebody else's state.
     */
    public function code(string $redirected): string
    {
        $query = parse_url(trim($redirected), PHP_URL_QUERY);

        if (!is_string($query)) {
            // No query at all: either the bare code, or something that is not an answer.
            return $this->bareCode(trim($redirected));
        }

        parse_str($query, $parameters);

        if (is_string($parameters['error'] ?? null)) {
            throw new SoundCloudException(sprintf(
                'The authorization was refused: %s%s',
                $parameters['error'],
                is_string($parameters['error_description'] ?? null)
                    ? ' — ' . $parameters['error_description']
                    : '',
            ));
        }

        if (!is_string($parameters['state'] ?? null) || !hash_equals($this->state, $parameters['state'])) {
            throw new SoundCloudException(
                'That redirect carries a different state than this attempt sent, so it is an answer '
                . 'to a different question. Start again rather than redeeming it.',
            );
        }

        if (!is_string($parameters['code'] ?? null) || $parameters['code'] === '') {
            throw new SoundCloudException('That redirect carries no code.');
        }

        return $parameters['code'];
    }

    /**
     * A code pasted on its own, with nothing around it to check.
     *
     * @param string $value
     * @return string
     * @throws SoundCloudException if it is not something that could be a code.
     */
    private function bareCode(string $value): string
    {
        // An address with no query is the mistake worth naming separately: it is what you get by
        // copying the browser's address bar after it has trimmed the ?, and treating it as a code
        // would send the whole URL to the token endpoint and come back with a shrug.
        if (parse_url($value, PHP_URL_SCHEME) !== null) {
            throw new SoundCloudException(
                'That address carries no query, so there is no code in it. Copy the whole address '
                . 'including everything after the ?.',
            );
        }

        if ($value === '' || str_contains($value, ' ')) {
            throw new SoundCloudException(
                'That is neither a redirected URL nor a code. Paste the whole address the browser '
                . 'ended on, including everything after the ?.',
            );
        }

        return $value;
    }

    /**
     * 43 characters of the unreserved alphabet RFC 7636 asks for.
     *
     * @return string
     */
    private static function secret(): string
    {
        return self::base64Url(random_bytes(32));
    }

    /**
     * @param string $bytes
     * @return string
     */
    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
