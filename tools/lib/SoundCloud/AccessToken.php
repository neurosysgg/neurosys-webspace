<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\SoundCloud;

use NeuroSYS\Tool\Http\JsonBody;

/**
 * The AccessToken class. A token, when it stops working, and what to get the next one with.
 *
 * **The refresh token is the valuable half.** SoundCloud's are single-use and rotate: spending one
 * returns a new one, and the old one is dead the moment the new one is issued. Lose the new one —
 * by writing the file badly, by throwing between the response and the write — and the only way
 * back is authorizing by hand in a browser. {@link TokenStore} is written around that fact.
 *
 * Time is a parameter here rather than a call to `time()`, so that "this token has expired" is
 * something a test can state instead of something it has to wait for.
 */
final readonly class AccessToken
{
    /**
     * Seconds before the stated expiry at which the token is treated as already gone.
     *
     * An upload takes minutes. A token that is valid when the request starts and invalid when the
     * body finishes is a 401 after the whole file has gone up the wire, which is the one failure
     * here worth spending a minute of validity to avoid.
     */
    private const int MARGIN = 60;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $value        The bearer value, sent as `OAuth <value>`.
     * @param int    $expiresAt    Unix time at which the value stops working.
     * @param string $refreshToken Single-use; spending it yields the next token and voids this one.
     * @param string $scope        What the token is good for, as the server stated it.
     */
    public function __construct(
        public string $value,
        public int    $expiresAt,
        public string $refreshToken,
        public string $scope = '',
    ) {}

    /**
     * Reads a token endpoint's answer.
     *
     * {@link TokenKey::ExpiresIn} is the wire's form of the expiry — a duration — and this is where
     * it becomes {@link TokenKey::ExpiresAt}, the instant everything downstream compares against.
     * That conversion is the reason both live in one enum.
     *
     * @param JsonBody $body
     * @param int      $now Unix time when the answer arrived.
     * @return self
     * @throws SoundCloudException if the answer carries no access token.
     */
    public static function fromResponse(JsonBody $body, int $now): self
    {
        $value = $body->string(TokenKey::AccessToken);

        if ($value === '') {
            throw new SoundCloudException(
                'The token endpoint answered without an access_token. Nothing was stored.',
            );
        }

        return new self(
            $value,
            $now + $body->int(TokenKey::ExpiresIn),
            $body->string(TokenKey::RefreshToken),
            $body->string(TokenKey::Scope),
        );
    }

    /**
     * Whether the value should be refreshed before it is used.
     *
     * @param int $now
     * @return bool
     */
    public function isExpired(int $now): bool
    {
        return $now >= $this->expiresAt - self::MARGIN;
    }

    /**
     * Whether there is anything to refresh with.
     *
     * @return bool
     */
    public function isRenewable(): bool
    {
        return $this->refreshToken !== '';
    }

    /**
     * The token as {@link TokenStore} writes it.
     *
     * @return array<string, string|int>
     */
    public function toArray(): array
    {
        return [
            TokenKey::AccessToken->value  => $this->value,
            TokenKey::ExpiresAt->value    => $this->expiresAt,
            TokenKey::RefreshToken->value => $this->refreshToken,
            TokenKey::Scope->value        => $this->scope,
        ];
    }

    /**
     * The token as {@link TokenStore} read it back, or null for a file that says nothing usable.
     *
     * The mirror of {@link self::toArray()}, and the same keys — {@link TokenKey::ExpiresAt} here
     * rather than `ExpiresIn`, because what was written was an instant.
     *
     * @param JsonBody $stored
     * @return self|null
     */
    public static function fromArray(JsonBody $stored): ?self
    {
        $value = $stored->string(TokenKey::AccessToken);

        if ($value === '') {
            return null;
        }

        return new self(
            $value,
            $stored->int(TokenKey::ExpiresAt),
            $stored->string(TokenKey::RefreshToken),
            $stored->string(TokenKey::Scope),
        );
    }
}
