<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\SoundCloud;

/**
 * The TrackSharing enum. Who can hear an uploaded track.
 *
 * Two cases, and only one of them is ever written by this tooling. {@link self::Private} is the
 * default everywhere it appears and there is no flag to change it: publishing is a decision about a
 * release date, and `docs/releases.md` already describes it as a step a person takes in SoundCloud's
 * own interface once the site is verified. A command that could publish is a command that can
 * publish by accident, which is a mistake with an audience.
 *
 * {@link self::Public} exists because it is the other half of the vocabulary and because an
 * uploaded track answers with the value it actually has — a response saying `public` when we asked
 * for `private` is a thing to notice, and it cannot be noticed by an enum with one case.
 */
enum TrackSharing: string
{
    /** Reachable only with the secret token, which is what `SoundCloudEmbed::$secretToken` is. */
    case Private = 'private';

    /** On the profile, in search, and in the feed of everyone following. */
    case Public = 'public';
}
