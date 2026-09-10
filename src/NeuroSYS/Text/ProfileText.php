<?php

declare(strict_types=1);

namespace NeuroSYS\Text;

/**
 * The ProfileText enum. How each external profile is labelled — the link's title, and its icon's
 * alt text.
 *
 * Worded per each platform's own guidelines, which exist in German too: Apple's German badge reads
 * "Anhören auf Apple Music" rather than a translation of the English. See
 * {@link \NeuroSYS\Model\Platform::label()}, which is where a platform finds its case.
 */
enum ProfileText: string implements Translatable
{
    use Translated;

    #[Translation(en: 'Listen on SoundCloud', de: 'Auf SoundCloud anhören')]
    case SoundCloud = 'soundcloud';

    #[Translation(en: 'Listen on Spotify', de: 'Auf Spotify anhören')]
    case Spotify = 'spotify';

    #[Translation(en: 'Listen on Apple Music', de: 'Anhören auf Apple Music')]
    case AppleMusic = 'apple-music';

    #[Translation(en: 'Watch on YouTube', de: 'Auf YouTube ansehen')]
    case YouTube = 'youtube';

    #[Translation(en: 'Follow on X', de: 'Auf X folgen')]
    case X = 'x';

    #[Translation(en: 'GitHub', de: 'GitHub')]
    case GitHub = 'github';
}
