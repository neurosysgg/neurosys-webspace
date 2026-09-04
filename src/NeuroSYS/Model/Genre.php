<?php

declare(strict_types=1);

namespace NeuroSYS\Model;

/**
 * The Genre enum. The musical genres used across the release catalogue.
 */
enum Genre: string
{
    case Dubstep      = 'Dubstep';
    case Riddim       = 'Riddim';
    case Halftime     = 'Halftime';
    case DrumAndBass  = 'Drum & Bass';
    case Neurofunk    = 'Neurofunk';
    case Trap         = 'Trap';
    case FutureBass   = 'Future Bass';
    case Techno       = 'Techno';
    case House        = 'House';
    case Ambient      = 'Ambient';
    case Experimental = 'Experimental';
}
