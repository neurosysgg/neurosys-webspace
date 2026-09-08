<?php

declare(strict_types=1);

namespace NeuroSYS\Model\Update;

use NeuroSYS\Exception\UpdateException;

/**
 * The UpdatePayload class. The framed request body: a manifest, a signature over it, and the
 * gzipped archive the manifest vouches for.
 *
 * ```
 * 0        4    magic "NSU1"
 * 4        4    manifest length      u32 big-endian
 * 8        n    manifest JSON        UTF-8
 * 8+n      4    signature length     u32 big-endian
 * 12+n     m    signature            ECDSA DER, over the manifest bytes exactly
 * 12+n+m   …    archive              gzip( ustar tar )
 * ```
 *
 * **Framed into the body rather than split across headers, for two reasons.** The first is local:
 * {@link \NeuroSYS\Http\RequestHeader} is mirrored in `assets/ts/model/RequestHeader.ts` and
 * compared case-for-case by `enum-parity.test.mjs`, so metadata carried in headers would put two
 * cases into the browser's bundle that no browser will ever read. The second is that a header is
 * the part of a request most likely to be rewritten, folded, or dropped by something between here
 * and the client, and this body passes through Strato's proxy and an Apache rewrite before PHP sees
 * it. A body is bytes; nothing on the path has an opinion about it.
 *
 * The magic carries a version digit for the reason {@link \NeuroSYS\Model\Waveform}'s does: the
 * layout is fixed offsets, so a second version is a different reader rather than a new field, and
 * the byte that says which is cheaper than guessing.
 *
 * **Nothing here is trusted.** This class establishes only that the bytes are shaped like a
 * payload; whether they are *ours* is {@link \NeuroSYS\Service\UpdateGate}'s question, and it
 * cannot be asked until the framing has been read, which is why the framing is checked so
 * carefully. Every length is bounded against what is actually present before it is used as an
 * offset.
 */
final readonly class UpdatePayload
{
    /** The container's magic, with a version digit. */
    public const string MAGIC = 'NSU1';

    /** Each of the two length prefixes. */
    private const int LENGTH_WIDTH = 4;

    /**
     * The most a manifest may claim to be.
     *
     * It is a handful of JSON keys, so this is three orders of magnitude of headroom. It exists
     * because the length is read before the manifest is, and an unbounded length would be a
     * `substr()` sized by whatever arrived.
     */
    private const int MAX_MANIFEST = 8192;

    /** The most a signature may claim to be. An ECDSA P-256 DER signature is 70-72 bytes. */
    private const int MAX_SIGNATURE = 256;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $manifest The manifest's raw bytes — what the signature must be checked
     *                         against, byte for byte, rather than a re-encoding of the parsed form.
     * @param string $signature The DER signature, raw.
     * @param string $archive The gzipped tar, exactly as sent; its digest is what the manifest names.
     */
    private function __construct(
        public string $manifest,
        public string $signature,
        public string $archive,
    ) {}

    /**
     * Reads the framing.
     *
     * @param string $body The whole request body.
     * @return self
     *
     * @throws UpdateException if the magic is wrong or any length runs past the end.
     */
    public static function parse(string $body): self
    {
        $length = strlen($body);
        $magic  = strlen(self::MAGIC);

        if ($length < $magic || substr($body, 0, $magic) !== self::MAGIC) {
            throw new UpdateException('not an update payload: wrong magic');
        }

        $offset   = $magic;
        $manifest = self::segment($body, $offset, self::MAX_MANIFEST, 'manifest');
        $signature = self::segment($body, $offset, self::MAX_SIGNATURE, 'signature');

        return new self($manifest, $signature, substr($body, $offset));
    }

    /**
     * Reads one length-prefixed segment and advances $offset past it.
     *
     * @param string $body
     * @param int $offset Moved on by reference — the two segments are read in sequence and each
     *                    starts where the last one ended.
     * @param int $max
     * @param string $what Which segment, for the message.
     * @return string
     *
     * @throws UpdateException
     */
    private static function segment(string $body, int &$offset, int $max, string $what): string
    {
        $length = strlen($body);

        if ($offset + self::LENGTH_WIDTH > $length) {
            throw new UpdateException(sprintf('the update payload ends before its %s length', $what));
        }

        /** @var array{1: int} $unpacked */
        $unpacked = unpack('N', substr($body, $offset, self::LENGTH_WIDTH));
        $size     = $unpacked[1];
        $offset  += self::LENGTH_WIDTH;

        if ($size > $max) {
            throw new UpdateException(sprintf(
                'the update payload declares a %s of %d bytes, over the %d-byte limit',
                $what,
                $size,
                $max,
            ));
        }

        if ($offset + $size > $length) {
            throw new UpdateException(sprintf(
                'the update payload declares a %d-byte %s but only %d bytes follow',
                $size,
                $what,
                $length - $offset,
            ));
        }

        $segment = substr($body, $offset, $size);
        $offset += $size;

        return $segment;
    }
}
