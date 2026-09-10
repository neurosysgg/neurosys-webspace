<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Tool\Dsp\Analyze;
use NeuroSYS\Tool\Dsp\Fft;
use NeuroSYS\Tool\Dsp\Spectrum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `tools/lib/Dsp/` — the PHP port of `c-µdsp`'s fft.c, analyze.c and spectrum.c.
 *
 * **These are that library's own tests, ported alongside the code they cover.** Every assertion
 * below has a counterpart in `c-µdsp/tests/`, in the same order and asserting the same thing, and
 * that is deliberate: a port is only worth anything if it answers what the original answered. The
 * approach is the one `c-µdsp/CLAUDE.md` states — known input, known output, exact values pinned
 * rather than "above the floor", so a regression in the maths shows up as a failure instead of
 * drifting.
 *
 * **The one systematic difference is the epsilon.** The C is `float` throughout and PHP has only
 * the wider type, so where `fft_test.c` compares against `0.01f` this compares against a tolerance
 * chosen for doubles. More precision, never less — see {@link Fft}, which is where the three
 * deviations of the whole port are written down.
 *
 * No `#[CoversClass]`, like every other test over `tools/`: that namespace is outside
 * `phpunit.xml.dist`'s coverage source, and with `failOnWarning` on, naming a class outside it
 * turns a warning into a failing test.
 */
final class DspTest extends TestCase
{
    /** What "close enough" means for a double where the C compared floats. */
    private const float EPSILON = 1.0e-9;

    /**
     * Ports `fft_test.c`'s `test_hann_window()`.
     *
     * @return void
     */
    public function testTheHannWindowIsZeroAtBothEndsAndPeaksInTheMiddle(): void
    {
        $windowed = Fft::hann(array_fill(0, 8, 1.0));

        self::assertLessThan(0.01, $windowed[0], 'the Hann window is ~0 at the first sample');
        self::assertLessThan(0.01, $windowed[7], 'the Hann window is ~0 at the last sample');
        self::assertTrue(
            $windowed[3] > 0.9 || $windowed[4] > 0.9,
            'the Hann window peaks near the centre',
        );
    }

    /**
     * Ports `fft_test.c`'s `test_hann_window_in_place()`, which is the one case the port answers
     * differently — and trivially so.
     *
     * The C documents that `in` and `out` may be the same buffer, because a caller owning both is
     * the arrangement that keeps the library allocation-free. {@link Fft::hann()} returns a new
     * array, so aliasing is not a thing that can happen; what is left worth asserting is that the
     * input is not modified, which is the same guarantee stated from the other side.
     *
     * @return void
     */
    public function testWindowingDoesNotDisturbWhatItWasGiven(): void
    {
        $samples  = array_fill(0, 4, 1.0);
        $windowed = Fft::hann($samples);

        self::assertSame(array_fill(0, 4, 1.0), $samples);
        self::assertLessThan(0.01, $windowed[0]);
    }

    /**
     * Ports `fft_test.c`'s `test_fft_dc_signal()`.
     *
     * @return void
     */
    public function testAConstantSignalPutsAllItsEnergyInBinZero(): void
    {
        $re = array_fill(0, 8, 1.0);
        $im = array_fill(0, 8, 0.0);

        Fft::forward($re, $im);

        $magnitude = Fft::magnitude($re, $im, 8);

        self::assertGreaterThan(7.9, $magnitude[0], "a DC signal's entire energy lands in bin 0");

        for ($i = 1; $i < 8; $i++) {
            self::assertLessThan(self::EPSILON, $magnitude[$i], "bin $i is ~0 for a pure DC signal");
        }
    }

    /**
     * Ports `fft_test.c`'s `test_fft_nyquist_signal()`.
     *
     * Alternating +1/-1 is the highest frequency an 8-sample signal can represent, so all of its
     * energy belongs at bin n/2.
     *
     * @return void
     */
    public function testAnAlternatingSignalPutsItsEnergyAtTheNyquistBin(): void
    {
        $re = [];
        $im = array_fill(0, 8, 0.0);

        for ($i = 0; $i < 8; $i++) {
            $re[$i] = $i % 2 === 0 ? 1.0 : -1.0;
        }

        Fft::forward($re, $im);

        self::assertGreaterThan(7.9, Fft::magnitude($re, $im, 8)[4]);
    }

    /**
     * Ports `analyze_test.c`'s `test_rms_known_values()` and `test_rms_is_channel_agnostic()`.
     *
     * The channel-agnostic case is the half worth keeping: `{1, -1, 1, -1}` read as one flat stream
     * is an RMS of 1.0, and that it needs no channel count at all is the property being asserted.
     *
     * @return void
     */
    public function testRmsOfKnownSignals(): void
    {
        self::assertSame(0.0, Analyze::rms(array_fill(0, 8, 0.0)), 'RMS of silence is 0');
        self::assertEqualsWithDelta(1.0, Analyze::rms(array_fill(0, 8, 1.0)), self::EPSILON);
        self::assertSame(0.0, Analyze::rms([]), 'RMS of an empty buffer is 0, not a NaN from 0/0');
        self::assertEqualsWithDelta(1.0, Analyze::rms([1.0, -1.0, 1.0, -1.0]), self::EPSILON);
    }

    /**
     * Ports `analyze_test.c`'s `test_peak()`.
     *
     * @return void
     */
    public function testPeakIsTheLargestAbsoluteValueAndZeroForNothing(): void
    {
        self::assertEqualsWithDelta(0.9, Analyze::peak([0.1, -0.9, 0.5, -0.3, 0.2]), self::EPSILON);
        self::assertSame(0.0, Analyze::peak([]));
    }

