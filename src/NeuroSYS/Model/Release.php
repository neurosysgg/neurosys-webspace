<?php

namespace NeuroSYS\Model;

use NeuroSYS\Exception\ReleaseVerificationException;
use NeuroSYS\Support\Collection;

/**
 * The Release class. Represents a music release with metadata and available download formats.
 */
class Release
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string     $title              The release title.
     * @param int        $bpm                Beats per minute (must be > 0).
     * @param MusicalKey $key                The musical key.
     * @param Genre      $genre              The musical genre.
     * @param string     $description        A short description shown in release listings.
     * @param string     $soundcloudEmbedHtml Full SoundCloud embed HTML, or empty to hide the player.
     * @param string     $coverSrc           Direct URL to the cover art image.
     * @param Collection<Format> $formats    The available download {@link Format}s.
     *
     * @throws ReleaseVerificationException if constructed with invalid data.
     */
    public function __construct(
        public string $title,
        public int $bpm,
        public MusicalKey $key,
        public Genre $genre,
        public string $description,
        public string $soundcloudEmbedHtml,
        public string $coverSrc,
        public Collection $formats
    ) {
        $this->verify();
    }

    /**
     * Finds a download format by its type value (e.g. 'flac', 'mp3').
     *
     * @param string $type The {@link ReleaseFormat} value to look up.
     * @return Format|null The matching format, or null if not available on this release.
     */
    public function findFormat(string $type): ?Format
    {
        foreach ($this->formats as $format) {
            if ($format->type->value === $type) {
                return $format;
            }
        }
        return null;
    }

    /**
     * @throws ReleaseVerificationException
     */
    private function verify(): void {
        if ($this->formats->type !== Format::class) {
            throw new ReleaseVerificationException(
                'Release::formats must be a Collection of \Format.'
            );
        }
        if ($this->bpm <= 0) {
            throw new ReleaseVerificationException(
                'Release::bpm must be greater than 0.'
            );
        }
    }
}