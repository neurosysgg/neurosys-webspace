<?php

declare(strict_types=1);

namespace NeuroSYS\Model;

use NeuroSYS\Text\ProfileText;

/**
 * The Platform enum. External profile platforms linked from the site footer.
 *
 * Icons are vendored under public/assets/img/brand/ — never hot-linked from the
 * platform's own CDN, which would transfer visitor data on page load before any
 * click (CJEU C-40/17, "Fashion ID") and require a consent gate.
 */
enum Platform: string
{
    case SoundCloud = 'soundcloud';
    case Spotify    = 'spotify';
    case AppleMusic = 'apple-music';
    case YouTube    = 'youtube';
    case X          = 'x';
    case GitHub     = 'github';

    /**
     * Returns the accessible link label, worded per each platform's brand guidelines — in both
     * languages, so the footer says it in the page's. See {@link ProfileText}.
     *
     * @return ProfileText
     */
    public function label(): ProfileText
    {
        return match ($this) {
            self::SoundCloud => ProfileText::SoundCloud,
            self::Spotify    => ProfileText::Spotify,
            self::AppleMusic => ProfileText::AppleMusic,
            self::YouTube    => ProfileText::YouTube,
            self::X          => ProfileText::X,
            self::GitHub     => ProfileText::GitHub,
        };
    }

    /**
     * Returns the platform's name as it reads in body copy — the plain noun, without
     * the verb {@link self::label()} carries. Used where the platform is named inside
     * a sentence, such as the embed consent gate.
     *
     * @return string
     */
    public function displayName(): string
    {
        return match ($this) {
            self::SoundCloud => 'SoundCloud',
            self::Spotify    => 'Spotify',
            self::AppleMusic => 'Apple Music',
            self::YouTube    => 'YouTube',
            self::X          => 'X',
            self::GitHub     => 'GitHub',
        };
    }

    /**
     * Returns the path to the platform's vendored brand asset under public/assets/img/brand/.
     *
     * @return string
     */
    public function iconSrc(): string
    {
        return match ($this) {
            self::Spotify    => '/assets/img/brand/spotify.svg',
            self::AppleMusic => '/assets/img/brand/apple-music-badge.svg',
            self::GitHub     => '/assets/img/brand/github.svg',
            self::SoundCloud => '/assets/img/brand/soundcloud.webp',
            self::YouTube    => '/assets/img/brand/youtube.png',
            self::X          => '/assets/img/brand/x.svg',
        };
    }

    /**
     * Returns the rendered icon height in px — the height of the *file*, which is
     * not the same as the height of the mark inside it.
     *
     * Apple supplies a wide "Listen on Apple Music" lockup rather than a square
     * mark, so it sits slightly taller to read at the same optical weight.
     * Spotify's floor is 21px, so 24 clears it.
     *
     * YouTube's and SoundCloud's official files bake their required clear space
     * into the canvas — the mark fills only 54% and 32% of the file height
     * respectively. Both are therefore scaled up so the *visible* mark lands at
     * roughly 20px and 18px, rather than the 13px and 8px a flat 24 would give.
     * The files stay byte-for-byte unmodified and their clear space scales with
     * them, which is what both guidelines ask for. Wide marks are set slightly
     * shorter than square ones so they don't dominate the row.
     *
     * @return int
     */
    public function iconHeight(): int
    {
        return match ($this) {
            self::SoundCloud => 56,
            self::YouTube    => 37,
            self::AppleMusic => 30,
            default          => 24,
        };
    }
}
