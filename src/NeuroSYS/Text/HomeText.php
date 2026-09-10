<?php

declare(strict_types=1);

namespace NeuroSYS\Text;

/**
 * The HomeText enum. The words of the home page, beyond the shell's.
 */
enum HomeText: string implements Translatable
{
    use Translated;

    /** The heading over the profile player. */
    #[Translation(en: 'latest tracks', de: 'neueste tracks')]
    case LatestTracks = 'latest-tracks';
}
