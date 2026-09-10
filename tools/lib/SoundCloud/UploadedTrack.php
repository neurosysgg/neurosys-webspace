<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\SoundCloud;

use NeuroSYS\Exception\ReleaseVerificationException;
use NeuroSYS\Model\Embed\SoundCloudEmbed;
use NeuroSYS\Tool\Http\JsonBody;

/**
 * The UploadedTrack class. What SoundCloud says exists now.
 *
 * Three of these five values are the three ids `data/releases.php` has been carrying a
 * commented-out placeholder for since `EntryWriter` was written — *"SoundCloud ids exist only once
 * the track is uploaded"*. This is that sentence stopping being true.
 *
 * **The response is read, not assumed.** The permalink asked for is a wish: the account may already
 * have a track under that slug, and SoundCloud answers with what it actually assigned. The same
 * goes for `sharing` — a track that came back public when private was asked for is worth seeing on
 * the screen.
 */
final readonly class UploadedTrack
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param int          $id          The numeric id, which is `SoundCloudEmbed::$trackId`.
     * @param string       $permalink   The slug SoundCloud assigned.
     * @param string       $secretToken The `s-…` share token, for a track that is not public.
     * @param string       $url         The track's page, for opening it after an upload.
     * @param TrackSharing $sharing     What the track actually is, not what was asked for.
     */
    public function __construct(
        public int          $id,
        public string       $permalink,
        public string       $secretToken,
        public string       $url,
        public TrackSharing $sharing,
    ) {}

    /**
     * Reads a track resource.
     *
     * The keys are the provider's, and they are {@link TrackKey} cases because a key read wrongly
     * here is *silent* in the way {@link TrackField} describes: `permalink_url` misspelled is an
     * empty string, and an empty string is a plausible-looking absence.
     *
     * Still read defensively — {@link JsonBody} answers `''` and `0` for a key that is absent or
     * carries the wrong type. What stops a
     * plausible absence reaching `data/releases.php` is {@link self::embed()}, where the two ids
     * that matter meet `SoundCloudEmbed`'s own constructor and its verification.
     *
     * @param JsonBody $body
     * @return self
     */
    public static function fromResponse(JsonBody $body): self
    {
        return new self(
            $body->int(TrackKey::Id),
            $body->string(TrackKey::Permalink),
            $body->string(TrackKey::SecretToken),
            $body->string(TrackKey::PermalinkUrl),
            TrackSharing::tryFrom($body->string(TrackKey::Sharing)) ?? TrackSharing::Private,
        );
    }

    /**
     * The player this track becomes on the release page.
     *
     * The site's own model, constructed here on purpose: `SoundCloudEmbed::verify()` refuses an id
     * that is not positive and a permalink that is empty, so a response this misread throws at the
     * moment it is misread rather than at the moment `data/releases.php` is loaded on the server.
     * Same arrangement as `Profile::$url`, checked at its constructor as well as at the renderer.
     *
     * @return SoundCloudEmbed
     * @throws ReleaseVerificationException if the response did not describe a usable track.
     */
    public function embed(): SoundCloudEmbed
    {
        return new SoundCloudEmbed($this->id, $this->permalink, $this->secretToken);
    }
}
