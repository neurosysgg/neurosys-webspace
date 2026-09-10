<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Flp;

use NeuroSYS\Support\File;

/**
 * The FlpFile class. A parsed `.flp` — its header, and every event in its data chunk.
 *
 * The format is two chunks borrowed from MIDI's shape: `FLhd`, six bytes of header, then `FLdt`,
 * whose declared length covers a run of type-length-value events with nothing between them. This
 * reads both and stops there. Nothing here knows what a tempo or a marker is — {@link Project} is
 * where events become facts, so that the byte-level rules and the musical ones fail separately.
 *
 * **A `.flp` gives a parser almost no way to check its footing**, and it is worth being exact about
 * how little. There are no separators between events and no checksums, so a reader that sizes one
 * id wrong reads every later event at the wrong offset. The bounds check in {@link self::walk()}
 * catches that when it runs off the end — truncation, and gross mis-sizing, both fail loudly there.
 *
 * What it does **not** catch is the case {@link EventWidth} describes. A one-byte error inside a
 * run of UTF-16 text re-synchronises on its own, because ASCII in UTF-16 is `char, 00, char, 00, …`
 * and a parser one byte out lands on the zero high bytes and reads pairs until the string ends. The
 * walk then finishes exactly on `FLdt`'s declared length with nothing structurally wrong — and is
 * simply missing whatever sat in the desynchronised stretch. An earlier draft of this class
 * asserted the walk landed on that boundary and claimed it as the guard; it is not one, because the
 * bug it was written for lands there too.
 *
 * The honest guard is a canary rather than a checksum: **every FL Studio project has a tempo**, in
 * all seven tested across four versions, and the desynchronised stretch is where it lives. So
 * `Project::$tempo` coming back null is treated by `Preflight` as a failed read rather than as a
 * project without a tempo — see the check there, which names this as the likely cause.
 */
