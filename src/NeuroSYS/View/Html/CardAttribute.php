<?php

declare(strict_types=1);

namespace NeuroSYS\View\Html;

/**
 * The CardAttribute enum. What names a card in the catalogue and the download list.
 *
 * Neither is read by anything — no script, no stylesheet — which is exactly why they are worth
 * naming: an attribute nothing consumes is one nothing would report on either. They say which
 * release and which format a card is for, and they are here so that stays true after a rename.
 */
enum CardAttribute: string implements AttributeName
{
    /** The release a `<release-card>` links to. */
    case Slug = 'slug';

    /** The download a `<download-card>` offers. */
    case Format = 'format';

    public function attribute(): string
    {
        return $this->value;
    }

    /** Neither is a URL: they name a release and a format, and the `<a>` inside carries the link. */
    public function isUrl(): bool
    {
        return false;
    }
}
