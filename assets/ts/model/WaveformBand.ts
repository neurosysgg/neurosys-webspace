/**
 * Mirrors NeuroSYS\Model\WaveformBand — where each of a column's four bytes sits.
 *
 * The one numeric mirror on this side, because the values are byte offsets rather than names: the
 * server packs a column in this order and this reads it back at these positions. Get one wrong and
 * the waveform is drawn in the wrong colours, which reads as a design choice rather than as a bug —
 * which is why enum-parity.test.mjs compares it against the PHP enum like every other.
 *
 * Level is not one of the three bands. It is how tall a column is drawn; the bands are only how it
 * is coloured, as shares of one another.
 */
export enum WaveformBand {
  Level = 0,
  Low = 1,
  Mid = 2,
  High = 3,
}

/** How many bytes one column takes. Derived, so a fifth band cannot leave the stride behind. */
export const STRIDE = Object.values(WaveformBand).filter((v) => typeof v === 'number').length;