final readonly class FlpFile
{
    /** The header chunk's magic, and the six bytes it has always declared. */
    private const string HEADER_MAGIC = 'FLhd';
    private const int HEADER_SIZE = 6;

    /** The data chunk's magic. */
    private const string DATA_MAGIC = 'FLdt';

    /**
     * How far a variable event's length prefix may shift before it stops being one.
     *
     * Five groups of seven bits, because `FLdt` states its own length as a dword and nothing inside
     * it can be longer than that. See {@link self::varInt()} for what a sixth group actually means.
     */
    private const int VARINT_BITS = 35;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param int          $format       FL's internal file format number; 0 for a project.
     * @param int          $channelCount Channels in the rack, from the header rather than counted.
     * @param int          $ppq          Pulses per quarter note — the unit every tick is in.
     * @param list<Event>  $events       Every event in the data chunk, in file order.
     */
    private function __construct(
        public int $format,
        public int $channelCount,
        public int $ppq,
        public array $events,
    ) {}

    /**
     * Reads a `.flp` from disk.
     *
     * @param File $file
     * @return self
     * @throws FlpException if the file cannot be read or does not parse.
     */
    public static function open(File $file): self
    {
        $bytes = $file->read();

        if ($bytes === null) {
            throw new FlpException(sprintf('cannot read %s', $file->path));
        }

        return self::read($bytes);
    }

    /**
     * Reads a `.flp` from bytes already in hand.
     *
     * Separate from {@link self::open()} so the parser can be tested against a project assembled
     * byte by byte, rather than against a committed multi-megabyte fixture.
     *
     * @param string $bytes
     * @return self
     * @throws FlpException if the bytes do not parse.
     */
    public static function read(string $bytes): self
    {
        if (strlen($bytes) < 8 || substr($bytes, 0, 4) !== self::HEADER_MAGIC) {
            throw new FlpException('not a .flp: no FLhd header');
        }

        $headerSize = unpack('V', substr($bytes, 4, 4))[1];

        if ($headerSize !== self::HEADER_SIZE || strlen($bytes) < 8 + $headerSize + 8) {
            throw new FlpException(sprintf('FLhd declares %d bytes, which no FL Studio writes', $headerSize));
        }

        $header = unpack('vformat/vchannels/vppq', substr($bytes, 8, self::HEADER_SIZE));
        $cursor = 8 + $headerSize;

        if (substr($bytes, $cursor, 4) !== self::DATA_MAGIC) {
            throw new FlpException('not a .flp: no FLdt chunk after the header');
        }

        $length = unpack('V', substr($bytes, $cursor + 4, 4))[1];
        $start  = $cursor + 8;

        if ($start + $length > strlen($bytes)) {
            throw new FlpException(sprintf(
                'FLdt declares %d bytes but only %d follow — the file is truncated',
                $length,
                strlen($bytes) - $start,
            ));
        }

        if ($header['ppq'] < 1) {
            throw new FlpException('FLhd declares a ppq of zero, so no tick can be placed in time');
        }

        return new self(
            format:       $header['format'],
            channelCount: $header['channels'],
            ppq:          $header['ppq'],
            events:       self::walk($bytes, $start, $length),
        );
    }

    /**
     * Walks the data chunk into events.
     *
     * @param string $bytes
     * @param int    $start
     * @param int    $length
     * @return list<Event>
     * @throws FlpException if the walk does not land exactly on the declared end.
     */
    private static function walk(string $bytes, int $start, int $length): array
    {
        $end    = $start + $length;
        $cursor = $start;
        $events = [];

        while ($cursor < $end) {
            $id    = ord($bytes[$cursor++]);
            $width = EventWidth::of($id);
            $size  = $width->size();

            if ($size === null) {
                [$size, $cursor] = self::varInt($bytes, $cursor, $end, $id);
            }

            if ($cursor + $size > $end) {
                throw new FlpException(sprintf(
                    'event %d at offset %d wants %d bytes with %d left in FLdt',
                    $id,
                    $cursor - 1,
                    $size,
                    $end - $cursor,
                ));
            }

            $value = match ($width) {
                EventWidth::Byte  => ord($bytes[$cursor]),
                EventWidth::Word  => unpack('v', substr($bytes, $cursor, $size))[1],
                EventWidth::Dword => unpack('V', substr($bytes, $cursor, $size))[1],
                default           => substr($bytes, $cursor, $size),
            };

            $cursor  += $size;
            $events[] = new Event($id, $value);
        }

        // No landing assertion here, deliberately: the bounds check above already guarantees the
        // walk stops exactly on $end, so one would be unreachable — and the desync it would have
        // been written for lands there anyway. See the class docblock for what guards that instead.
        return $events;
    }

    /**
     * Reads the 7-bits-at-a-time length that prefixes every variable-width event.
     *
     * **Five groups and no more, which is a guard rather than a limit.** `FLdt` declares its own
     * length as a dword, so no event inside it can be longer than 32 bits can say, and five groups
     * of seven cover that with room to spare. A sixth continuation byte is not a long event, it is
     * a run of bytes that is not a length at all — which is what a desynchronised walk finds when
     * it lands inside a plugin blob, where ten consecutive bytes with the high bit set are
     * unremarkable.
     *
     * Without the bound it is worse than an unread event. The shift reaches 63, `0x7F << 63`
     * overflows to `PHP_INT_MIN`, and the length comes back **negative** — which sails through
     * {@link self::walk()}'s `$cursor + $size > $end` check, because a negative size is always
     * within bounds. `substr()` then reads backwards from the cursor and the cursor itself moves
     * back, so the walk manufactures an event out of nothing and desynchronises further, silently.
     * That is the one failure mode this class exists to make loud, arriving quietly.
     *
     * @param string $bytes
     * @param int    $cursor
     * @param int    $end
     * @param int    $id
     * @return array{int, int} The length, and the cursor after it.
     * @throws FlpException if the length runs off the end of the chunk, or is not one.
     */
    private static function varInt(string $bytes, int $cursor, int $end, int $id): array
    {
        $value = 0;
        $shift = 0;

        do {
            if ($cursor >= $end) {
                throw new FlpException(sprintf('event %d has a length that runs past FLdt', $id));
            }

            if ($shift >= self::VARINT_BITS) {
                throw new FlpException(sprintf(
                    'event %d at offset %d has a length of more than %d bytes, which no event in a '
                    . 'chunk this size can have — the walk is reading something that is not a length',
                    $id,
                    $cursor,
                    intdiv(self::VARINT_BITS, 7),
                ));
            }

            $byte   = ord($bytes[$cursor++]);
            $value |= ($byte & 0x7F) << $shift;
            $shift += 7;
        } while (($byte & 0x80) !== 0);

        return [$value, $cursor];
    }

    /**
     * Every event with the given id, in file order.
     *
     * @param EventId $id
     * @return list<Event>
     */
    public function all(EventId $id): array
    {
        return array_values(array_filter($this->events, static fn(Event $e): bool => $e->is($id)));
    }

    /**
     * The first event with the given id, or null where the project carries none.
     *
     * @param EventId $id
     * @return Event|null
     */
    public function first(EventId $id): ?Event
    {
        foreach ($this->events as $event) {
            if ($event->is($id)) {
                return $event;
            }
        }

        return null;
    }
}
