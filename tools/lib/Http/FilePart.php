<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Http;

use NeuroSYS\Http\MimeType;
use NeuroSYS\Http\TopLevelType;
use NeuroSYS\Model\ReleaseFormat;
use NeuroSYS\Support\File;

/**
 * The FilePart class. One field of a multipart request whose value is a file on disk.
 *
 * It stays a {@link File} all the way to {@link CurlTransport}, which is the point: the master this
 * is built for is 45 MB, and a multipart body assembled in PHP would be 45 MB of string. curl
 * streams the file off disk instead, and what this class carries is only what the part's headers
 * need.
 *
 * The other half of the reason is testability. A `CURLFile` constructed here would put a curl
 * handle's private type in the middle of a value object, and a fake {@link Transport} could assert
 * nothing about it. A `FilePart` has a path, a filename and a type a test can read.
 */
final readonly class FilePart
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param File     $file     The file to send. Read by the transport, not by this.
     * @param string   $filename The name the far end is told, which need not be the name on disk.
     * @param MimeType $type     The part's `Content-Type`. Never carries a charset: audio is bytes.
     */
    public function __construct(
        public File     $file,
        public string   $filename,
        public MimeType $type,
    ) {}

    /**
     * A part for a file, taking its filename and its type from the file itself.
     *
     * @param File        $file
     * @param string|null $filename Overrides the name on disk, for a working file whose name is not
     *                              the one the release goes out under.
     * @return self
     */
    public static function at(File $file, ?string $filename = null): self
    {
        return new self(
            $file,
            $filename ?? $file->name(),
            self::typeOf(ReleaseFormat::tryFrom($file->extension())),
        );
    }

    /**
     * The media type for a release format.
     *
     * The extension is resolved through {@link ReleaseFormat} rather than matched as a string,
     * because that enum is already the site's list of what a release is distributed as. What it is
     * **not** is a list of media types, which is why the subtype is written out per case instead of
     * taken from the backing value: MP3's registered type is `audio/mpeg`, and `audio/mp3` is a
     * spelling that exists in the wild and in no registry. Deriving it would have been right four
     * times out of six and silently wrong twice.
     *
     * `STEMS` is a zip and not audio at all, and anything unrecognised falls back the same way.
     * That fallback is not a failure — the far end sniffs the bytes, and a type stated wrongly is
     * worse than one not stated.
     *
     * @param ReleaseFormat|null $format
     * @return MimeType
     */
    private static function typeOf(?ReleaseFormat $format): MimeType
    {
        return match ($format) {
            ReleaseFormat::FLAC => new MimeType(TopLevelType::Audio, 'flac', null),
            ReleaseFormat::WAV  => new MimeType(TopLevelType::Audio, 'wav', null),
            ReleaseFormat::MP3  => new MimeType(TopLevelType::Audio, 'mpeg', null),
            ReleaseFormat::AIFF => new MimeType(TopLevelType::Audio, 'aiff', null),
            ReleaseFormat::OGG  => new MimeType(TopLevelType::Audio, 'ogg', null),
            default             => new MimeType(TopLevelType::Application, 'octet-stream', null),
        };
    }
}
