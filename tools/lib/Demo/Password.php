<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Demo;

use NeuroSYS\Support\PasswordHash;

/**
 * The Password class. One minted demo password: the plaintext, once, and its hash to keep.
 *
 * **The plaintext exists only for as long as this object does.** It is printed by
 * {@link \NeuroSYS\Tool\Command\StageDemo} and written nowhere — not to `data/`, not to a ledger,
 * not to the shell history. Losing it means minting a new one with `--rotate`, which is a second's
 * work and the only arrangement in which a copy of this repository, or of the server, cannot be
 * turned into a working password.
 *
 * The two halves are on one object because they must not be produced separately: a hash of a
 * password that was never shown, or a password shown with no hash written down, are both ways of
 * ending up with a demo nobody can open.
 */
final readonly class Password
{
    /**
     * The alphabet, which is Crockford's base32 — no `I`, `L`, `O` or `U`.
     *
     * Those four are dropped because a demo password gets read off a screen and typed into a
     * browser prompt, and sometimes read down a phone: `I` against `1` and `O` against `0` are the
     * confusions that produce a password that "does not work". `U` goes for a different reason of
     * Crockford's — it keeps the accidental words out.
     */
    private const string ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** Characters per group. Four groups of five reads like a licence key, which is the point. */
    private const int GROUP = 5;

    /** How many groups. Twenty characters of this alphabet is 100 bits. */
    private const int GROUPS = 4;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param string       $plaintext What to send, once.
     * @param PasswordHash $hash      What goes in `data/demos.php`.
     */
    private function __construct(
        public string       $plaintext,
        public PasswordHash $hash,
    ) {}

    /**
     * Mints a password and hashes it.
     *
     * `random_int()` rather than `mt_rand()`, and not as a formality: this is the entire access
     * control on unreleased music, so the generator has to be the cryptographic one. It is also why
     * the alphabet's length is a power of two — 32 divides the range evenly, so there is no modulo
     * bias to reason about, and `random_int()`'s own range argument means there is none anyway.
     *
     * 100 bits is far past anything a gate could be brute-forced through, and the choice was made
     * on legibility rather than on strength: a shorter one would still be unguessable and a longer
     * one would be worse to type.
     *
     * @return self
     */
    public static function mint(): self
    {
        $plaintext = self::plaintext();

        return new self($plaintext, new PasswordHash(password_hash($plaintext, PASSWORD_BCRYPT)));
    }

    /**
     * The characters, before anything is done with them.
     *
     * Separate from {@link self::mint()} so the two properties can be tested apart: what this
     * produces is checked over many draws, and hashing it is checked once. Together they would be
     * one bcrypt per draw, and bcrypt is slow on purpose — a suite that mints fifty passwords to
     * prove they differ spends twelve seconds proving it.
     *
     * @return string
     */
    private static function plaintext(): string
    {
        $groups = [];

        for ($group = 0; $group < self::GROUPS; $group++) {
            $characters = '';

            for ($i = 0; $i < self::GROUP; $i++) {
                $characters .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }

            $groups[] = $characters;
        }

        return implode('-', $groups);
    }
}
