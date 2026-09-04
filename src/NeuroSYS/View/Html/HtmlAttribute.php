<?php

declare(strict_types=1);

namespace NeuroSYS\View\Html;

/**
 * The HtmlAttribute interface. One attribute name an {@link Element} may carry.
 *
 * Implemented by an enum per element, so `track-id` is {@link \NeuroSYS\Model\Embed\
 * SoundCloudPlayerAttribute::TrackId} rather than a string typed out once in PHP and again in
 * TypeScript. Same shape as {@link \NeuroSYS\Http\Security\CspSource}: the interface is what lets
 * {@link Element} take any element's attributes without knowing which element it is building.
 */
interface HtmlAttribute
{
    /** The attribute name as it appears in the markup. */
    public function attribute(): string;
}
