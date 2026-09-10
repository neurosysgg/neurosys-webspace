<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Flp;

/**
 * The Note class. One note from a pattern, as the 24 bytes {@link EventId::PatternNotes} holds it.
 *
 * **The layout was measured rather than looked up.** Every byte column was surveyed across 3,309
 * notes of a real project, and each field below is named for what that survey showed — a column
 * that never varies is a centre or a default, a column with 108 distinct values under a 76-channel
 * rack is an index, and two columns that are always zero are the high half of something narrower
 * than its slot:
 *
 * | offset | width | field                | what the survey showed                        |
 * |--------|-------|----------------------|-----------------------------------------------|
 * | 0      | u32   | {@link self::$position}    | bytes 2–3 always zero                   |
 * | 4      | u16   | flags                      | constant `0x4000`, so not read          |
 * | 6      | u16   | {@link self::$channel}     | 0–73 under a 76-channel rack            |
 * | 8      | u32   | {@link self::$length}      | bytes 10–11 always zero                 |
 * | 12     | u8    | {@link self::$key}         | 27–87; the one field already read       |
 * | 14     | u16   | {@link self::$group}       | 0 for an ungrouped note                 |
 * | 16     | u8    | {@link self::$finePitch}   | constant 120, which is centre           |
 * | 18     | u8    | {@link self::$release}     | constant 64                             |
 * | 19     | u8    | {@link self::$slide}       | only ever 0 or 128 — a flag, not a byte |
 * | 20     | u8    | {@link self::$pan}         | constant 64, which is centre            |
 * | 21     | u8    | {@link self::$velocity}    | constant 100, which is FL's default     |
 * | 22–23  | u8    | mod x, mod y               | constant 128, so not read               |
 *
 * The three columns that never varied across the corpus are deliberately **not** fields: a value
 * this reader has only ever seen one of is a value it has not actually learnt to read, and naming
 * it would claim otherwise. They are listed above so the next person knows the slots are accounted
 * for rather than skipped.
 *
 * {@link KeyEstimate} decodes two of these fields on its own — it weights pitch classes by duration
 * and needs nothing else. The rest of the struct is
 * what turns a project into music rather than into a histogram.
 */
final readonly class Note
{
    /** Bytes per note. The event's length is always a whole number of these. */
    public const int SIZE = 24;

    /**
     * The highest key FL will write, and the highest MIDI can hold.
     *
     * FL's piano roll runs C0–B10, which is 132 keys, so 128–131 are real pitches that a MIDI file
     * has no room for — {@link self::isPlayable()} is the question a writer asks, and it is a
     * different question from whether the byte is a pitch at all. Above `FL_MAX` it is not: FL
     * parks markers of its own up there, which is why {@link KeyEstimate} has skipped them since
     * before any of this was read.
     */
    private const int FL_MAX_KEY = 131;
    private const int MIDI_MAX_KEY = 127;

    /** The bit FL sets in the MIDI-channel byte to mark a note as a slide. */
    private const int SLIDE_BIT = 0x80;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param int  $position  Ticks from the start of the pattern, at the project's ppq.
     * @param int  $length    Ticks. **Zero is normal** and means a one-shot — see
     *                        {@link Score::sounded()} for what a length of nothing turns into.
     * @param int  $key       The MIDI key, 0–131; see {@link self::isPlayable()}.
     * @param int  $channel   The rack channel this note plays, which is what a MIDI track is.
     * @param int  $velocity  In FL's 0–128 scale, which is one wider than MIDI's.
     * @param int  $pan       In 0–128, where 64 is centre.
     * @param int  $release   In 0–128, where 64 is centre.
     * @param int  $finePitch In 0–240, where 120 is centre.
     * @param int  $group     The note group, 0 when ungrouped.
     * @param bool $slide     Whether this is a slide note.
     */
    private function __construct(
        public int $position,
        public int $length,
        public int $key,
        public int $channel,
        public int $velocity,
        public int $pan,
        public int $release,
        public int $finePitch,
        public int $group,
        public bool $slide,
    ) {}

    /**
     * Every note in one {@link EventId::PatternNotes} event.
     *
     * A block that is not a whole number of notes is refused outright rather than read to the last
     * whole one, on the same grounds {@link FlpFile::varInt()} refuses a sixth continuation byte:
     * a ragged block is not a short block, it is a sign the walk is reading something that is not
     * a note list, and reading 41 of its 41.5 notes would turn that into data.
     *
     * @param string $block
     * @return list<self>
     */
    public static function decode(string $block): array
    {
        if ($block === '' || strlen($block) % self::SIZE !== 0) {
            return [];
        }

        $notes = [];

        for ($offset = 0; $offset < strlen($block); $offset += self::SIZE) {
            $fields = unpack('Vposition/vflags/vchannel/Vlength', substr($block, $offset, 12));
            $key    = ord($block[$offset + 12]);

            // Not a pitch at all — see FL_MAX_KEY. KeyEstimate has skipped these all along.
            if ($key > self::FL_MAX_KEY) {
                continue;
            }

            $notes[] = new self(
                position:  $fields['position'],
                length:    $fields['length'],
                key:       $key,
                channel:   $fields['channel'],
                velocity:  ord($block[$offset + 21]),
                pan:       ord($block[$offset + 20]),
                release:   ord($block[$offset + 18]),
                finePitch: ord($block[$offset + 16]),
                group:     unpack('v', substr($block, $offset + 14, 2))[1],
                slide:     (ord($block[$offset + 19]) & self::SLIDE_BIT) !== 0,
            );
        }

        return $notes;
    }

    /**
     * Whether a MIDI file can hold this note's pitch.
     *
     * Separate from the check in {@link self::decode()} because the two rule out different things.
     * That one drops a byte that is not a pitch; this one reports a pitch that is real and simply
     * higher than MIDI counts — a fact about the format being written, not about the project.
     *
     * @return bool
     */
    public function isPlayable(): bool
    {
        return $this->key <= self::MIDI_MAX_KEY;
    }
}
