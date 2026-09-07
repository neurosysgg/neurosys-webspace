<?php

declare(strict_types=1);

namespace NeuroSYS\Model;

use NeuroSYS\Exception\ReleaseVerificationException;
use NeuroSYS\Support\Directory;
use NeuroSYS\Support\File;

/**
 * The Waveform class. A demo mix's whole shape, in two kilobytes.
 *
 * {@link self::COLUMNS} slices of the track, each {@link WaveformBand::stride()} bytes: how loud
 * the slice is, and how its energy is spread across three log-spaced bands. `<demo-waveform>` draws
 * it behind the player, which is what makes a demo card read like a deck screen rather than a row
 * with a control on it.
 *
 * **It is generated output and lives with the generated audio**, at
 * `data/demos/{slug}/{label}.wave` — not in `data/demos.php`. That is
 * {@link \NeuroSYS\Tool\Demo\DemoEntryWriter}'s own rule applied one step further: the audio is
 * generated with a name derived from a label and the data file is a hand-ordered list, so a
 * two-kilobyte blob belongs on the generated side of that line. Two things follow free — `deploy.sh`
 * already rsyncs `data/` from the working tree, and `.gitignore` already covers `data/demos/`.
 *
 * **The analysis is not here and could not be.** Computing one is ~6 seconds of FFT per mix (see
 * {@link \NeuroSYS\Tool\Dsp\Spectrum}), which is a build-time number and not a shared-hosting
 * request. This class is only the format: the tooling writes it, the site reads it back and hands
 * it to an attribute, and nothing on the server ever looks inside a column.
 *
 * **A missing or malformed file is `null` rather than an exception.** {@link self::parse()} takes
 * what {@link File::read()} answers with, including its null, and collapses absent, unreadable and
 * corrupt to the same thing — because they are the same thing to the page above, which draws a card
 * without a waveform. A demo staged before this existed is exactly that case and is not a fault.
 */
final readonly class Waveform
{
    /**
     * The first four bytes of the file.
     *
     * Not decoration: `data/demos/{slug}/` holds audio, and a reader that only checked a length
     * would accept any 2,052-byte file dropped in there. The trailing digit is the layout's
     * version — a fifth byte per column would be `NSW2`, and an old file would then read as
     * unparseable rather than as a waveform drawn wrong.
     */
    public const string MAGIC = 'NSW1';

    /**
     * How many slices a whole track is reduced to, whatever its length.
     *
     * 512 over a card ~700px wide is a shade under one and a half pixels per column — finer than
     * the display, so the canvas averages rather than invents. It is also what fixes the size: 2,052
     * bytes on disk and 2,732 base64 characters in the attribute, per mix.
     *
     * **What that costs on the wire is 2 KB, not 2.7.** The attribute rides inside the demo page,
     * which is `text/html`, which `public/.htaccess` hands to `mod_deflate` — and gzip gives back
     * almost exactly what base64's four-thirds took: 2,732 characters compress to 2,032 bytes,
     * measured on a synthetic three-minute bounce, and a mix with an actual arrangement in it does
     * better still, because a level column that repeats is a level column that compresses.
     *
     * That is worth stating carefully rather than confidently, and this docblock previously said
     * the opposite — that Strato compressed nothing, so the base64 figure was the wire figure. It
     * was written from a reading taken on 2026-09-05, when that was true; the same host was serving
     * gzip again on 2026-09-06 with nothing in this repository having changed. Both `.htaccess`
     * blocks are `<IfModule>`-guarded, so the module coming or going is silence in either
     * direction. See CLAUDE.md's deployment section, which carries the check to re-run and the two
     * readings that motivated it. **Do not size anything here on compression being present.**
     */
    public const int COLUMNS = 512;

    /** The sidecar's extension. `v4.mp3` is a mix; `v4.wave` is its shape. */
    public const string EXTENSION = 'wave';

    /**
     * Constructs an instance of {@link self}.
     *
     * Private because there are exactly two ways to get one and each checks something different:
     * {@link self::of()} refuses a wrong number of columns, {@link self::parse()} refuses bytes it
     * cannot vouch for.
     *
     * @param string $bytes The whole file, magic included.
     */
    private function __construct(private string $bytes) {}

    /**
     * Builds one from the columns an analysis produced.
     *
     * A variadic rather than a {@link \NeuroSYS\Support\Collection}, per CLAUDE.md's rule: a
     * collection replaces a hand-rolled type check on data crossing a public boundary, and it does
     * not replace a variadic, which PHP already enforces at the same boundary.
     *
     * @param WaveformColumn ...$columns Exactly {@link self::COLUMNS} of them.
     * @return self
     *
     * @throws ReleaseVerificationException if given a different number of columns.
     */
    public static function of(WaveformColumn ...$columns): self
    {
        if (count($columns) !== self::COLUMNS) {
            throw new ReleaseVerificationException(sprintf(
                'A Waveform is exactly %d columns, got %d. The count is the format rather than a '
                . 'preference: the element divides its width by it and draws no axis, so a short '
                . 'one is a track that appears to end early.',
                self::COLUMNS,
                count($columns),
            ));
        }

        return new self(self::MAGIC . implode('', array_map(
            static fn(WaveformColumn $column): string => $column->bytes(),
            $columns,
        )));
    }

    /**
     * Reads a sidecar's contents, or answers null for anything it cannot vouch for.
     *
     * @param string|null $bytes What {@link File::read()} answered — its null included.
     * @return self|null
     */
    public static function parse(?string $bytes): ?self
    {
        if ($bytes === null || strlen($bytes) !== self::length() || !str_starts_with($bytes, self::MAGIC)) {
            return null;
        }

        return new self($bytes);
    }

    /**
     * Where one mix's sidecar sits, beside the audio it describes.
     *
     * @param Directory $directory The demo's own directory — {@link \NeuroSYS\Config::demoDir()}.
     * @param string    $label     The mix's label, which is also what its audio file is named for.
     * @return File
     */
    public static function fileIn(Directory $directory, string $label): File
    {
        return $directory->file($label . '.' . self::EXTENSION);
    }

    /**
     * How long a well-formed file is.
     *
     * @return int
     */
    public static function length(): int
    {
        return strlen(self::MAGIC) + self::COLUMNS * WaveformBand::stride();
    }

    /**
     * The whole file, for the tooling to write.
     *
     * @return string
     */
    public function bytes(): string
    {
        return $this->bytes;
    }

    /**
     * The columns alone, base64'd, for the `peaks` attribute.
     *
     * **The magic is left behind**, and that is the one asymmetry worth stating: it identifies a
     * file on disk, and an attribute is not a file. The client reads a flat run of columns and
     * divides by the stride, with nothing to skip first.
     *
     * @return string
     */
    public function base64(): string
    {
        return base64_encode(substr($this->bytes, strlen(self::MAGIC)));
    }
}
