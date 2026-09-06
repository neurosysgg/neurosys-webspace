<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Flp;

use NeuroSYS\Support\Collection;

/**
 * The Pattern class. One of a project's patterns, and the notes written into it.
 *
 * A pattern's notes arrive as {@link EventId::PatternNotes} events that carry no index of their
 * own — the index comes from the {@link EventId::PatternIndex} event that opened the block, which
 * is why {@link Score} assembles these with a cursor rather than a filter. Its name arrives the
 * same way, from a third event.
 *
 * The name is `?string` because FL writes one only where the author typed one. That is not the
 * usual case: all 47 patterns of the project this reader was built against are named, which is
 * what makes {@link \NeuroSYS\Tool\Command\ExtractMidi}'s `--patterns` mode worth having — a MIDI
 * file of 47 tracks called *Pattern 1* through *Pattern 47* would be a worse thing to hand a
 * remixer than one that says `SQUARE ARP` and `HORNS & STRINGS SOLO`.
 */
final readonly class Pattern
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param int               $index FL's own pattern number, which is 1-based and not dense —
     *                                 deleting a pattern leaves a hole rather than renumbering.
     * @param string|null       $name  Null where the author never named it.
     * @param Collection<Note>  $notes In the order the file holds them, which is not sorted.
     */
    public function __construct(
        public int $index,
        public ?string $name,
        public Collection $notes,
    ) {}

    /**
     * The name to show, falling back to the index where there is none.
     *
     * @return string
     */
    public function label(): string
    {
        return $this->name ?? sprintf('Pattern %d', $this->index);
    }
}
