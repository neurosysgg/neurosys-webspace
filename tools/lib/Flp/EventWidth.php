<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Flp;

/**
 * The EventWidth enum. How many bytes of value an event id carries.
 *
 * The `.flp` data chunk is a run of type-length-value records with no separators, so this is the
 * only thing that says where one event ends and the next begins. Get it wrong for a single id and
 * every event after it is read at the wrong offset.
 *
 * The rule is four bands of 64 ids, which is why the ids look arbitrary until you write them in
 * hex: an id's *number* is its type.
 */
enum EventWidth
{
    case Byte;
    case Word;
    case Dword;
    case Variable;

    /**
     * FL 26's preamble flag — **one byte wide despite sitting in the DWORD band**.
     *
     * This is the one place the band rule does not hold, and it is worth the paragraph because of
     * how it fails rather than how often. Read as a DWORD it consumes four bytes instead of one,
     * swallowing the id byte of the event after it. That event is the `FL Studio 26.1.0.5530`
     * string, and a UTF-16 run of ASCII text is `char, 00, char, 00, …` — so a parser one byte out
     * lands on the zero high bytes and reads each character as its own well-formed event, pair by
     * pair, until the string ends and the alignment happens to come back.
     *
     * The consequence is that the file still parses, still ends **exactly** on the length `FLdt`
     * declares, and is simply missing whatever sat in the desynchronised stretch — which is where
     * the tempo lives. A silently tempo-less project, from a file that looks perfectly well-formed.
     *
     * So there is no structural check that would have caught this, and it is worth knowing that
     * before trusting the next FL Studio release: the whole failure fits inside a well-formed file.
     * What catches it is the canary {@link FlpFile} names — a project with no tempo is a bad read
     * rather than a project without a tempo — and, before that, running the tool over real
     * projects, which is how this one was found.
     */
    private const int NARROW_DWORD = 172;

    /**
     * The width an event id carries.
     *
     * @param int $id
     * @return self
     */
    public static function of(int $id): self
    {
        return match (true) {
            $id === self::NARROW_DWORD => self::Byte,
            $id < 64                   => self::Byte,
            $id < 128                  => self::Word,
            $id < 192                  => self::Dword,
            default                    => self::Variable,
        };
    }

    /**
     * How many bytes of value to read, or null when the event carries its own length.
     *
     * @return int|null
     */
    public function size(): ?int
    {
        return match ($this) {
            self::Byte     => 1,
            self::Word     => 2,
            self::Dword    => 4,
            self::Variable => null,
        };
    }
}
