<?php

declare(strict_types=1);

namespace NeuroSYS\Model;

/**
 * The WaveformColumn class. One vertical slice of a {@link Waveform}, before it is quantised.
 *
 * It is where the two things a column carries get their scales fixed, and they are deliberately
 * different measurements. {@link self::$level} is how tall the slice is drawn; the three bands are
 * only how it is coloured.
 *
 * **The level is an RMS relative to the track's own loudest slice, and it is not a peak.** A peak
 * envelope was the obvious choice and is measurably the wrong one: on `wna-bootleg/v4.mp3` — a
 * mastered, limited bounce — **287 of 512 columns peak at or above full scale**, so the outline is
 * a flat-topped rectangle for over half the track. Its RMS over the same columns runs 0.07 to 0.72
 * and follows the arrangement exactly. Relative rather than absolute for the other half of the same
 * problem, pointing the other way: a demo is often an unmastered bounce sitting 12 dB down, and an
 * absolute scale draws that as a flat line near the axis. The cost is that heights compare within
 * a mix and not between two of them, which is the trade every CDJ makes too.
 *
 * **The bands stay absolute dBFS**, because they are read as shares of one another rather than as
 * heights — a column is coloured by which of the three dominates, and that is a ratio a second
 * normalisation would only distort.
 *
 * A four-slot array would have done the same work, and this is a class for the reason CLAUDE.md
 * gives about `Attribute`: a tuple only reads correctly if you already know which slot is which,
 * and here the slots are not even in the same unit.
 *
 * Only the tooling builds one — {@link \NeuroSYS\Tool\Demo\WaveformScan} — and it lives here beside
 * {@link Waveform} rather than there because it is half of a format, and a format's two ends have
 * to agree. The site never constructs one; it parses bytes.
 */
final readonly class WaveformColumn
{
    /**
     * The quietest dBFS the band scale represents, below which a band reads 0.
     *
     * **This must equal {@link \NeuroSYS\Tool\Dsp\Analyze::DBFS_FLOOR}**, and `WaveformTest`
     * asserts that it does. It is written twice rather than shared because the two say different
     * things that happen to coincide: that one is a meter's bottom, a convention `c-µdsp` chose;
     * this one is the bottom of an 8-bit scale, which is this format's own business. `src/` also
     * cannot read a constant out of `tools/` — `deploy.sh` uploads one of them and not the other.
     */
    public const float DBFS_FLOOR = -60.0;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param float $level This slice's RMS as a fraction of the loudest slice in the same track,
     *                     so 1.0 somewhere in every waveform. Clamped, not checked.
     * @param float $low   The 20–207 Hz band's level in dBFS.
     * @param float $mid   The 207–2134 Hz band's.
     * @param float $high  The 2134 Hz–Nyquist band's.
     */
    public function __construct(
        public float $level,
        public float $low,
        public float $mid,
        public float $high,
    ) {}

    /**
     * This column as {@link WaveformBand::stride()} bytes, in {@link WaveformBand} order.
     *
     * @return string
     */
    public function bytes(): string
    {
        return pack(
            'C*',
            self::quantiseFraction($this->level),
            self::quantiseLevel($this->low),
            self::quantiseLevel($this->mid),
            self::quantiseLevel($this->high),
        );
    }

    /**
     * A 0–1 fraction to a byte.
     *
     * @param float $fraction
     * @return int
     */
    private static function quantiseFraction(float $fraction): int
    {
        return max(0, min(255, (int) round($fraction * 255)));
    }

    /**
     * A dBFS level to a byte, over {@link self::DBFS_FLOOR}…0.
     *
     * @param float $level
     * @return int
     */
    private static function quantiseLevel(float $level): int
    {
        return self::quantiseFraction(($level - self::DBFS_FLOOR) / -self::DBFS_FLOOR);
    }
}
