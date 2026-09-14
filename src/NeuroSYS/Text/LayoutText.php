<?php

declare(strict_types=1);

namespace NeuroSYS\Text;

use Phpanta\Text\Translatable;
use Phpanta\Text\Translated;
use Phpanta\Text\Translation;

/**
 * The LayoutText enum. The words of the site shell — every page has these.
 *
 * Lower case in both languages, the way the site writes its own navigation.
 */
enum LayoutText: string implements Translatable
{
    use Translated;

    /** The header link, the home page's button and the catalogue's heading. */
    #[Translation(en: 'releases', de: 'releases')]
    case Releases = 'releases';

    /** The footer's profile links, for a screen reader. */
    #[Translation(en: 'Profiles', de: 'Profile')]
    case Profiles = 'profiles';

    #[Translation(en: 'imprint', de: 'impressum')]
    case Imprint = 'imprint';

    #[Translation(en: 'privacy policy', de: 'datenschutz')]
    case Privacy = 'privacy';

    /** The footer's one-glyph link to the admin, for a screen reader: the glyph says nothing. */
    #[Translation(en: 'admin', de: 'admin')]
    case Admin = 'admin';

    /**
     * The home page's headline and the meta description's second half. Without its full stop:
     * the stop is the accent, set in its own span, and the same in both languages.
     */
    #[Translation(en: 'electronic music', de: 'elektronische musik')]
    case Tagline = 'tagline';
}
