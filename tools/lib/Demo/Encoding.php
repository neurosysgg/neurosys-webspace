<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Demo;

use NeuroSYS\Support\File;

/**
 * The Encoding enum. What is done to a master on its way into `data/demos/`.
 *
 * Two answers, decided by what the source already is, and the distinction is worth a type rather
 * than a boolean parameter: **re-encoding an already-lossy file is a generation loss for nothing.**
 * A 30 MB FLAC has to become something a browser will stream, so it is encoded; a demo that was
 * only ever bounced as an MP3 is already that, so it is remuxed — the same audio, in a fresh
 * container, at no cost in quality.
 *
 * Both paths strip metadata and artwork, which is the half they have in common and the reason the
 * lossy path is a remux rather than a copy: `cp` would carry the master's ID3 tags, and those hold
 * the working title and, on at least one file here, a note to self. A demo may end up forwarded;
 * what it carries should be the audio and nothing else. See {@link \NeuroSYS\Tool\Release\Probe::encode()}.
 */
enum Encoding
{
    /** Encode to 192 kbps CBR MP3. What a lossless master gets. */
    case Mp3;

    /** Keep the audio exactly as it is and rebuild the container. What an already-lossy file gets. */
    case Remux;

    /**
     * Which one $file wants, decided by its extension.
     *
     * Anything not named here is treated as lossless and encoded, which is the safe way round: a
     * format nobody listed is more likely to be a big uncompressed export than a small lossy one,
     * and encoding something that was already small costs a little quality where remuxing something
     * huge would ship a 30 MB file to a browser.
     *
     * @param File $file
     * @return self
     */
    public static function forSource(File $file): self
    {
        return match (strtolower($file->extension())) {
            'mp3', 'm4a', 'ogg', 'opus' => self::Remux,
            default                     => self::Mp3,
        };
    }

    /**
     * The extension the staged file ends up with.
     *
     * A remux has to keep the source's container — MP3 audio does not go into an Ogg and Opus does
     * not go into an MP3 — so only the encoding path gets to name the extension.
     *
     * @param File $source
     * @return string
     */
    public function extensionFor(File $source): string
    {
        return $this === self::Mp3 ? 'mp3' : strtolower($source->extension());
    }

    /**
     * The codec half of the ffmpeg invocation.
     *
     * @return list<string>
     */
    public function arguments(): array
    {
        return match ($this) {
            self::Mp3   => ['-codec:a', 'libmp3lame', '-b:a', '192k'],
            self::Remux => ['-codec:a', 'copy'],
        };
    }
}
