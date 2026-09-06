<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Release;

use NeuroSYS\Support\File;
use ZipArchive;

/**
 * The Probe class. Every shell-out this tooling makes, in one place.
 *
 * `metaflac`, `ffprobe` and `ffmpeg` are the three things here that are not PHP, and keeping them
 * behind one class means the escaping is stated once and a machine without them fails the same way
 * everywhere.
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
        exec(self::escaped($command), $output, $status);

        return $status === 0 ? $output : [];
    }

    /**
     * Runs a command for its effect rather than its output, and says whether it worked.
     *
     * {@link self::run()} cannot answer this: it collapses a failure and a success that printed
     * nothing to the same empty array, which is the right shape for the readers above — a missing
     * tag and a missing tool both mean "this file did not tell us" — and the wrong one for
     * {@link self::encode()}, where nothing is printed on success and the whole question is whether
     * the file now exists.
     *
     * @param list<string> $command Program first, then one argument per element.
     * @return bool
     */
    public static function succeeds(array $command): bool
    {
        exec(self::escaped($command), $discarded, $status);

        return $status === 0;
    }

    /**
     * Transcodes an audio file to MP3, for streaming behind the demo gate.
     *
     * A demo is sent to be listened to in a browser, and the master it is made from is a 30 MB
     * FLAC. Every byte of that would come back through PHP on shared hosting, once per listen — so
     * this is not a convenience, it is what makes the arrangement affordable at all. 192 kbps CBR
     * rather than a V0 variable rate: a browser seeking in a CBR file can calculate the byte offset
     * from the timestamp, where a VBR file without a seek table has to be scanned, and seeking is
     * the whole reason `FileResponse` answers ranges. An already-lossy source is handed
     * {@link \NeuroSYS\Tool\Demo\Encoding::Remux}'s arguments instead and is not re-encoded at all.
     *
     * **`-map_metadata -1` is deliberate.** The masters carry Vorbis comments with the working
     * title, the artist and sometimes a note to self — see `alien house v4.flac`, whose COMMENTS
     * field reads like a scratchpad. A file that may end up forwarded should carry as little of
     * that as possible; the page it came from is where the context belongs. `-vn` drops embedded
     * artwork for the same reason and because a cover in a demo MP3 is bytes nothing shows.
     *
     * @param File         $source The master, in whatever format it was exported.
     * @param File         $target Where it goes. Its directory must exist — this class does not
     *                             create one, for the reason {@link \NeuroSYS\Support\File} does not.
     * @param list<string> $codec  The codec arguments — see {@link \NeuroSYS\Tool\Demo\Encoding}.
     * @return bool
     */
    public static function encode(File $source, File $target, array $codec): bool
    {
        return self::succeeds([
            'ffmpeg', '-nostdin', '-y',
            '-i', $source->path,
            '-vn', '-map_metadata', '-1',
            ...$codec,
            $target->path,
        ]) && $target->exists() && $target->size() > 0;
    }

    /**
     * Decodes an audio file to raw mono 32-bit float samples, little-endian.
     *
     * What {@link \NeuroSYS\Tool\Demo\WaveformScan} analyses. `-f f32le` because the port in
     * `tools/lib/Dsp/` works in floats and `unpack('g')` reads exactly that; `-ac 1` because the
     * analysis needs one value per instant and ffmpeg is already running — which is why
     * {@link \NeuroSYS\Tool\Dsp\Analyze::monoDownmix()} has no caller on this side.
     *
     * **It cannot be {@link self::run()}**, and that is not a style choice: `exec()` splits stdout
     * into lines, and float bytes contain newlines about as often as any other byte. `popen()` is
     * what keeps the stream whole while still handing back an exit status, which `shell_exec()`
     * would not.
     *
     * The result is one string rather than an array of samples on purpose. A three-minute track is
     * 6.9 million of them; as a PHP array that is roughly 550 MB and does not fit, and as a string
     * it is 28 MB. Reading one window at a time back out of it is also exactly the contract the C
     * library states — the caller owns the buffer and supplies a window.
     *
     * @param File $file
     * @param int  $rate The sample rate to decode at, which is the rate the analysis then assumes.
     * @return string|null null if ffmpeg is absent, refused the file, or produced nothing.
     */
    public static function decode(File $file, int $rate): ?string
    {
        $pipe = popen(self::escaped([
            'ffmpeg', '-nostdin', '-v', 'error',
            '-i', $file->path,
            '-f', 'f32le', '-ac', '1', '-ar', (string) $rate,
            '-',
        ]), 'r');

        if ($pipe === false) {
            return null;
        }

        $samples = stream_get_contents($pipe);

        return pclose($pipe) === 0 && $samples !== false && $samples !== '' ? $samples : null;
    }

    /**
     * One command line, escaped, with stderr discarded.
     *
     * The escaping is stated once here rather than at each `exec()`, which is the whole reason this
     * class exists — see its docblock.
     *
     * @param list<string> $command
     * @return string
     */
    private static function escaped(array $command): string
    {
        return implode(' ', array_map(escapeshellarg(...), $command)) . ' 2>/dev/null';
    }

    /**
     * Reads a FLAC's Vorbis comments, keyed by {@link FlacTag}.
     *
     * A comment this tooling has no case for is dropped rather than carried as a string, so what
     * comes back is exactly the vocabulary the rest of the code knows how to ask about.
     *
     * @param File $flac
     * @return array<string, string> FlacTag value => comment value.
     */
    public static function tags(File $flac): array
    {
        $tags = [];

        foreach (self::run(['metaflac', '--export-tags-to=-', $flac->path]) as $line) {
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
     * @param File $file
     * @return AudioStream|null null if the file cannot be probed at all.
     */
    public static function stream(File $file): ?AudioStream
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
     * @param File $flac
     * @return bool
     */
    public static function hasPicture(File $flac): bool
    {
        return self::run(['metaflac', '--list', '--block-type=PICTURE', $flac->path]) !== [];
    }

    /**
     * Lists a zip's file entries, keyed by their path inside the archive, with uncompressed sizes.
     *
     * The path is kept rather than the base name so {@link Preflight} compares the whole packaged
     * tree — a remix package is a stems folder *and* whatever sits beside it, and `hello world!`
     * keeps a MIDI there.
     *
     * @param File $zip
     * @return array<string, int>|null null if the zip cannot be read at all.
     */
    public static function zipEntries(File $zip): ?array
    {
        if (!class_exists(ZipArchive::class)) {
            return null;
        }

        $archive = new ZipArchive();

        if ($archive->open($zip->path) !== true) {
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
     * @param File   $file
     * @param string $entries
     * @return list<string>
     */
    private static function ffprobe(File $file, string $entries): array
    {
        return self::run([
            'ffprobe', '-v', 'error', '-select_streams', 'a:0',
            '-show_entries', $entries, '-of', 'default=noprint_wrappers=1:nokey=1', $file->path,
        ]);
    }
}
