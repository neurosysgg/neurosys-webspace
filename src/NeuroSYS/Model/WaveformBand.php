<?php

declare(strict_types=1);

namespace NeuroSYS\Model;

/**
 * The WaveformBand enum. What each of a {@link Waveform} column's four bytes means.
 *
 * The backing value is the byte's **offset within the column**, so the enum is the format rather
 * than a label for it: the tool writes a column in this order and `DemoWaveform.ts` reads it back
 * at these offsets. `assets/ts/model/WaveformBand.ts` mirrors it under the parity test, which is
 * what stops the two halves of a binary layout drifting apart — the failure mode being a waveform
 * drawn in the wrong colours, which reads as a design choice rather than as a bug.
 *
 * **{@link self::Level} is not one of the three bands, and the four bytes are not one measurement.**
 * The level is how tall a column is drawn; the three bands are only how it is coloured, as shares
 * of each other. That split is what makes the picture read: heights from an amplitude, colour from
 * a spectrum. See {@link WaveformColumn} for why each is measured the way it is.
 */
enum WaveformBand: int
{
    /** How loud this slice is, against the loudest slice of the same track. The bar's height. */
    case Level = 0;

    /** 20–207 Hz at 44.1 kHz. */
    case Low = 1;

    /** 207–2134 Hz. */
    case Mid = 2;

    /** 2134 Hz–Nyquist. */
    case High = 3;

    /**
     * How many bytes one column takes, which is how many cases there are.
     *
     * Derived rather than written down, so adding a band cannot leave the stride behind.
     *
     * @return int
     */
    public static function stride(): int
    {
        return count(self::cases());
    }

    /**
     * The three that come from {@link \NeuroSYS\Tool\Dsp\Spectrum::bars()}, in band order.
     *
     * {@link self::Level} is measured differently and is not one of them — see the class docblock.
     *
     * @return list<self>
     */
    public static function bands(): array
    {
        return [self::Low, self::Mid, self::High];
    }
}
