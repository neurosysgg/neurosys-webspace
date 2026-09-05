<?php

declare(strict_types=1);

namespace NeuroSYS\Model\Embed;

/**
 * The SoundCloudPlayerStyle enum. The two player layouts SoundCloud's embed dialog offers.
 */
enum SoundCloudPlayerStyle: string
{
    /** Large square artwork with the waveform overlaid. */
    case Visual = 'visual';

    /** Compact waveform bar beside a small thumbnail. */
    case Classic = 'classic';

    /**
     * Returns SoundCloud's own iframe height for this layout, in px.
     *
     * @return int
     */
    public function height(): int
    {
        return match ($this) {
            self::Visual  => 300,
            self::Classic => 166,
        };
    }

    /**
     * Returns the value of the player's `visual` query flag for this layout.
     *
     * @return bool
     */
    public function isVisual(): bool
    {
        return $this === self::Visual;
    }
}
