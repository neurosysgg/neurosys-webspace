<?php
declare(strict_types=1);

namespace NeuroSYS\Model\Link;

use NeuroSYS\Exception\ReleaseVerificationException;

/**
 * The HiDriveLink class. A file shared from HiDrive, addressed by its share id.
 *
 * Replaces the full share URLs that used to be pasted into `data/releases.php` — every
 * one of them the same endpoint with a different 9-character id on the end.
 *
 * {@link self::BASE} is HiDrive's **direct-download** endpoint: it responds with the file
 * itself, which is what both an `<img src>` and a download redirect need. HiDrive's web UI
 * also offers a share *page* URL (`https://my.hidrive.com/share/…`), which looks similar
 * but serves an HTML viewer — that one does not work here. Take the link from
 * **Share → Direct download link**; see docs/releases.md.
 */
final readonly class HiDriveLink implements FileLink
{
    /** HiDrive's direct-download endpoint — serves the file, not a viewer page. */
    private const string BASE = 'https://my.hidrive.com/api/sharelink/download';

    /**
     * The shape HiDrive currently mints: exactly 9 alphanumeric characters.
     *
     * Enforced so a truncated or mistyped paste fails loudly when the data file loads,
     * rather than silently 404ing from HiDrive when a visitor clicks. If HiDrive ever
     * changes the format, this pattern is the only thing that needs widening.
     */
    private const string ID_PATTERN = '/^[A-Za-z0-9]{9}$/';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $shareId The id from a HiDrive direct-download link — the `id=` query
     *                        parameter, e.g. 'BXRsy9S7d'.
     *
     * @throws ReleaseVerificationException if the id is not a well-formed share id.
     */
    public function __construct(public string $shareId)
    {
        $this->verify();
    }

    public function url(): string
    {
        return self::BASE . '?' . http_build_query(['id' => $this->shareId]);
    }

    /**
     * @throws ReleaseVerificationException
     */
    private function verify(): void
    {
        if (preg_match(self::ID_PATTERN, $this->shareId) !== 1) {
            throw new ReleaseVerificationException(sprintf(
                "HiDriveLink::shareId must be 9 alphanumeric characters, got '%s'. "
                . 'Use the id from Share → Direct download link, not the whole URL.',
                $this->shareId,
            ));
        }
    }
}
