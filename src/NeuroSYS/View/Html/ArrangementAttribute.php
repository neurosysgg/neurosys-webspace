<?php

declare(strict_types=1);

namespace NeuroSYS\View\Html;

/**
 * The ArrangementAttribute enum. What a view tells `<arrangement-section>`.
 *
 * Unlike {@link CoverArtAttribute}, no element reads these: `<arrangement-section>` is a guard and
 * nothing else, and the reader is the stylesheet. Named here for the reason
 * `assets/ts/model/ArrangementAttribute.ts` names them on the other side — a CSS selector is a
 * reader no test can follow, so the name existing in exactly one place per language is the whole
 * guard there is.
 */
enum ArrangementAttribute: string implements AttributeName
{
    /** Which accent the section takes; a {@link \NeuroSYS\Model\Production\SectionKind} value. */
    case Kind = 'kind';

    /**
     * @return string
     */
    public function attribute(): string
    {
        return $this->value;
    }

    /**
     * @return bool
     */
    public function isUrl(): bool
    {
        return false;
    }
}
