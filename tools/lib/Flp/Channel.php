<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Flp;

/**
 * The Channel class. One channel of the rack, and the name it goes by.
 *
 * A note names the channel it plays and nothing else, so this is what turns
 * `channel 13` into `[Serum 2] SYN phat saw stack` — which is the whole difference between a MIDI
 * file a person can open and one they have to guess their way around. FL's own export names its
 * tracks from exactly this, and matching it was one of the things checked note for note.
 *
 * The name comes from {@link EventId::ChannelName}, which FL writes for effects too. That is
 * harmless here because this is only ever built under a {@link EventId::ChannelIndex} cursor, so
 * an insert's plugin name is never mistaken for a rack channel's.
 */
final readonly class Channel
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param int    $index The rack position a note's `channel` field refers to.
     * @param string $name  FL's display name for it.
     */
    public function __construct(public int $index, public string $name) {}

    /**
     * The name to show, falling back to the index where the project names nothing.
     *
     * @return string
     */
    public function label(): string
    {
        return $this->name !== '' ? $this->name : sprintf('Channel %d', $this->index);
    }
}
