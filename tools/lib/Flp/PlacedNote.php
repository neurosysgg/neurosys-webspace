<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Flp;

/**
 * The PlacedNote class. A note at a tick on the song's timeline, with a length it can be played at.
 *
 * A {@link Note} is written once and may be placed many times — its `position` is relative to the
 * pattern holding it, which is not a time. This is what a pattern's note becomes once the playlist
 * has said where it plays.
 *
 * **Only two things about a note change when it is placed**, which is why only two are fields here
 * and everything else is read through {@link self::$note}: where it starts, and — for a one-shot —
 * how long it lasts. Key, velocity, channel, pan and the rest are the file's and are not the
 * arrangement's to reinterpret, so they stay where they were read.
 */
final readonly class PlacedNote
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param int  $tick   Ticks from the start of the song, at the project's ppq.
     * @param int  $length Ticks, and never zero — see {@link Score::sounded()} for what a one-shot
     *                     turns into and why leaving it at zero is not an option.
     * @param Note $note   The note as the file holds it.
     */
    public function __construct(public int $tick, public int $length, public Note $note) {}

    /**
     * A copy at a stated length, for the pass that gives one-shots a duration.
     *
     * @param int $length
     * @return self
     */
    public function lasting(int $length): self
    {
        return new self($this->tick, $length, $this->note);
    }
}
