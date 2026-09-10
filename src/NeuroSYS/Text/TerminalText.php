<?php

declare(strict_types=1);

namespace NeuroSYS\Text;

/**
 * The TerminalText enum. The terminal rows the release page and the demo page share.
 *
 * Captions only. The window's title and its command line — `release.log`, `./release --track` —
 * stay as they are in both languages: they are the look of a shell, and a shell is not translated.
 */
enum TerminalText: string implements Translatable
{
    use Translated;

    #[Translation(en: 'artist', de: 'künstler')]
    case Artist = 'artist';

    #[Translation(en: 'status', de: 'status')]
    case Status = 'status';
}
