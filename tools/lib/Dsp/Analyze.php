<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Dsp;

/**
 * The Analyze class. `c-µdsp/src/analyze.c`, in PHP.
 *
 * Basic signal-level primitives with no frequency-domain concept at all — the time-domain half of
 * the port, where {@link Spectrum} is the frequency-domain one. {@link self::dbfs()} is the single
 * dB conversion everything here shares, so the floor and its clamp are defined exactly once.
 *
 * The deviations from the C are {@link Fft}'s, stated there and not repeated: doubles rather than
 * floats, and no length argument where the array already carries one.
 *
 * **Two of the four have no caller on this side**, which is worth saying out loud rather than
 * leaving to be noticed. `ffmpeg -ac 1` is where a demo's downmix happens — see
 * {@link \NeuroSYS\Tool\Release\Probe::decode()} — because the decode was already a shell-out and
 * doing it there costs half the bytes through the pipe, so {@link self::monoDownmix()} is unused.
 * So is {@link self::peak()}, and that one was measured rather than assumed: a waveform's outline
 * built from peaks is a flat-topped rectangle over half a mastered track, so
 * {@link \NeuroSYS\Model\WaveformColumn} takes {@link self::rms()} instead and says why.
 *
 * Both are ported because a module is the unit this port is made of, and because `analyze_test.c`
 * came with them.
 */
final readonly class Analyze
{
    /**
     * The floor every dB value here is clamped to.
     *
     * Silence is -inf dB, which is useless for a bar's fill fraction, so it reads as this instead.
     * -60 dBFS is the usual "meter bottom": well below a typical noise floor, well above -inf.
     */
    public const float DBFS_FLOOR = -60.0;

    /**
     * RMS over a flat buffer, unscaled — a plain ratio to full scale, not gained for visual punch.
     *
     * Deliberately channel-agnostic: summing squares over every sample is the same computation
     * whether or not consecutive samples belong to different channels.
     *
     * @param list<float> $samples
     * @return float
     */
    public static function rms(array $samples): float
    {
        $n = count($samples);

        if ($n === 0) {
            return 0.0;
        }

        $sumSquares = 0.0;

        foreach ($samples as $sample) {
            $sumSquares += $sample * $sample;
        }

        return sqrt($sumSquares / $n);
    }

    /**
     * The largest absolute sample value in a flat buffer, and 0.0 for an empty one.
     *
     * Same channel-agnostic reasoning as {@link self::rms()}.
     *
     * @param list<float> $samples
     * @return float
     */
    public static function peak(array $samples): float
    {
        $peak = 0.0;

        foreach ($samples as $sample) {
            $magnitude = abs($sample);

            if ($magnitude > $peak) {
                $peak = $magnitude;
            }
        }

        return $peak;
    }

    /**
     * Interleaved samples to one value per frame, by averaging each frame's channels.
     *
     * This one **is** channel-aware, unlike the two above, and the C says why: a true per-sample
     * waveform — unlike a single scalar statistic — has no channel-agnostic equivalent, because
     * an FFT needs one value per instant rather than per raw sample.
     *
     * @param list<float> $interleaved
     * @param int         $channels Anything below 2 is treated as already mono and copied.
     * @return list<float>
     */
    public static function monoDownmix(array $interleaved, int $channels): array
    {
        if ($channels <= 1) {
            return $interleaved;
        }

        $frames = intdiv(count($interleaved), $channels);
        $mono   = [];

        for ($i = 0; $i < $frames; $i++) {
            $sum = 0.0;

            for ($c = 0; $c < $channels; $c++) {
                $sum += $interleaved[$i * $channels + $c];
            }

            $mono[$i] = $sum / $channels;
        }

        return $mono;
    }

    /**
     * A linear amplitude ratio (1.0 being full scale) as dBFS, clamped to {@link self::DBFS_FLOOR}.
     *
     * Never -inf, even for exactly 0.0.
     *
     * @param float $amplitude
     * @return float
     */
    public static function dbfs(float $amplitude): float
    {
        if ($amplitude <= 0.0) {
            return self::DBFS_FLOOR;
        }

        $db = 20.0 * log10($amplitude);

        return $db < self::DBFS_FLOOR ? self::DBFS_FLOOR : $db;
    }
}
