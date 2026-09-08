<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Update;

use NeuroSYS\Model\Update\UpdatePayload;
use NeuroSYS\Support\Collection;
use NeuroSYS\Support\File;
use NeuroSYS\Tool\Cli\UsageException;

/**
 * The PayloadBuilder class. Packs, compresses, signs and frames a push.
 *
 * The writing half of {@link UpdatePayload}, which is the reading half, and the layout is stated
 * once — there. This builds to that description; nothing here restates the offsets.
 *
 * **The private key is read and used here and nowhere else in this repository.** It never leaves
 * this machine, is never committed, and has no counterpart under `src/`: the server holds the public
 * half and can therefore only ever check a signature, never make one. That asymmetry is the whole
 * security argument for the feature, so it is worth noticing that it is enforced by *where the files
 * are* rather than by any check in the code.
 */
final readonly class PayloadBuilder
{
    /** Where the private key lives by default — outside the repository entirely. */
    public const string DEFAULT_KEY = '.config/neurosys/update.key';

    /**
     * Builds the request body.
     *
     * @param Collection<PackedFile> $files
     * @param File $key The PEM private key.
     * @param bool $apply False for a dry run.
     * @param bool $mirror True to have the server delete what this payload omits.
     * @return string
     *
     * @throws UsageException if the key cannot be read or used to sign.
     */
    public static function build(Collection $files, File $key, bool $apply, bool $mirror): string
    {
        $archive = gzencode(TarWriter::pack($files), 9);

        if ($archive === false) {
            throw new UsageException('could not gzip the archive');
        }

        // The manifest's bytes are what gets signed and what the server checks the signature
        // against, so they are built once and passed along as a string. Re-encoding the parsed form
        // on either side would be a second spelling of one fact, and JSON has more than one way to
        // write the same object.
        $manifest = json_encode([
            'serial' => time(),
            'digest' => hash('sha256', $archive),
            'size'   => strlen($archive),
            'apply'  => $apply,
            'mirror' => $mirror,
        ], JSON_THROW_ON_ERROR);

        return UpdatePayload::MAGIC
            . pack('N', strlen($manifest)) . $manifest
            . self::signed($manifest, $key)
            . $archive;
    }

    /**
     * The signature over $manifest, length-prefixed.
     *
     * @param string $manifest
     * @param File $key
     * @return string
     *
     * @throws UsageException
     */
    private static function signed(string $manifest, File $key): string
    {
        $pem = $key->read();
        if ($pem === null) {
            throw new UsageException(sprintf(
                "cannot read the private key at %s. Generate the pair with:\n"
                . "  openssl genpkey -algorithm EC -pkeyopt ec_paramgen_curve:P-256 -out %s\n"
                . '  openssl pkey -in %s -pubout -out data/update.pub',
                $key->path,
                $key->path,
                $key->path,
            ));
        }

        $private = @openssl_pkey_get_private($pem);
        if ($private === false) {
            throw new UsageException($key->path . ' is not a readable PEM private key');
        }

        $signature = null;
        if (!@openssl_sign($manifest, $signature, $private, OPENSSL_ALGO_SHA256)) {
            throw new UsageException(
                'could not sign the manifest. The key must be an EC P-256 key: Ed25519 does not '
                . "work through PHP's openssl binding, which drives the digest-based API.",
            );
        }

        return pack('N', strlen((string) $signature)) . (string) $signature;
    }
}
