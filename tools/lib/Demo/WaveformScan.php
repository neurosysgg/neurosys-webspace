<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Demo;

use NeuroSYS\Model\Waveform;
use NeuroSYS\Model\WaveformColumn;
use NeuroSYS\Support\File;
use NeuroSYS\Tool\Dsp\Analyze;
use NeuroSYS\Tool\Dsp\Spectrum;
use NeuroSYS\Tool\Release\Probe;

/**
 * The WaveformScan class. Reads one staged mix and answers the shape of it.
 *
 * This is the consumer half of the port in `tools/lib/Dsp/`, and the split is the C library's own:
 * nothing under `Dsp/` opens a file, decides a column count or knows what a demo is, exactly as
 * nothing under `c-µdsp/src/` knows about `ctui-mus`'s threading. Everything I/O-shaped is here.
 *
 * **It reads the staged MP3, not the master**, which is the right way round twice over. It is what
 * the listener actually hears, so the waveform describes the file the page serves; and it is what
 * makes `--waveforms` possible at all, since the masters live on one machine and a demo staged
 * months ago may have none of them left.
 *
 * ~12 seconds per mix. That is a build-time number and the reason none of this is on the server:
 * see {@link Waveform}, which is only the format.
 */
final readonly class WaveformScan
{
    /**
     * The rate the audio is decoded at, and therefore the rate the analysis assumes.
     *
     * Not the file's own. A fixed rate is what keeps {@link Spectrum}'s band edges fixed — they are
     * placed between {@link Spectrum::MIN_HZ} and Nyquist, so a mix bounced at 48 kHz would
     * otherwise be coloured against slightly different bands than one at 44.1, and the stylesheet
     * has one set of colours for both. Resampling is free here; ffmpeg is already decoding.
     */
    public const int RATE = 44100;

    /**
     * How many FFT windows are taken per column, spread evenly across it.
     *
     * One is not enough and the arithmetic says why: a column of a three-minute track is ~307 ms
     * and an FFT window is 46 ms, so a single window describes a seventh of what the column stands
     * for. At 140 bpm that is narrower than a sixteenth note, and a window that lands between two
     * kicks colours the whole column as though the low end were not there — which over 512 columns
     * reads as flicker rather than as a mistake.
     *
     * Three is where it stops being visible and before it stops being cheap: the cost is linear and
     * this is the whole cost of the scan. Full coverage would be ~7 and ~26 seconds a mix.
     */
    private const int WINDOWS_PER_COLUMN = 3;

    /** Low, mid, high — {@link \NeuroSYS\Model\WaveformBand::bands()}, from this side. */
    private const int BANDS = 3;

    /**
     * Analyses one audio file.
     *
     * @param File $audio A staged mix, in whatever container it was staged as.
     * @return Waveform|null null if ffmpeg could not decode it, which is the same answer
     *                       {@link Probe::decode()} gives for ffmpeg not being installed at all.
     */
    public static function of(File $audio): ?Waveform
    {
        $samples = Probe::decode($audio, self::RATE);

        if ($samples === null) {
            return null;
        }

        $frames = intdiv(strlen($samples), 4);

        if ($frames < 1) {
            return null;
        }

        $span    = $frames / Waveform::COLUMNS;
        $energy  = [];
        $spectra = [];

        for ($c = 0; $c < Waveform::COLUMNS; $c++) {
            $from = (int) ($c * $span);
            $to   = max($from + 1, (int) (($c + 1) * $span));

            $energy[$c]  = Analyze::rms(self::read($samples, $frames, $from, $to - $from));
            $spectra[$c] = self::spectrum($samples, $frames, $from, $to);
        }

        // The normalisation is why this is two passes rather than one: a column's height is its
        // share of the loudest column, and which column that is cannot be known until the last one
        // has been read. A silent file divides by nothing and comes back flat rather than as a NAN.
        $loudest = max($energy) ?: 1.0;
        $columns = [];

        for ($c = 0; $c < Waveform::COLUMNS; $c++) {
            $columns[] = new WaveformColumn(
                $energy[$c] / $loudest,
                $spectra[$c][0],
                $spectra[$c][1],
                $spectra[$c][2],
            );
        }

        return Waveform::of(...$columns);
    }

    /**
     * One column's three band levels in dBFS, low first.
     *
     * **The height and the colour cover different amounts of the column, deliberately.** The RMS
     * above reads every sample in it, because that is the number the shape is made of. The colour
     * reads {@link self::WINDOWS_PER_COLUMN} windows and takes the **loudest** each band reaches in
     * any of them — the same "loudest, not averaged" rule {@link Spectrum::bars()} applies within a
     * band, for the same reason: a band that is briefly enormous is what the column is about, and
     * averaging it against the silence either side hides exactly that.
     *
     * @param string $samples
     * @param int    $frames
     * @param int    $from
     * @param int    $to
     * @return list<float>
     */
    private static function spectrum(string $samples, int $frames, int $from, int $to): array
    {
        $levels = array_fill(0, self::BANDS, Analyze::DBFS_FLOOR);
        $stride = intdiv(max(1, $to - $from), self::WINDOWS_PER_COLUMN);

        for ($w = 0; $w < self::WINDOWS_PER_COLUMN; $w++) {
            $window = Spectrum::bars(
                self::read($samples, $frames, $from + $w * $stride, Spectrum::FFT_N),
                self::RATE,
                self::BANDS,
            );

            foreach ($window as $band => $level) {
                $levels[$band] = max($levels[$band], $level);
            }
        }

        return $levels;
    }

    /**
     * $count samples from frame $from, zero-padded where the track ends first.
     *
     * The padding is not a nicety: {@link Spectrum::bars()} is documented as taking exactly
     * {@link Spectrum::FFT_N} samples and does not check, so the last few columns of every track
     * would otherwise hand it a short array and read whatever `$magnitude[$k]` found.
     *
     * @param string $samples
     * @param int    $frames
     * @param int    $from
     * @param int    $count
     * @return list<float>
     */
    private static function read(string $samples, int $frames, int $from, int $count): array
    {
        $available = max(0, min($count, $frames - $from));

        if ($available < 1) {
            return array_fill(0, $count, 0.0);
        }

        /** @var list<float> $read */
        $read = array_values((array) unpack('g' . $available, $samples, $from * 4));

        return $available === $count ? $read : array_pad($read, $count, 0.0);
    }
}
