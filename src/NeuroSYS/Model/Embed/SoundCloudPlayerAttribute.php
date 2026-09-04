<?php

declare(strict_types=1);

namespace NeuroSYS\Model\Embed;

use NeuroSYS\View\Html\AttributeName;

/**
 * The SoundCloudPlayerAttribute enum. What {@link SoundCloudEmbed} tells `<soundcloud-player>`.
 *
 * These names are the whole interface between the two halves of the player: the server writes them,
 * `assets/ts/elements/embed/SoundCloudPlayer.ts` reads them, and a typo on either side is a silent
 * `null` — a widget URL missing its track, or an iframe with no height. So the names live in one
 * place per side and `assets/ts/model/SoundCloudPlayerAttribute.ts` mirrors this, under the same
 * parity test as the enums the query string is built from.
 */
enum SoundCloudPlayerAttribute: string implements AttributeName
{
    /** The numeric SoundCloud track id the widget resolves. */
    case TrackId = 'track-id';

    /** The track's URL slug, for the attribution link. */
    case Permalink = 'permalink';

    /** Share token for a private or scheduled track. Absent entirely on a public one. */
    case SecretToken = 'secret-token';

    /** Which player layout — see {@link SoundCloudPlayerStyle}. */
    case PlayerStyle = 'player-style';

    /** The enabled toggles, space-separated — see {@link SoundCloudOption}. */
    case Options = 'options';

    /** The track title, for the iframe's accessible name and the attribution. */
    case TrackTitle = 'track-title';

    /** The player height, which the gate reserves so the page does not jump. */
    case Height = 'height';

    public function attribute(): string
    {
        return $this->value;
    }
}
