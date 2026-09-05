<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Flp;

/**
 * The Event class. One record from a project's data chunk.
 *
 * The id stays an `int` rather than becoming an {@link EventId}, because most of the few thousand
 * events in a project have no case and never will. Asking `is()` keeps the comparison typed at the
 * call sites that care, while the ones that do not are free to walk past — the same arrangement as
 * `HttpMethod::tryFrom()` handing back null rather than inventing a case for a verb it does not
 * serve.
 */
final readonly class Event
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param int        $id    The raw event id.
     * @param int|string $value An int for the three fixed-width bands, raw bytes for the variable
     *                          one — undecoded, because only the reader of a given id knows whether
     *                          it holds UTF-16 text, ASCII, or a struct.
     */
    public function __construct(public int $id, public int|string $value) {}

    /**
     * Whether this is the event named.
     *
     * @param EventId $id
     * @return bool
     */
    public function is(EventId $id): bool
    {
        return $this->id === $id->value;
    }

    /**
     * The value as an int, for an event from one of the fixed-width bands.
     *
     * @return int
     * @throws FlpException if this event carries bytes rather than a number.
     */
    public function number(): int
    {
        if (!is_int($this->value)) {
            throw new FlpException(sprintf('event %d carries bytes, not a number', $this->id));
        }

        return $this->value;
    }

    /**
     * The value as text.
     *
     * FL writes UTF-16LE for everything except {@link EventId::Version}, which stayed ASCII. Both
     * arrive NUL-terminated. The encoding is decided per id by the caller rather than sniffed,
     * because a sniff is wrong exactly when a string is short enough not to matter and long enough
     * to be worth reading.
     *
     * @param bool $ascii
     * @return string
     * @throws FlpException if this event carries a number rather than bytes.
     */
    public function text(bool $ascii = false): string
    {
        if (!is_string($this->value)) {
            throw new FlpException(sprintf('event %d carries a number, not bytes', $this->id));
        }

        $decoded = $ascii ? $this->value : (mb_convert_encoding($this->value, 'UTF-8', 'UTF-16LE') ?: '');

        return rtrim($decoded, "\0");
    }
}
