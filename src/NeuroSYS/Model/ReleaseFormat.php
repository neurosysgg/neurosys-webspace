<?php

declare(strict_types=1);

namespace NeuroSYS\Model;

use Phpanta\Http\MimeType;
use Phpanta\Http\TopLevelType;

/**
 * The ReleaseFormat enum. Audio file formats supported for release downloads.
 */
enum ReleaseFormat: string
{
    case FLAC  = 'flac';
    case MP3   = 'mp3';
    case WAV   = 'wav';
    case AIFF  = 'aiff';
    case STEMS = 'stems';
    case OGG   = 'ogg';

    /**
     * Returns the human-readable display label for this format.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::FLAC  => 'FLAC',
            self::MP3   => 'MP3',
            self::WAV   => 'WAV',
            self::AIFF  => 'AIFF',
            self::STEMS => 'Stems',
            self::OGG   => 'OGG',
        };
    }

    /**
     * Returns true if this format is lossless (no quality loss from encoding).
     *
     * @return bool
     */
    public function isLossless(): bool
    {
        return match ($this) {
            self::FLAC, self::WAV, self::AIFF, self::STEMS => true,
            self::MP3, self::OGG                           => false,
        };
    }

    /**
     * The type a file in this format is sent as — to SoundCloud, as the audio of an upload.
     *
     * Stems are an archive of several files rather than one sound, so they have no audio type and
     * go as bytes.
     *
     * @return MimeType
     */
    public function mimeType(): MimeType
    {
        return match ($this) {
            self::FLAC  => new MimeType(TopLevelType::Audio, 'flac', null),
            self::WAV   => new MimeType(TopLevelType::Audio, 'wav', null),
            self::MP3   => new MimeType(TopLevelType::Audio, 'mpeg', null),
            self::AIFF  => new MimeType(TopLevelType::Audio, 'aiff', null),
            self::OGG   => new MimeType(TopLevelType::Audio, 'ogg', null),
            self::STEMS => new MimeType(TopLevelType::Application, 'octet-stream', null),
        };
    }
}
