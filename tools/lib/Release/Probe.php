<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Release;

use ZipArchive;

/**
 * The Probe class. Every shell-out this tooling makes, in one place.
 *
 * `metaflac` and `ffprobe` are the two things here that are not PHP, and keeping them behind one
 * class means the escaping is stated once and a machine without them fails the same way everywhere.
 *
 * Failure is an empty result rather than an exception: a missing tag and a missing tool both mean
 * "this folder did not tell us", which the fallback ladders in {@link ReleaseFolder} already handle.
 */
final readonly class Probe
{
    /**
     * Runs a command and returns its stdout lines, discarding stderr.
     *
     * @param list<string> $command Program first, then one argument per element — never a shell string.
     * @return list<string>
     */
    public static function run(array $command): array
    {
        $escaped = implode(' ', array_map(escapeshellarg(...), $command));

        exec($escaped . ' 2>/dev/null', $output, $status);

        return $status === 0 ? $output : [];
    }

    /**
     * Reads a FLAC's Vorbis comments, keyed by {@link FlacTag}.
     *
     * A comment this tooling has no case for is dropped rather than carried as a string, so what
     * comes back is exactly the vocabulary the rest of the code knows how to ask about.
     *
     * @param string $flac
     * @return array<string, string> FlacTag value => comment value.
     */
    public static function tags(string $flac): array
    {
        $tags = [];

        foreach (self::run(['metaflac', '--export-tags-to=-', $flac]) as $line) {
            if (!str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);

            if (($tag = FlacTag::tryFrom(strtoupper($name))) !== null) {
                $tags[$tag->value] = $value;
            }
        }

        return $tags;
    }

    /**
     * Reads an audio file's stream properties.
     *
     * @param string $file
     * @return AudioStream|null null if the file cannot be probed at all.
     */
    public static function stream(string $file): ?AudioStream
    {
        $values = self::ffprobe($file, 'stream=codec_name,sample_rate,bits_per_raw_sample:format=duration');

        if (count($values) < 4) {
            return null;
        }

        [$codec, $rate, $bits, $duration] = $values;

        // A WAV states its depth in `bits_per_sample` instead, which ffprobe reports as N/A above;
        // ask the sample format rather than calling the file depthless.
        if ($bits === 'N/A') {
            $bits = self::ffprobe($file, 'stream=bits_per_sample')[0] ?? '0';
        }

        return new AudioStream($codec, (int) $rate, (int) $bits > 0 ? (int) $bits : null, (float) $duration);
    }

    /**
     * Whether a FLAC carries an embedded cover picture.
     *
     * @param string $flac
     * @return bool
     */
    public static function hasPicture(string $flac): bool
    {
        return self::run(['metaflac', '--list', '--block-type=PICTURE', $flac]) !== [];
    }

    /**
     * Lists a zip's file entries, keyed by their path inside the archive, with uncompressed sizes.
     *
     * The path is kept rather than the base name so {@link Preflight} compares the whole packaged
     * tree — a remix package is a stems folder *and* whatever sits beside it, and `hello world!`
     * keeps a MIDI there.
     *
     * @param string $zip
     * @return array<string, int>|null null if the zip cannot be read at all.
     */
    public static function zipEntries(string $zip): ?array
    {
        if (!class_exists(ZipArchive::class)) {
            return null;
        }

        $archive = new ZipArchive();

        if ($archive->open($zip) !== true) {
            return null;
        }

        $entries = [];

        for ($i = 0; $i < $archive->numFiles; $i++) {
            $stat = $archive->statIndex($i);

            if ($stat !== false && !str_ends_with($stat['name'], '/')) {
                $entries[$stat['name']] = $stat['size'];
            }
        }

        $archive->close();

        return $entries;
    }

    /**
     * One ffprobe invocation, asking for named entries off the first audio stream.
     *
     * @param string $file
     * @param string $entries
     * @return list<string>
     */
    private static function ffprobe(string $file, string $entries): array
    {
        return self::run([
            'ffprobe', '-v', 'error', '-select_streams', 'a:0',
            '-show_entries', $entries, '-of', 'default=noprint_wrappers=1:nokey=1', $file,
        ]);
    }
}
