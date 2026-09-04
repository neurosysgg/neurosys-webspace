<?php

namespace NeuroSYS\Model;

/**
 * The Platform enum. External profile platforms linked from the site footer.
 *
 * Icons are vendored under public/assets/img/brand/ — never hot-linked from the
 * platform's own CDN, which would transfer visitor data on page load before any
 * click (CJEU C-40/17, "Fashion ID") and require a consent gate.
 */
enum Platform: string
{
    case Spotify    = 'spotify';
    case AppleMusic = 'apple-music';
    case YouTube    = 'youtube';
    case GitHub     = 'github';

    /** Returns the accessible link label, worded per each platform's brand guidelines. */
    public function label(): string
    {
        return match ($this) {
            self::Spotify    => 'Listen on Spotify',
            self::AppleMusic => 'Listen on Apple Music',
            self::YouTube    => 'Watch on YouTube',
            self::GitHub     => 'GitHub',
        };
    }

    /** Returns the vendored brand asset path, or '' if the asset isn't vendored yet. */
    public function iconSrc(): string
    {
        return match ($this) {
            self::Spotify    => '/assets/img/brand/spotify.svg',
            self::AppleMusic => '/assets/img/brand/apple-music-badge.svg',
            self::GitHub     => '/assets/img/brand/github.svg',
            self::YouTube    => '', // not vendored — see docs/branding.md
        };
    }

    /**
     * Returns the rendered icon height in px.
     *
     * Apple supplies a wide "Listen on Apple Music" lockup rather than a square
     * mark, so it sits slightly taller to read at the same optical weight.
     * Spotify's floor is 21px, so 24 clears it.
     */
    public function iconHeight(): int
    {
        return match ($this) {
            self::AppleMusic => 30,
            default          => 24,
        };
    }
}
