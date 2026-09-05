<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\SoundCloud;

use NeuroSYS\Model\Genre;
use NeuroSYS\Support\Collection;
use NeuroSYS\Tool\Http\FilePart;
use NeuroSYS\Tool\Http\FormField;
use NeuroSYS\Tool\Release\ReleaseFolder;

/**
 * The TrackUpload class. One `POST /tracks`, as facts rather than as form fields.
 *
 * The fields are built in one place, from typed values, at the end — which is the arrangement the
 * markup tree has with `Element::render()`: composing is one thing and writing the wire format is
 * another, and the second happens once.
 *
 * **Everything here except the audio is a fact the folder already knows.** That is the point of
 * {@link self::forRelease()}: `stage-release` reads a release out of its folder and this uploads
 * the same reading, so the title on SoundCloud and the title in `data/releases.php` cannot be two
 * different strings somebody typed twice.
 */
final readonly class TrackUpload
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string        $title
     * @param FilePart      $audio       The file to upload.
     * @param TrackSharing  $sharing     Private unless something has argued otherwise.
     * @param string        $permalink   The slug asked for; empty to let SoundCloud choose.
     * @param string        $description
     * @param Genre|null    $genre
     * @param FilePart|null $artwork     The cover, where there is one fit to send.
     */
    public function __construct(
        public string       $title,
        public FilePart     $audio,
        public TrackSharing $sharing     = TrackSharing::Private,
        public string       $permalink   = '',
        public string       $description = '',
        public ?Genre       $genre       = null,
        public ?FilePart    $artwork     = null,
    ) {}

    /**
     * The upload a prepared folder describes.
     *
     * The audio is passed in rather than taken from the folder, because what goes to SoundCloud is
     * the render — see {@link \NeuroSYS\Tool\Export\Exporter} — and the folder's own master is only
     * the most likely stand-in for it.
     *
     * **The cover is sent only when it is the `web/` export.** The other two rungs
     * {@link \NeuroSYS\Tool\Release\Cover} knows about are a 19 MB master and a picture still
     * inside the FLAC; neither is artwork to hand a service, and `Preflight` already warns about
     * both. Nothing is uploaded that the folder has not prepared.
     *
     * @param ReleaseFolder $folder
     * @param FilePart      $audio
     * @param string        $description The one fact no folder supplies; empty is normal here.
     * @return self
     * @throws SoundCloudException if the folder has no title, which is the one field a track needs.
     */
    public static function forRelease(ReleaseFolder $folder, FilePart $audio, string $description = ''): self
    {
        if ($folder->title === null) {
            throw new SoundCloudException(sprintf(
                '%s has no title, so there is nothing to call the track. Run stage-release --check '
                . 'to see which facts the folder is missing.',
                $folder->directory->path,
            ));
        }

        $cover = $folder->cover;

        return new self(
            $folder->title,
            $audio,
            TrackSharing::Private,
            (string) $folder->slug(),
            $description,
            $folder->genre,
            $cover?->isWebExport() === true ? FilePart::at($cover->file) : null,
        );
    }

    /**
     * The multipart fields, keyed by {@link TrackField}.
     *
     * An empty value is left out rather than sent empty, which is the same distinction
     * `Element::attr()` draws between `null` and `''`: a description nobody wrote is a field the
     * request does not carry, not a field carrying nothing.
     *
     * @return Collection<FormField>
     */
    public function fields(): Collection
    {
        $fields = new Collection(FormField::class)->with(
            FormField::of(TrackField::Title, $this->title),
            FormField::of(TrackField::AssetData, $this->audio),
            FormField::of(TrackField::Sharing, $this->sharing->value),
        );

        if ($this->permalink !== '') {
            $fields = $fields->with(FormField::of(TrackField::Permalink, $this->permalink));
        }

        if ($this->description !== '') {
            $fields = $fields->with(FormField::of(TrackField::Description, $this->description));
        }

        if ($this->genre !== null) {
            $fields = $fields->with(FormField::of(TrackField::Genre, $this->genre->value));
        }

        if ($this->artwork !== null) {
            $fields = $fields->with(FormField::of(TrackField::ArtworkData, $this->artwork));
        }

        return $fields;
    }
}
