<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Command;

use NeuroSYS\Tool\Cli\Option;

/**
 * The ExtractMidiOption enum. The flags `tools/extract-midi.php` accepts.
 *
 * Two of them, and they answer different questions: {@link self::Patterns} is *what* to write, and
 * {@link self::Out} is *where*. Nothing here selects a subset of instruments, deliberately — an
 * editor can delete a track in a click, and a flag that quietly omitted one would leave a file
 * nothing says is incomplete.
 *
 * @see ExtractMidi
 */
enum ExtractMidiOption: string implements Option
{
    /**
     * Write each pattern as its own track instead of the arrangement.
     *
     * The writing rather than the performance: every pattern once, at the ticks the pattern itself
     * holds, whether or not the playlist ever places it. That is the useful export for taking a
     * chord progression somewhere else, where the arrangement is the useful one for a remix.
     */
    case Patterns = 'patterns';

    /** Where to write the file. Defaults to the project's own name beside where it was run. */
    case Out = 'out';

    /**
     * @return string
     */
    public function flag(): string
    {
        return $this->value;
    }

    /**
     * @return bool
     */
    public function takesValue(): bool
    {
        return $this === self::Out;
    }
}
