<?php

declare(strict_types=1);

namespace NeuroSYS\View\Html;

/**
 * The CoverArtAttribute enum. What a view tells `<cover-art>`.
 *
 * The element builds its own `<img>` from these, so all three are read by
 * `assets/ts/elements/CoverArt.ts` and mirrored in `assets/ts/model/CoverArtAttribute.ts`.
 */
enum CoverArtAttribute: string implements AttributeName
{
    /** Where the cover is hosted. */
    case Src = 'src';

    /** The placeholder to swap in when the file host 404s. */
    case Fallback = 'fallback';

    /** The image's alternative text. */
    case Alt = 'alt';

    public function attribute(): string
    {
        return $this->value;
    }

    /**
     * Both image sources, neither of them a URL to the HTML parser.
     *
     * `<cover-art>` is a custom element, so nothing is fetched until `CoverArt.ts` assigns these to
     * the `.src` of an image it builds — which makes them exactly as much a URL as a native
     * `<img src>` would be, one layer later. The check belongs on the attribute the server writes,
     * because that is the last point where anything on this side can still refuse it.
     */
    public function isUrl(): bool
    {
        return match ($this) {
            self::Src, self::Fallback => true,
            self::Alt                 => false,
        };
    }
}
