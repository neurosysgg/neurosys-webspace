<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Dsp;

/**
 * The Fft class. `c-µdsp/src/fft.c`, in PHP.
 *
 * Generic power-of-two FFT machinery, with no audio-domain concept in it at all — no Hz, no dB, no
 * channels. That isolation is the original's, and it is why this file was portable in the first
 * place: {@link Spectrum} is the one thing here that knows what a frequency is, and this is the
 * numerical primitive underneath it.
 *
 * **Three deviations from the C, and they are the only ones.**
 *
 * - **A `float` is a double.** The C is `float` throughout; PHP has one floating type and it is the
 *   wider one. More precision than the original, never less — which is why {@link \DspTest} ports
 *   `fft_test.c`'s assertions with an epsilon rather than as exact compares.
 * - **A length argument is gone wherever the array already carries it.** `udsp_fft_forward(re, im,
 *   n)` needs `n` because a pointer has no length; a PHP array does. {@link self::magnitude()} is
 *   the exception and keeps its count, because {@link Spectrum::bars()} asks it for the first half
 *   of the spectrum rather than for all of it — there the number is a real argument.
 * - **{@link self::hann()} and {@link self::magnitude()} return arrays** where the C writes into a
 *   caller-owned scratch buffer. There is no scratch buffer to own in PHP, so the convention that
 *   makes the C library allocation-free has nothing to attach to here.
 *
 * What is *not* a deviation: this trusts its caller, exactly as the original does. `n` a power of
 * two is the caller's responsibility and is not re-checked — every call site passes
 * {@link Spectrum::FFT_N}.
 */
final readonly class Fft
{
    /**
     * In-place radix-2 Cooley-Tukey, decimation-in-time.
     *
     * $re and $im hold the signal's real and imaginary parts on entry and its unnormalised DFT on
     * return. For a real-valued input a caller sets every $im to 0.0 and only the first n/2+1
     * output bins are distinct, the rest mirroring them — {@link Spectrum::bars()} is the one place
     * that relies on that symmetry.
     *
     * @param list<float> $re
     * @param list<float> $im
     * @return void
     */
    public static function forward(array &$re, array &$im): void
    {
        $n = count($re);

        // Bit-reversal permutation — the standard iterative reordering that lets the butterfly
        // passes below work on adjacent and strided pairs rather than on a recursion.
        for ($i = 1, $j = 0; $i < $n; $i++) {
            for ($bit = $n >> 1; $j & $bit; $bit >>= 1) {
                $j ^= $bit;
            }

            $j ^= $bit;

            if ($i < $j) {
                [$re[$i], $re[$j]] = [$re[$j], $re[$i]];
                [$im[$i], $im[$j]] = [$im[$j], $im[$i]];
            }
        }

        for ($len = 2; $len <= $n; $len <<= 1) {
            $angle = -2.0 * M_PI / $len;
            $wr    = cos($angle);
            $wi    = sin($angle);
            $half  = $len >> 1;

            for ($i = 0; $i < $n; $i += $len) {
                $curR = 1.0;
                $curI = 0.0;

                for ($k = 0; $k < $half; $k++) {
                    $a = $i + $k;
                    $b = $a + $half;

                    $ur = $re[$a];
                    $ui = $im[$a];
                    $vr = $re[$b] * $curR - $im[$b] * $curI;
                    $vi = $re[$b] * $curI + $im[$b] * $curR;

                    $re[$a] = $ur + $vr;
                    $im[$a] = $ui + $vi;
                    $re[$b] = $ur - $vr;
                    $im[$b] = $ui - $vi;

                    $nextR = $curR * $wr - $curI * $wi;
                    $curI  = $curR * $wi + $curI * $wr;
                    $curR  = $nextR;
                }
            }
        }
    }

    /**
     * Multiplies each sample by a Hann window.
     *
     * Reduces the spectral leakage that comes of analysing a finite, non-periodic slice of a
     * continuous signal.
     *
     * @param list<float> $samples
     * @return list<float>
     */
    public static function hann(array $samples): array
    {
        $n = count($samples);

        if ($n < 2) {
            return $samples;
        }

        $out = [];

        for ($i = 0; $i < $n; $i++) {
            $out[$i] = $samples[$i] * (0.5 - 0.5 * cos(2.0 * M_PI * $i / ($n - 1)));
        }

        return $out;
    }

    /**
     * The magnitude of each of the first $bins complex bins, discarding phase.
     *
     * Nothing downstream of {@link Spectrum} ever needs phase, which is why this collapses it here
     * rather than handing back a complex spectrum for a caller to reduce.
     *
     * @param list<float> $re
     * @param list<float> $im
     * @param int         $bins How many bins to answer for — a real argument, not the array's own
     *                          length: a real signal's spectrum mirrors after n/2.
     * @return list<float>
     */
    public static function magnitude(array $re, array $im, int $bins): array
    {
        $out = [];

        for ($i = 0; $i < $bins; $i++) {
            $out[$i] = sqrt($re[$i] * $re[$i] + $im[$i] * $im[$i]);
        }

        return $out;
    }
}
