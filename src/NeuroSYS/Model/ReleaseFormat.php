<?php

declare(strict_types=1);

namespace NeuroSYS\Model;

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

    /** Returns the human-readable display label for this format. */
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

    /** Returns true if this format is lossless (no quality loss from encoding). */
    public function isLossless(): bool
    {
        return match ($this) {
            self::FLAC, self::WAV, self::AIFF, self::STEMS => true,
            self::MP3, self::OGG                           => false,
        };
    }
}
