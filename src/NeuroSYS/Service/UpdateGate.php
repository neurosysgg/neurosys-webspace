<?php

declare(strict_types=1);

namespace NeuroSYS\Service;

use NeuroSYS\Config;
use NeuroSYS\DataFile;
use NeuroSYS\Exception\UpdateException;
use NeuroSYS\Http\HttpMethod;
use NeuroSYS\Http\Request;
use NeuroSYS\Model\Update\UpdateManifest;
use NeuroSYS\Model\Update\UpdatePayload;
use NeuroSYS\Support\BareArray;
use NeuroSYS\Support\File;
use NeuroSYS\Support\PublicKey;
use NoDiscard;

/**
 * The UpdateGate class. Decides whether a request is a push this deployment signed for, and answers
 * with the manifest and archive when it is.
 *
 * It is {@link Auth} for the update route, and the split is the same one: the decision is a
 * returned value and the refusal lives outside it, because a method that ends the request cannot be
 * asserted against. Here the refusal is not even a challenge — it is
 * {@link \NeuroSYS\Controller\UpdateController} answering exactly as the site answers for a path no
 * route claims, so a caller without the key cannot tell the route from a typo.
 *
 * **Nothing this class refuses says why.** Every failure below is one `null`, and the controller
 * turns every `null` into the same 404 or 405. That is the difference between this gate and the
 * three Basic ones: those announce a realm because a person has to be prompted for a password, and
 * this must announce nothing at all, because the only legitimate caller already knows the endpoint
 * is there. Past the signature the situation inverts and diagnostics become generous — see
 * {@link UpdateApplier} — since by then the caller has proved possession of the private key.
 *
 * **The order below is the design.** Cheap and unforgeable checks first, then the signature, then
 * everything the signature makes trustworthy. Nothing derived from the body is used for anything
 * before {@link PublicKey::verifies()} has passed.
 */
final readonly class UpdateGate
{
    /**
     * The most a push may weigh.
     *
     * The real payload is about 210 KB, so this is thirty times what it takes and a fortieth of
     * what the live host's `post_max_size` would permit. It is the length {@link Request::body()} is
     * told to read *to* — one byte over, so anything larger arrives as larger rather than silently
     * truncated to the limit — because an unbounded `file_get_contents('php://input')` pulls up to
     * that `post_max_size` into memory before this class sees a byte. A bound the application states
     * and reads to is worth more than one inherited from a php.ini nobody in this repository owns.
     */
    private const int MAX_BODY = 8_388_608;

    /**
     * How far a payload's serial may sit from this server's clock, in seconds.
     *
     * Five minutes each way, which is enough for an unsynchronised laptop and short enough that a
     * captured payload is worthless long before anyone could use it. It is the belt to
     * {@link self::MAX_BODY}'s braces on replay: the monotonic check below already refuses a serial
     * that has been seen, and this refuses one saved up to be used later.
     */
    private const int MAX_SKEW = 300;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param File|null $key Where the public key lives. The parameter is a test seam, the way
     *                       {@link Auth::requireSiteAuth()}'s is; production passes nothing.
     * @param File|null $serial Where the last accepted serial is recorded. Same.
     */
    public function __construct(private ?File $key = null, private ?File $serial = null) {}

    /**
     * The verified payload, or null if this request is not one.
     *
     * @param Request $request
     * @return array{UpdateManifest, string}|null The manifest and the archive it vouches for.
     */
    #[BareArray(
        'two types in a fixed order — the manifest and the bytes it vouches for — which is the one '
        . 'shape a homogeneous collection cannot hold. They are returned together because they are '
        . 'one decision: an archive without the manifest that named its digest has not been checked '
        . 'against anything.',
    )]
    #[NoDiscard('this is the update gate\'s decision and nothing else; dropping it is a door left open')]
    public function accepts(Request $request): ?array
    {
        if ($request->method() !== HttpMethod::Post) {
            return null;
        }

        $key = $this->key();
        if ($key === null) {
            return null;
        }

        // Read to one past the cap rather than the whole stream: the check below can then reject an
        // over-limit body, but only MAX_BODY + 1 bytes were ever pulled into memory to reach it,
        // which is the point of stating the bound here instead of inheriting post_max_size.
        $body = $request->body(self::MAX_BODY + 1);
        if ($body === '' || strlen($body) > self::MAX_BODY) {
            return null;
        }

        try {
            $payload = UpdatePayload::parse($body);

            // Everything above this line is framing. Nothing below trusts a byte of it until the
            // signature has passed, and the manifest is not even parsed until then: parsing is a
            // decision about attacker-controlled bytes, and the cheapest place to make none.
            if (!$key->verifies($payload->manifest, $payload->signature)) {
                return null;
            }

            $manifest = UpdateManifest::parse($payload->manifest);
        } catch (UpdateException) {
            return null;
        }

        if (!$this->isFresh($manifest->serial)) {
            return null;
        }

        // The manifest is signed, so its digest is ours; this is what binds the archive to it.
        // hash_equals rather than === because the two sides are a stored secret-adjacent value and
        // an attacker-supplied one, which is the case it exists for.
        if (
            strlen($payload->archive) !== $manifest->size
            || !hash_equals($manifest->digest, hash('sha256', $payload->archive))
        ) {
            return null;
        }

        return [$manifest, $payload->archive];
    }

    /**
     * Records $serial as the highest accepted, so it and everything before it cannot be replayed.
     *
     * Called by the controller *after* a run that wrote something, and deliberately not after a dry
     * run: a dry run writes nothing, so leaving the serial where it is lets the same payload be sent
     * again for real. A captured dry run replayed on its own still does nothing.
     *
     * Answers whether the record was actually written, because a serial that could not be stored
     * is replay protection that is quietly off — the next identical payload would be accepted
     * again. The caller reports that rather than ignoring it.
     *
     * @param int $serial
     * @return bool
     */
    #[NoDiscard('a serial that failed to store is replay protection silently switched off')]
    public function accept(int $serial): bool
    {
        return ($this->serial ?? Config::updateSerial())->write((string) $serial . "\n", 0o600);
    }

    /**
     * The key this deployment verifies against, or null where it holds none.
     *
     * A key file that is absent, unreadable, or not a usable EC key all collapse to null and so to
     * the same 404 — which is the correct collapse here, unlike {@link Support\File::read()}'s,
     * because the difference matters to whoever installs the key and to nobody else. A malformed
     * key is loud in the one place it can be: `openssl pkey -pubin -in data/update.pub -text`.
     *
     * @return PublicKey|null
     */
    private function key(): ?PublicKey
    {
        $pem = ($this->key ?? Config::dataFile(DataFile::UpdateKey))->read();

        if ($pem === null) {
            return null;
        }

        try {
            return PublicKey::fromPem($pem);
        } catch (UpdateException) {
            return null;
        }
    }

    /**
     * Whether $serial is both close to this clock and ahead of every serial already accepted.
     *
     * Two questions rather than one because each closes a gap the other leaves. The monotonic half
     * alone would accept a payload signed years ago and never sent; the skew half alone would let
     * one payload be replayed for five minutes. A missing or unreadable record reads as zero, which
     * is the permissive direction and is correct: the first push a deployment ever receives has
     * nothing to be ahead of.
     *
     * @param int $serial
     * @return bool
     */
    private function isFresh(int $serial): bool
    {
        if (abs(time() - $serial) > self::MAX_SKEW) {
            return false;
        }

        $recorded = ($this->serial ?? Config::updateSerial())->read();

        // (int) '' is 0, so an absent or unreadable record needs no sentinel of its own.
        return $serial > (int) trim($recorded ?? '');
    }
}
