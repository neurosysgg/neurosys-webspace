<?php

declare(strict_types=1);

namespace NeuroSYS\Model\Update;

use JsonException;
use NeuroSYS\Exception\UpdateException;

/**
 * The UpdateManifest class. What a push claims about itself, signed.
 *
 * **The signature covers this and nothing else, and this covers the archive by digest.** That is
 * the whole trick: one signature over a few hundred bytes protects a payload of any size, and the
 * archive never has to be held in memory twice or hashed before it is read. Every field here is
 * therefore load-bearing — a field the manifest does not carry is a field an attacker chooses.
 *
 * It is parsed strictly. Every key must be present and of the right type; there are no defaults and
 * no coercions, because a missing `mirror` defaulting to false would be an update that silently
 * stopped deleting, and a missing `apply` defaulting to true would be a dry run that was not one.
 * {@link \NeuroSYS\Tool\Http\JsonBody} on the tooling side answers `''` and `0` for an absent key,
 * which is right for reading somebody else's API and wrong for reading a security boundary.
 */
final readonly class UpdateManifest
{
    /** A SHA-256, written out. */
    private const string DIGEST_PATTERN = '/\A[0-9a-f]{64}\z/';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param int $serial A Unix timestamp doing double duty — see {@link \NeuroSYS\Service\UpdateGate},
     *                    which bounds it against the clock and against the last one accepted.
     * @param string $digest SHA-256 of the archive segment, lowercase hex.
     * @param int $size Length of the archive segment in bytes.
     * @param bool $apply False for a dry run: validate everything, write nothing, advance nothing.
     * @param bool $mirror True to delete what the payload omits.
     */
    private function __construct(
        public int    $serial,
        public string $digest,
        public int    $size,
        public bool   $apply,
        public bool   $mirror,
    ) {}

    /**
     * Parses the manifest JSON.
     *
     * `json_decode`'s array stays a local and is never a declared type, which is the whole point of
     * the method: it is the door, and nothing past it is an array.
     *
     * @param string $json The exact bytes the signature was checked against.
     * @return self
     *
     * @throws UpdateException if the JSON is unreadable or any field is missing or mistyped.
     */
    public static function parse(string $json): self
    {
        try {
            /** @var mixed $values */
            $values = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $cause) {
            throw new UpdateException(
                'the update manifest is not readable JSON: ' . $cause->getMessage(),
                previous: $cause,
            );
        }

        if (!is_array($values)) {
            throw new UpdateException('the update manifest is not a JSON object');
        }

        $serial = $values['serial'] ?? null;
        $digest = $values['digest'] ?? null;
        $size   = $values['size'] ?? null;
        $apply  = $values['apply'] ?? null;
        $mirror = $values['mirror'] ?? null;

        if (!is_int($serial) || !is_string($digest) || !is_int($size) || !is_bool($apply) || !is_bool($mirror)) {
            throw new UpdateException(
                'the update manifest must carry serial:int, digest:string, size:int, apply:bool '
                . 'and mirror:bool, all present and all of those types',
            );
        }

        if (preg_match(self::DIGEST_PATTERN, $digest) !== 1) {
            throw new UpdateException('the update manifest\'s digest is not a lowercase hex SHA-256');
        }

        if ($size < 0) {
            throw new UpdateException('the update manifest declares a negative archive size');
        }

        return new self($serial, $digest, $size, $apply, $mirror);
    }
}
