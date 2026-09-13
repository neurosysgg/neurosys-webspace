<?php

declare(strict_types=1);

namespace NeuroSYS\Model;

use Phpanta\Text\Translatable;
use Phpanta\Text\Translated;
use Phpanta\Text\Translation;

/**
 * The MusicalKey enum. All 24 standard Western musical keys (12 major + 12 minor).
 *
 * **The backing value is the English name, and stays it**: `tools/` reads a key out of a project
 * file or a tag and matches it against these values, so they are data as well as words. What a page
 * shows is the {@link Translation} on each case, which German writes its own way — `Fis-Dur`,
 * `dis-Moll`, and `H` for the English B, with major capitalised and minor not. A sharp stays a
 * sharp (`Ais`, not the enharmonic `B`), because the value names the pitch the tools found.
 */
enum MusicalKey: string implements Translatable
{
    use Translated;

    // Major
    #[Translation(en: 'C Major', de: 'C-Dur')]
    case CMajor      = 'C Major';
    #[Translation(en: 'C# Major', de: 'Cis-Dur')]
    case CSharpMajor = 'C# Major';
    #[Translation(en: 'D Major', de: 'D-Dur')]
    case DMajor      = 'D Major';
    #[Translation(en: 'D# Major', de: 'Dis-Dur')]
    case DSharpMajor = 'D# Major';
    #[Translation(en: 'E Major', de: 'E-Dur')]
    case EMajor      = 'E Major';
    #[Translation(en: 'F Major', de: 'F-Dur')]
    case FMajor      = 'F Major';
    #[Translation(en: 'F# Major', de: 'Fis-Dur')]
    case FSharpMajor = 'F# Major';
    #[Translation(en: 'G Major', de: 'G-Dur')]
    case GMajor      = 'G Major';
    #[Translation(en: 'G# Major', de: 'Gis-Dur')]
    case GSharpMajor = 'G# Major';
    #[Translation(en: 'A Major', de: 'A-Dur')]
    case AMajor      = 'A Major';
    #[Translation(en: 'A# Major', de: 'Ais-Dur')]
    case ASharpMajor = 'A# Major';
    #[Translation(en: 'B Major', de: 'H-Dur')]
    case BMajor      = 'B Major';

    // Minor
    #[Translation(en: 'C Minor', de: 'c-Moll')]
    case CMinor      = 'C Minor';
    #[Translation(en: 'C# Minor', de: 'cis-Moll')]
    case CSharpMinor = 'C# Minor';
    #[Translation(en: 'D Minor', de: 'd-Moll')]
    case DMinor      = 'D Minor';
    #[Translation(en: 'D# Minor', de: 'dis-Moll')]
    case DSharpMinor = 'D# Minor';
    #[Translation(en: 'E Minor', de: 'e-Moll')]
    case EMinor      = 'E Minor';
    #[Translation(en: 'F Minor', de: 'f-Moll')]
    case FMinor      = 'F Minor';
    #[Translation(en: 'F# Minor', de: 'fis-Moll')]
    case FSharpMinor = 'F# Minor';
    #[Translation(en: 'G Minor', de: 'g-Moll')]
    case GMinor      = 'G Minor';
    #[Translation(en: 'G# Minor', de: 'gis-Moll')]
    case GSharpMinor = 'G# Minor';
    #[Translation(en: 'A Minor', de: 'a-Moll')]
    case AMinor      = 'A Minor';
    #[Translation(en: 'A# Minor', de: 'ais-Moll')]
    case ASharpMinor = 'A# Minor';
    #[Translation(en: 'B Minor', de: 'h-Moll')]
    case BMinor      = 'B Minor';
}
