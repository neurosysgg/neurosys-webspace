<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Midi;

use NeuroSYS\Support\Collection;
use NeuroSYS\Support\File;

/**
 * The MidiFile class. A standard MIDI file, header and all.
 *
 * **Format 1 with a conductor track**, which is what FL Studio's own export writes and what every
 * editor expects: the first chunk carries tempo and time signature and no notes, and each chunk
 * after it is one instrument. Format 0 would flatten every instrument into one stream, which is a
 * file a remixer has to take apart before they can use it.
 *
 * The division is the project's ppq **unchanged**. That is worth stating because it is the one
 * number that would be tempting to normalise: FL writes 96 and plenty of MIDI is 480, so a
 * conversion looks like tidying. It is not — every tick in every note would have to be rescaled,
 * and any that did not divide evenly would be quantised by the rounding. Ticks are carried across
 * exactly, and FL's own export does the same, which is why its note positions could be diffed
 * against ours as integers.
 */
final readonly class MidiFile
{
    /** Meta event types the conductor track carries. */
    private const int TEMPO = 0x51;
    private const int TIME_SIGNATURE = 0x58;

    /** Microseconds in a minute, which is what a tempo meta event counts in. */
    private const int MICROSECONDS_PER_MINUTE = 60_000_000;

    /** The division field is fifteen bits when it counts ticks per quarter note. */
    private const int MAX_DIVISION = 0x7FFF;

    /**
     * The largest value a tempo meta event can state, its payload being three bytes.
     *
     * Which puts the slowest music a MIDI file can describe at about 3.58 BPM. FL's own floor is
     * 10, so nothing this repository reads can reach it — but this constructor takes a float from
     * wherever, and the failure is the quiet kind the class beside it is full of guards against:
     * `pack('N')` gives four bytes, `substr(…, 1)` drops the high one to fit the payload, and 3 BPM
     * writes itself as ~18.6 with nothing anywhere reading as wrong. Every other value this package
     * cannot hold throws — a key, a velocity, a length, a division, a variable-length quantity — so
     * this one does too rather than being the one that truncates.
     */
    private const int MAX_MICROSECONDS_PER_QUARTER = 0xFFFFFF;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param int                   $division      Ticks per quarter note — the project's ppq.
     * @param Collection<MidiTrack> $tracks        One per instrument.
     * @param float|null            $tempo         BPM, or null to leave the file at MIDI's own
     *                                             default of 120. A project without a tempo is a
     *                                             bad read rather than a slow song — see
     *                                             {@link \NeuroSYS\Tool\Flp\FlpFile} — so the
     *                                             caller reports it rather than this inventing one.
     * @param TimeSignature|null    $timeSignature Null for MIDI's own default of 4/4.
     * @throws MidiException if the division or the tempo is not something the file can state.
     */
    public function __construct(
        public int $division,
        public Collection $tracks,
        public ?float $tempo = null,
        public ?TimeSignature $timeSignature = null,
    ) {
        if ($division < 1 || $division > self::MAX_DIVISION) {
            throw new MidiException(sprintf('a division of %d cannot be written to the header', $division));
        }

        // Checked here rather than where it is written, beside the division, because both are
        // facts about whether this file can exist at all — and a constructor is where this package
        // says so. A tempo of zero or less is not refused: that is `conductor()` leaving the event
        // out entirely, which is a different answer from stating one wrongly.
        if ($tempo !== null && $tempo > 0 && self::microseconds($tempo) > self::MAX_MICROSECONDS_PER_QUARTER) {
            throw new MidiException(sprintf(
                'a tempo of %g BPM needs %d microseconds per quarter note, past the %d that three '
                . 'bytes can state — about %.2f BPM is the slowest a MIDI file describes',
                $tempo,
                self::microseconds($tempo),
                self::MAX_MICROSECONDS_PER_QUARTER,
                self::MICROSECONDS_PER_MINUTE / self::MAX_MICROSECONDS_PER_QUARTER,
            ));
        }
    }

    /**
     * A tempo in BPM as the microseconds per quarter note a tempo meta event counts in.
     *
     * @param float $tempo Above zero, which the two callers each establish for themselves.
     * @return int
     */
    private static function microseconds(float $tempo): int
    {
        return (int) round(self::MICROSECONDS_PER_MINUTE / $tempo);
    }

    /**
     * The whole file.
     *
     * @return string
     */
    public function render(): string
    {
        $chunks = $this->conductor();

        foreach ($this->tracks as $track) {
            $chunks .= $track->render();
        }

        // Format 1, and one more chunk than there are tracks, the conductor being the extra.
        $header = pack('nnn', 1, count($this->tracks) + 1, $this->division);

        return 'MThd' . pack('N', strlen($header)) . $header . $chunks;
    }

    /**
     * Writes the file to disk.
     *
     * @param File $file
     * @return bool False where the write failed — which for a path whose directory does not exist
     *              is deliberate and is {@link File}'s decision, not this one's.
     */
    public function write(File $file): bool
    {
        return $file->write($this->render());
    }

    /**
     * The first chunk: what is true of the whole song rather than of any one instrument.
     *
     * @return string
     */
    private function conductor(): string
    {
        $signature = $this->timeSignature ?? TimeSignature::common();
        $body      = VariableLength::encode(0)
            . MidiTrack::meta(self::TIME_SIGNATURE, $signature->render());

        if ($this->tempo !== null && $this->tempo > 0) {
            // Three bytes, which the constructor has already established this value fits in.
            $microseconds = self::microseconds($this->tempo);

            $body .= VariableLength::encode(0) . MidiTrack::meta(self::TEMPO, substr(pack('N', $microseconds), 1));
        }

        $body .= MidiTrack::terminator();

        return 'MTrk' . pack('N', strlen($body)) . $body;
    }
}
