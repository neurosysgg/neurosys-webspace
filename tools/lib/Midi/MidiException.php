<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Midi;

use RuntimeException;

/**
 * Thrown when a MIDI file cannot be written as asked.
 *
 * Every case is a value the format has no room for — a delta past four groups of seven bits, a key
 * above 127, a time signature whose denominator is not a power of two. None is recoverable and all
 * are programming errors rather than bad input, which is why they throw where
 * {@link \NeuroSYS\Tool\Flp\Playlist::of()} answers null: a project that says nothing is ordinary,
 * and a note that cannot be written is not.
 */
final class MidiException extends RuntimeException
{
}
