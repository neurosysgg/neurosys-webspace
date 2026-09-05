<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\SoundCloud;

/**
 * The TrackKey enum. The keys of a track resource, as SoundCloud answers it.
 *
 * **The other direction from {@link TrackField}**, and the pair is worth reading together before
 * touching either. That one is what an upload *sends* — `track[permalink]`, a multipart field name.
 * This is what comes *back* — `permalink`, a JSON key. The same fact under two spellings, because
 * the provider uses two, and neither enum may be used for the other's job.
 *
 * **These are typed because getting one wrong is silent**, which is the same argument `TrackField`
 * makes and it is if anything stronger here. {@link UploadedTrack}'s own docblock has said so since
 * it was written: *"`permalink_url` misspelled is an empty string, and an empty string is a
 * plausible-looking absence"*. It then read all five keys as string literals. This is that sentence
 * being acted on.
 *
 * Read through {@link \NeuroSYS\Tool\Http\JsonBody}, which takes a case and never a string, so there
 * is no spelling left at the call site to get wrong.
 */
enum TrackKey: string
{
    /** The numeric id, which becomes `SoundCloudEmbed::$trackId`. */
    case Id = 'id';

    /** The slug SoundCloud actually assigned, which need not be the one asked for. */
    case Permalink = 'permalink';

    /** The `s-…` share token a private track is reachable with, and a public one has none. */
    case SecretToken = 'secret_token';

    /** The track's own page. Note the `_url`: {@link self::Permalink} is the slug, this is the address. */
    case PermalinkUrl = 'permalink_url';

    /** {@link TrackSharing} — what the track *is*, which is not always what was asked for. */
    case Sharing = 'sharing';
}
