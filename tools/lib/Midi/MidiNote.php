<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Midi;

/**
 * The MidiNote class. One note as a MIDI file can hold it.
 *
 * Deliberately narrower than {@link \NeuroSYS\Tool\Flp\Note}, and the narrowing is the point: FL
 * counts velocity to 128 where MIDI stops at 127, writes keys up to 131 where MIDI stops at 127,
 * and lets a note last no time at all where MIDI has no such thing. Every one of those is a value
 * that has to be dealt with *somewhere*, and a constructor that throws is how this codebase says
 * where — the same arrangement as `HiDriveLink` refusing a share id that is not nine characters.
 *
 * What it does **not** do is clamp. Deciding that a velocity of 128 becomes 127, or that a key of
 * 130 is dropped rather than lowered an octave, is a decision about a particular project and
 * belongs to the caller making it — {@link \NeuroSYS\Tool\Command\ExtractMidi} makes it, and says
 * out loud in its report how many notes it affected. A class that quietly clamped would leave a
 * file that is subtly not the project with nothing anywhere saying so.
 */
final readonly class MidiNote
{
    /** The highest key and velocity a MIDI byte can carry, seven bits being what it has. */
    public const int MAX_KEY = 127;
    public const int MAX_VELOCITY = 127;

    /**
     * The quietest velocity that is still a note.
     *
     * Named rather than left as the `1` in the guard below, because it is a floor with a reason and
     * not an off-by-one: a note-on of velocity zero **is** a note-off in this format, so zero is not
     * a quiet note but an absent one. {@link \NeuroSYS\Tool\Command\ExtractMidi} raises FL's silent
     * notes to this and says how many, which is a different fact from lowering FL's 128 to 127 —
     * and the two used to be counted together and reported as one.
     */
    public const int MIN_VELOCITY = 1;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param int $tick     Ticks from the start of the track.
     * @param int $key      Within MIDI's 0–127.
     * @param int $velocity Within 1–127. Zero is excluded because a note-on of velocity zero *is*
     *                      a note-off in this format, so it would not be a quiet note but an
     *                      absent one.
     * @param int $length   Ticks, at least one.
     * @throws MidiException if any of those is outside what the format can hold.
     */
    public function __construct(
        public int $tick,
        public int $key,
        public int $velocity,
        public int $length,
    ) {
        if ($tick < 0) {
            throw new MidiException(sprintf('a note cannot start at tick %d', $tick));
        }

        if ($key < 0 || $key > self::MAX_KEY) {
            throw new MidiException(sprintf('key %d is outside MIDI\'s 0-%d', $key, self::MAX_KEY));
        }

        if ($velocity < self::MIN_VELOCITY || $velocity > self::MAX_VELOCITY) {
            throw new MidiException(sprintf(
                'velocity %d is outside %d-%d',
                $velocity,
                self::MIN_VELOCITY,
                self::MAX_VELOCITY,
            ));
        }

        if ($length < 1) {
            throw new MidiException(sprintf('a note of %d ticks would never sound', $length));
        }
    }

    /**
     * The tick this note stops sounding.
     *
     * @return int
     */
    public function end(): int
    {
        return $this->tick + $this->length;
    }
}
