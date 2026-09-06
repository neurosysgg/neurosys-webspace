<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Midi;

use NeuroSYS\Support\Collection;

/**
 * The MidiTrack class. One `MTrk` chunk — a name, a channel, and the notes on it.
 *
 * A note is held as a note rather than as the two events it becomes, because the pair has to be
 * split apart and re-sorted anyway: a note's off lands at a tick its on knows nothing about, and
 * both go into one stream ordered by time. Storing them already split would mean a caller could
 * hand over an off without an on, which is a file no player agrees on.
 *
 * **Order at a shared tick is a decision, not an accident.** Where a note ends exactly as another
 * begins, the off is written first — otherwise a player that reads the on first sees the same key
 * sounding twice and the off it then meets ends the wrong one, which is how a re-triggered hi-hat
 * turns into one long note. That is what {@link self::OFF} and {@link self::ON} order, and PHP's
 * sort being stable is what keeps notes at the same tick in the order they were given.
 */
final readonly class MidiTrack
{
    /** Meta event types: a track's name, and the end of it. */
    private const int NAME = 0x03;
    private const int END_OF_TRACK = 0x2F;

    /** Status nibbles. */
    private const int NOTE_OFF = 0x80;
    private const int NOTE_ON = 0x90;

    /** What is written first when two events share a tick. Lower goes earlier. */
    private const int RENAME = 0;
    private const int OFF = 1;
    private const int ON = 2;

    /** MIDI has sixteen channels, and a rack can have a hundred — see {@link self::$channel}. */
    public const int CHANNELS = 16;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param string                $name    Shown by every editor as the track's name; may be
     *                                       empty, in which case none is written.
     * @param int                   $channel One of MIDI's sixteen. In a format-1 file each track
     *                                       is its own stream, so this matters far less than the
     *                                       track it sits on — which is why the caller is free to
     *                                       derive it from a rack position running past fifteen.
     * @param Collection<MidiNote>  $notes   In any order; this sorts them.
     * @throws MidiException if the channel is not one of the sixteen.
     */
    public function __construct(
        public string $name,
        public int $channel,
        public Collection $notes,
    ) {
        if ($channel < 0 || $channel >= self::CHANNELS) {
            throw new MidiException(sprintf('channel %d is outside MIDI\'s 0-15', $channel));
        }
    }

    /**
     * The whole chunk, header included.
     *
     * @return string
     */
    public function render(): string
    {
        $events = [];

        if ($this->name !== '') {
            $events[] = [0, self::RENAME, self::meta(self::NAME, $this->name)];
        }

        foreach ($this->notes as $note) {
            $events[] = [
                $note->tick,
                self::ON,
                chr(self::NOTE_ON | $this->channel) . chr($note->key) . chr($note->velocity),
            ];
            $events[] = [
                $note->end(),
                self::OFF,
                chr(self::NOTE_OFF | $this->channel) . chr($note->key) . chr(0),
            ];
        }

        usort($events, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $body = '';
        $last = 0;

        foreach ($events as [$tick, , $bytes]) {
            $body .= VariableLength::encode($tick - $last) . $bytes;
            $last  = $tick;
        }

        $body .= VariableLength::encode(0) . self::meta(self::END_OF_TRACK, '');

        return 'MTrk' . pack('N', strlen($body)) . $body;
    }

    /**
     * One meta event: the marker byte, the type, and a variable-length payload.
     *
     * @param int    $type
     * @param string $payload
     * @return string
     */
    public static function meta(int $type, string $payload): string
    {
        return chr(0xFF) . chr($type) . VariableLength::encode(strlen($payload)) . $payload;
    }
}
