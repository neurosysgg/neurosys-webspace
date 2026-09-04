<?php

declare(strict_types=1);

namespace NeuroSYS\Model\Embed;

/**
 * The SoundCloudOption enum. The boolean toggles SoundCloud's player accepts.
 *
 * Each case is backed by the literal query-string key the player reads. A
 * {@link SoundCloudEmbed} enables exactly the options it is given; every other case
 * is emitted as `false` rather than omitted, matching what SoundCloud's own embed
 * dialog produces.
 *
 * Cases are declared in the order SoundCloud emits them — {@link SoundCloudEmbed}
 * iterates {@link self::cases()} to build the query string, so this order is the
 * rendered order.
 */
enum SoundCloudOption: string
{
    /** Start playback as soon as the player loads. */
    case AutoPlay = 'auto_play';

    /** Suppress the related-tracks list shown when playback ends. */
    case HideRelated = 'hide_related';

    /** Show timed listener comments on the waveform. */
    case ShowComments = 'show_comments';

    /** Show the uploading artist's name and avatar. */
    case ShowUser = 'show_user';

    /** Show reposts alongside the track in the related list. */
    case ShowReposts = 'show_reposts';

    /** Show the "next up" teaser strip. */
    case ShowTeaser = 'show_teaser';
}
