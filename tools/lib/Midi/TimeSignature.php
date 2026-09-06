<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Midi;

/**
 * The TimeSignature class. A bar, as MIDI's meta event states one.
 *
 * MIDI does not store a denominator; it stores the power of two the denominator is. So a `4/4`
 * written by hand at a call site is two numbers, one of which has to be converted and the other of
 * which does not — exactly the sort of thing that reads as correct and is silently a bar of the
 * wrong length. A class that takes `4` and `4` and does the conversion once is the whole reason
 * this is not two `int` parameters on {@link MidiFile}.
 *
 * {@link self::parse()} reads FL's spelling, which is the plain `4/4` a time-signature marker
 * carries. It answers **null** rather than a default for anything else, because a marker that is
 * present and unreadable is a different thing from no marker at all, and only the caller knows
 * whether that is worth mentioning — see {@link \NeuroSYS\Tool\Command\ExtractMidi}, which says so.
 */
final readonly class TimeSignature
{
    /** MIDI clocks per metronome click, and 32nd notes per quarter. Both are the conventional. */
    private const int CLOCKS_PER_CLICK = 24;
    private const int THIRTY_SECONDS_PER_QUARTER = 8;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param int $beats    The numerator — beats in a bar.
     * @param int $division The denominator — the note value a beat is. A power of two.
     * @throws MidiException if the denominator is not a power of two, which MIDI cannot state.
     */
    public function __construct(public int $beats, public int $division)
    {
        if ($beats < 1 || $beats > 255) {
            throw new MidiException(sprintf('a bar of %d beats is not one', $beats));
        }

        if ($division < 1 || $division > 256 || ($division & ($division - 1)) !== 0) {
            throw new MidiException(sprintf('%d is not a power of two, so MIDI cannot state it', $division));
        }
    }

    /**
     * Four in a bar — MIDI's own assumption, and the right fallback for a project that sets none.
     *
     * @return self
     */
    public static function common(): self
    {
        return new self(4, 4);
    }

    /**
     * Reads FL's spelling of a time signature.
     *
     * @param string $text
     * @return self|null null where it is not `n/d` with a denominator MIDI can hold.
     */
    public static function parse(string $text): ?self
    {
        if (preg_match('/^\s*(\d{1,3})\s*\/\s*(\d{1,3})\s*$/', $text, $matches) !== 1) {
            return null;
        }

        try {
            return new self((int) $matches[1], (int) $matches[2]);
        } catch (MidiException) {
            return null;
        }
    }

    /**
     * The four bytes of the meta event's payload.
     *
     * @return string
     */
    public function render(): string
    {
        return chr($this->beats)
            . chr((int) log($this->division, 2))
            . chr(self::CLOCKS_PER_CLICK)
            . chr(self::THIRTY_SECONDS_PER_QUARTER);
    }

    /**
     * How it reads in a report.
     *
     * @return string
     */
    public function label(): string
    {
        return sprintf('%d/%d', $this->beats, $this->division);
    }
}
