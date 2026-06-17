<?php

namespace NeuroSYS\Model;

/**
 * The MusicalKey enum. All 24 standard Western musical keys (12 major + 12 minor).
 */
enum MusicalKey: string
{
    // Major
    case CMajor      = 'C Major';
    case CSharpMajor = 'C# Major';
    case DMajor      = 'D Major';
    case DSharpMajor = 'D# Major';
    case EMajor      = 'E Major';
    case FMajor      = 'F Major';
    case FSharpMajor = 'F# Major';
    case GMajor      = 'G Major';
    case GSharpMajor = 'G# Major';
    case AMajor      = 'A Major';
    case ASharpMajor = 'A# Major';
    case BMajor      = 'B Major';

    // Minor
    case CMinor      = 'C Minor';
    case CSharpMinor = 'C# Minor';
    case DMinor      = 'D Minor';
    case DSharpMinor = 'D# Minor';
    case EMinor      = 'E Minor';
    case FMinor      = 'F Minor';
    case FSharpMinor = 'F# Minor';
    case GMinor      = 'G Minor';
    case GSharpMinor = 'G# Minor';
    case AMinor      = 'A Minor';
    case ASharpMinor = 'A# Minor';
    case BMinor      = 'B Minor';
}