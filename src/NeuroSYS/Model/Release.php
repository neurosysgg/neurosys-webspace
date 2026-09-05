<?php

declare(strict_types=1);

namespace NeuroSYS\Model;

use NeuroSYS\Exception\ReleaseVerificationException;
use NeuroSYS\Model\Embed\Embed;
use NeuroSYS\Model\Link\FileLink;
use NeuroSYS\Model\Production\Arrangement;
use NeuroSYS\Model\Production\Plugin;
use NeuroSYS\Model\Production\ProductionTime;
use NeuroSYS\Support\Collection;

/**
 * The Release class. Represents a music release with metadata and available download formats.
 *
 * The last three parameters are what the project file adds, and all three are optional so that an
 * entry written before `tools/lib/Flp/` existed stays valid unchanged — the same half-state a null
 * `cover` and a linkless `Format` already model. They are the facts nothing but a `.flp` knows: how
 * the track is laid out, how long it took, and what made it. See `docs/authoring.md`.
 */
readonly class Release
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string     $title              The release title.
     * @param int        $bpm                Beats per minute (must be > 0).
     * @param MusicalKey $key                The musical key.
     * @param Genre      $genre              The musical genre.
     * @param string     $description        A short description shown in release listings.
     * @param FileLink|null $cover           The cover art image, or null to use the placeholder.
     * @param Collection<Format> $formats    The available download {@link Format}s.
     * @param Embed|null $embed              The media player for this release, or null for no player.
     * @param Arrangement|null $arrangement  How the track is laid out in time, or null to show none.
     * @param ProductionTime|null $timeSpent How long it took, or null where it was not recorded.
     * @param Collection<Plugin> $madeWith   The instruments and effects to credit; empty for none.
     *
     * @throws ReleaseVerificationException if constructed with invalid data.
     */
    public function __construct(
        public string $title,
        public int $bpm,
        public MusicalKey $key,
        public Genre $genre,
        public string $description,
        public ?FileLink $cover,
        public Collection $formats,
        public ?Embed $embed = null,
        public ?Arrangement $arrangement = null,
        public ?ProductionTime $timeSpent = null,
        public Collection $madeWith = new Collection(Plugin::class),
    ) {
        $this->verify();
    }

    /**
     * Finds a download format on this release.
     *
     * Takes the enum rather than its value: the string came from a URL segment, and turning it into
     * a {@link ReleaseFormat} is the caller's job — an unknown segment is then a null before it gets
     * here, rather than a comparison that quietly matches nothing.
     *
     * @param ReleaseFormat $type
     * @return Format|null The matching format, or null if not available on this release.
     */
    public function findFormat(ReleaseFormat $type): ?Format
    {
        foreach ($this->formats as $format) {
            if ($format->type === $type) {
                return $format;
            }
        }
        return null;
    }

    /**
     *
     * @return void
     * @throws ReleaseVerificationException
     */
    private function verify(): void {
        if ($this->formats->type !== Format::class) {
            throw new ReleaseVerificationException(
                'Release::formats must be a Collection of \Format.'
            );
        }
        if ($this->madeWith->type !== Plugin::class) {
            throw new ReleaseVerificationException(
                'Release::madeWith must be a Collection of \Plugin.'
            );
        }
        if ($this->bpm <= 0) {
            throw new ReleaseVerificationException(
                'Release::bpm must be greater than 0.'
            );
        }
    }
}
