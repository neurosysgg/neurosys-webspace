<?php

declare(strict_types=1);

namespace NeuroSYS\View\Html;

/**
 * The CoverArtAttribute enum. What a view tells `<cover-art>`.
 *
 * The element builds its own `<img>` from these, so all three are read by
 * `assets/ts/elements/CoverArt.ts` and mirrored in `assets/ts/model/CoverArtAttribute.ts`.
 */
enum CoverArtAttribute: string implements HtmlAttribute
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
}
