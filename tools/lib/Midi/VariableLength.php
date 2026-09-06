<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Midi;

/**
 * The VariableLength class. MIDI's seven-bits-at-a-time integer, on the way out.
 *
 * The mirror of {@link \NeuroSYS\Tool\Flp\FlpFile::varInt()}, which reads the same encoding on the
 * way in — the two formats share it because a `.flp` borrowed its chunk shape from MIDI in the
 * first place. Worth reading that method's docblock before touching this one: it is where the
 * damage a mis-encoded length does is written down.
 *
 * **The bound is the same bound, arrived at from the other end.** A reader refuses a sixth
 * continuation byte because a length that long is not a length; a writer refuses a value above
 * {@link self::MAXIMUM} because the format cannot say it. Silently truncating to four bytes would
 * produce a file that parses and plays the wrong thing — every event after the truncated delta
 * lands at the wrong time — so it throws instead.
 */
final readonly class VariableLength
{
    /** The largest delta a standard MIDI file can state: four groups of seven bits. */
    public const int MAXIMUM = 0x0FFFFFFF;

    /**
     * Encodes one value.
     *
     * @param int $value
     * @return string
     * @throws MidiException if the value is negative or too large for the encoding.
     */
    public static function encode(int $value): string
    {
        if ($value < 0) {
            throw new MidiException(sprintf('a delta of %d is not a duration', $value));
        }

        if ($value > self::MAXIMUM) {
            throw new MidiException(sprintf(
                'a delta of %d exceeds the %d a variable-length quantity can state',
                $value,
                self::MAXIMUM,
            ));
        }

        $bytes = [$value & 0x7F];

        while (($value >>= 7) > 0) {
            // Every group but the last carries the continuation bit, and they are written
            // most-significant first — which is why they are collected backwards and reversed.
            $bytes[] = ($value & 0x7F) | 0x80;
        }

        return implode('', array_map(chr(...), array_reverse($bytes)));
    }
}
