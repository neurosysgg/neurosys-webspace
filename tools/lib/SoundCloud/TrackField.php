<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\SoundCloud;

/**
 * The TrackField enum. The multipart field names a track upload is made of.
 *
 * **These are typed because getting one wrong is silent.** An API drops a field it does not
 * recognise; it does not refuse the request over one. So `track[titel]` uploads the file, answers
 * 201, and leaves a track called *ill..wav* on the account — which is exactly the failure mode this
 * codebase types away everywhere else, and the reason `AttributeName`, `HeaderName` and `Tag` are
 * enums rather than strings.
 *
 * The contrast is deliberate and it is written down in {@link Authorization}: the OAuth parameter
 * names beside these stay literals, because misspelling one is answered in words by the
 * authorization server before anything is uploaded.
 *
 * **This list is from SoundCloud's published guide and has not been checked against a live
 * account** — no app is registered yet. It is the one thing here that is documentation rather than
 * observation, and this enum is where a correction lands when the first real upload disagrees.
 *
 * Exhaustive of what {@link TrackUpload} sends. A case nothing writes is a name with nothing on the
 * other end of it.
 */
enum TrackField: string
{
    /** The track's title, which is `Release::$title` and not the file's name. */
    case Title = 'track[title]';

    /** The audio itself — the only field whose value is a {@link \NeuroSYS\Tool\Http\FilePart}. */
    case AssetData = 'track[asset_data]';

    /** {@link TrackSharing}, and always private on the way up. */
    case Sharing = 'track[sharing]';

    /** The release's description, which is the one fact no folder can derive. */
    case Description = 'track[description]';

    /** The site's {@link \NeuroSYS\Model\Genre} value, as free text — SoundCloud has no enum. */
    case Genre = 'track[genre]';

    /**
     * The slug the track would like.
     *
     * A wish rather than an instruction: the account may already have a track under it, and
     * SoundCloud answers with the permalink it actually assigned. {@link UploadedTrack} reads that
     * back instead of assuming this one took.
     */
    case Permalink = 'track[permalink]';

    /** The cover, sent only where the folder has the export prepared for the web. */
    case ArtworkData = 'track[artwork_data]';
}