    /**
     * Ports `analyze_test.c`'s `test_mono_downmix()`.
     *
     * @return void
     */
    public function testDownmixAveragesEachFrameAndCopiesWhatIsAlreadyMono(): void
    {
        $mono = Analyze::monoDownmix([1.0, -1.0, 1.0, 0.0], 2);

        self::assertSame(0.0, $mono[0], 'a fully out-of-phase frame averages to 0');
        self::assertEqualsWithDelta(0.5, $mono[1], self::EPSILON);

        self::assertSame([0.1, 0.2, 0.3], Analyze::monoDownmix([0.1, 0.2, 0.3], 1));
    }

    /**
     * Ports `analyze_test.c`'s `test_dbfs()`.
     *
     * @param float $amplitude
     * @param float $expected
     * @return void
     */
    #[DataProvider('amplitudes')]
    public function testAnAmplitudeConvertsToDbfsAndNeverToMinusInfinity(
        float $amplitude,
        float $expected,
    ): void {
        self::assertEqualsWithDelta($expected, Analyze::dbfs($amplitude), self::EPSILON);
    }

    /**
     * @return iterable<string, array{float, float}>
     */
    public static function amplitudes(): iterable
    {
        yield 'full scale is 0 dBFS'    => [1.0, 0.0];
        yield 'half scale'              => [0.5, -6.020599913279624];
        yield 'silence clamps'          => [0.0, Analyze::DBFS_FLOOR];
        yield 'below the floor clamps'  => [0.0001, Analyze::DBFS_FLOOR];
        // Invalid, but the C is explicit that it must not crash on one.
        yield 'a negative amplitude'    => [-1.0, Analyze::DBFS_FLOOR];
    }

    /**
     * Ports `spectrum_test.c`'s `test_silence_is_floor_every_bar()`.
     *
     * @return void
     */
    public function testSilenceReadsTheFloorInEveryBar(): void
    {
        $bars = Spectrum::bars(array_fill(0, Spectrum::FFT_N, 0.0), 44100, Spectrum::BARS);

        self::assertSame(array_fill(0, Spectrum::BARS, Analyze::DBFS_FLOOR), $bars);
    }

    /**
     * Ports `spectrum_test.c`'s `test_tone_peaks_in_expected_bar()` — the assertion that pins the
     * log-spacing maths, and the one that says the port is actually the same function.
     *
     * 1 kHz over 20 Hz…22.05 kHz in 28 bars is `log(1000/20)/log(22050/20) * 28` ≈ 15.6, so bar 15.
     * The C pins that exact index for the reason it gives: a change to the spacing or to the
     * bin/frequency conversion that shifts it should fail here rather than drift.
     *
     * @return void
     */
    public function testAOneKilohertzToneLandsInTheSameBarItDoesInTheC(): void
    {
        $bars    = Spectrum::bars(self::tone(1000.0, 44100), 44100, Spectrum::BARS);
        $loudest = (int) array_search(max($bars), $bars, true);

        self::assertSame(15, $loudest, 'a 1kHz tone is loudest in bar 15 of 28');
        self::assertGreaterThan(-20.0, $bars[$loudest], 'a full-scale tone reads well above the floor');
    }

    /**
     * Ports `spectrum_test.c`'s `test_bin_count_is_flexible()`, with the spectrogram's own bin
     * count — 48, from `spectrogram.h` — even though that module is not part of this port. The
     * property under test is that `$bars` is a runtime argument rather than a constant this file
     * owns, and 48 is what the C used to demonstrate it.
     *
     * @return void
     */
    public function testTheBarCountIsAnArgumentRatherThanAConstant(): void
    {
        $bars = Spectrum::bars(self::tone(1000.0, 44100), 44100, 48);

        self::assertCount(48, $bars);
        self::assertGreaterThan(Analyze::DBFS_FLOOR, max($bars));
    }

    /**
     * A sample rate at or below the lowest band edge has no spectrum to divide up.
     *
     * The C returns a buffer of floors for this; nothing calls it that way, and it is here because
     * a guard clause that no test enters is a guard clause nobody knows is wrong.
     *
     * @return void
     */
    public function testARateWithNoSpectrumAboveTheLowestEdgeIsAllFloor(): void
    {
        $bars = Spectrum::bars(self::tone(5.0, 30), 30, 3);

        self::assertSame([Analyze::DBFS_FLOOR, Analyze::DBFS_FLOOR, Analyze::DBFS_FLOOR], $bars);
    }

    /**
     * The three-band split the demo waveform colours by, which nobody chose.
     *
     * Worth pinning because it is quoted as a fact in docs/demos.md and in {@link Spectrum}'s docblock:
     * at `$bars = 3` the log spacing lands on essentially a CDJ's low/mid/high. If the spacing ever
     * changes, the prose describing the waveform becomes wrong at the same moment.
     *
     * @return void
     */
    public function testThreeBandsSplitWhereTheWaveformSaysTheyDo(): void
    {
        $edges = [];

        for ($band = 0; $band < 3; $band++) {
            $edges[] = array_map(
                static fn(float $hz): int => (int) round($hz),
                Spectrum::bandEdges($band, 44100, 3),
            );
        }

        self::assertSame([[20, 207], [207, 2134], [2134, 22050]], $edges);
    }

    /**
     * A full-scale sine of $frequency Hz, one FFT window long.
     *
     * @param float $frequency
     * @param int   $rate
     * @return list<float>
     */
    private static function tone(float $frequency, int $rate): array
    {
        $samples = [];

        for ($i = 0; $i < Spectrum::FFT_N; $i++) {
            $samples[$i] = sin(2.0 * M_PI * $frequency * $i / $rate);
        }

        return $samples;
    }
}
