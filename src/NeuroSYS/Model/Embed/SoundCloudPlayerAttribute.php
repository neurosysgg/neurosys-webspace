<?php

declare(strict_types=1);

namespace NeuroSYS\Model\Embed;

use NeuroSYS\View\Html\AttributeName;

/**
 * The SoundCloudPlayerAttribute enum. What {@link SoundCloudEmbed} tells `<soundcloud-player>`.
 *
 * SoundCloud's own facts, and only those. What every embed carries whoever it is for — the reserved
 * height, and the `loaded` flag the gate sets — is {@link EmbedAttribute}, so the provider-agnostic
 * half of the client has no reason to know this enum exists.
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

    public function attribute(): string
    {
        return $this->value;
    }

    /**
     * None of them, and that is the design rather than an oversight: the server sends the release's
     * facts — an id, a slug, a token — and `SoundCloudPlayer.ts` builds the widget URL around them
     * from its own constant host. No address crosses, so there is no address to check.
     */
    public function isUrl(): bool
    {
        return false;
    }
}
