<?php

declare(strict_types=1);

namespace NeuroSYS\View\Html;

/**
 * The AttributeName interface. One attribute name an {@link Element} may carry.
 *
 * Implemented by an enum per element — {@link CoverArtAttribute} and friends — plus
 * {@link HtmlAttribute} for the standard ones, so `track-id` and `href` are both a case rather than
 * a string typed out at each call site. Same shape as {@link \NeuroSYS\Http\Security\CspSource}:
 * the interface is what lets {@link Element} take any element's attributes without knowing which
 * element it is building.
 */
interface AttributeName
{
    /** The attribute name as it appears in the markup. */
    public function attribute(): string;
}
