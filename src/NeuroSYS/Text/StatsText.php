<?php

declare(strict_types=1);

namespace NeuroSYS\Text;

/**
 * The StatsText enum. The words of the download statistics page, which is behind a password.
 */
enum StatsText: string implements Translatable
{
    use Translated;

    #[Translation(en: 'stats', de: 'statistik')]
    case Title = 'title';

    #[Translation(
        en: 'Download logging is switched off — nothing is recorded.',
        de: 'Die Download-Protokollierung ist abgeschaltet — es wird nichts aufgezeichnet.',
    )]
    case LoggingOff = 'logging-off';

    #[Translation(en: 'No downloads logged yet.', de: 'Noch keine Downloads protokolliert.')]
    case NothingYet = 'nothing-yet';

    /** Followed by the number, in its own element. */
    #[Translation(en: 'total downloads: ', de: 'downloads gesamt: ')]
    case Total = 'total';

    #[Translation(en: 'by format', de: 'nach format')]
    case ByFormat = 'by-format';

    #[Translation(en: 'by day', de: 'nach tag')]
    case ByDay = 'by-day';
}
