<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Dsp;

/**
 * The Spectrum class. `c-µdsp/src/spectrum.c`, in PHP.
 *
 * The audio-domain interpretation of {@link Fft}'s numeric transform: a window of samples in, one
 * dBFS value per logarithmically-spaced frequency band out. Stateless — one call, one answer — so
 * the same method backs a few wide bars and many narrow bins with nothing but a different
 * `$bars` count.
 *
 * **Log spacing is the whole point of the file.** Music's harmonic content and human pitch
 * perception are both distributed that way, and a linear FFT bin index is not: a linear layout
 * spends most of its bars on treble nobody needs and almost none on the octaves below ~500 Hz
 * where a mix's energy actually lives.
 *
 * It is also what makes the demo waveform's colouring free rather than invented. At `$bars = 3`
 * and 44.1 kHz the edges land on **20–207 Hz, 207–2134 Hz and 2134–22050 Hz** — which is a
 * low/mid/high split nobody here chose. See {@link \NeuroSYS\Tool\Demo\WaveformScan}.
 *
 * The C's documented simplification comes across with it: {@link self::NORM} is the textbook `2/N`
 * single-sided amplitude normalisation and does **not** divide out the Hann window's own ~0.5
 * coherent gain, so a full-scale sine reads a few dB below true 0 dBFS. Fine for a relative,
 * log-scaled display; wrong for anything claiming a calibrated measurement.
 */
final readonly class Spectrum
{
    /**
     * The FFT size every spectrum computation here uses. Fixed, and a power of two because
     * {@link Fft::forward()} trusts that it is.
     *
     * 2048 samples is ~46 ms at 44.1 kHz — short enough to be responsive, long enough for ~21 Hz
     * bin resolution, which is usable detail even in the sub-bass end of a log-spaced layout.
     */
    public const int FFT_N = 2048;

    /**
     * The lowest frequency any band edge is placed at, and so the log scale's effective bottom.
     * Below this a band's width collapses to near nothing anyway.
     */
    public const float MIN_HZ = 20.0;

    /** The C's default bar count for a spectrum widget. Nothing here uses it; `$bars` is an argument. */
    public const int BARS = 28;

    /**
     * Single-sided amplitude normalisation for an N-point FFT, so a full-scale sine lands near
     * 0 dBFS rather than scaling with N. See the class docblock for what it deliberately omits.
     */
    private const float NORM = 2.0 / self::FFT_N;

    /**
     * One dBFS value per log-spaced band, from {@link self::MIN_HZ} to Nyquist.
     *
     * Each band's value is the **loudest** bin magnitude within it rather than the mean — a wide
     * quiet band should not be diluted by the silent bins in it — converted through
     * {@link Analyze::dbfs()}.
     *
     * @param list<float> $window Exactly {@link self::FFT_N} samples, mono. The caller's to get
     *                            right, as it is in the C: nothing re-checks it here.
     * @param int         $rate   The sample rate those samples were taken at.
     * @param int         $bars   How many bands to answer for.
     * @return list<float>
     */
    public static function bars(array $window, int $rate, int $bars): array
    {
        $re = Fft::hann($window);
        $im = array_fill(0, self::FFT_N, 0.0);

        Fft::forward($re, $im);

        $half      = intdiv(self::FFT_N, 2);
        $magnitude = Fft::magnitude($re, $im, $half);
        $nyquist   = $rate / 2.0;

        if ($nyquist <= self::MIN_HZ) {
            return array_fill(0, $bars, Analyze::DBFS_FLOOR);
        }

        $levels = [];

        for ($b = 0; $b < $bars; $b++) {
            $low  = self::MIN_HZ * ($nyquist / self::MIN_HZ) ** ($b / $bars);
            $high = self::MIN_HZ * ($nyquist / self::MIN_HZ) ** (($b + 1) / $bars);

            $first = (int) ($low * self::FFT_N / $rate);
            $last  = (int) ($high * self::FFT_N / $rate);

            if ($last <= $first) {
                $last = $first + 1;
            }

            $first = max(0, $first);
            $last  = min($half, $last);

            $peak = 0.0;

            for ($k = $first; $k < $last; $k++) {
                if ($magnitude[$k] > $peak) {
                    $peak = $magnitude[$k];
                }
            }

            $levels[$b] = Analyze::dbfs($peak * self::NORM);
        }

        return $levels;
    }

    /**
     * The frequency range one band of a $bars-wide layout covers, as `[low, high]` in Hz.
     *
     * The same two lines {@link self::bars()} computes inline, exposed because the numbers are
     * worth being able to state: a colour named "low" that actually covers 20–2000 Hz would be a
     * claim nothing checks. {@link \DspTest} pins the three-band edges through this.
     *
     * @param int $band
     * @param int $rate
     * @param int $bars
     * @return array{float, float}
     */
    public static function bandEdges(int $band, int $rate, int $bars): array
    {
        $nyquist = $rate / 2.0;
        $span    = $nyquist / self::MIN_HZ;

        return [
            self::MIN_HZ * $span ** ($band / $bars),
            self::MIN_HZ * $span ** (($band + 1) / $bars),
        ];
    }
}
