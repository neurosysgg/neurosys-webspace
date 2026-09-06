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
     * @throws MidiException if the division is not something the header can state.
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
            $microseconds = (int) round(self::MICROSECONDS_PER_MINUTE / $this->tempo);

            $body .= VariableLength::encode(0) . MidiTrack::meta(self::TEMPO, substr(pack('N', $microseconds), 1));
        }

        $body .= VariableLength::encode(0) . MidiTrack::meta(0x2F, '');

        return 'MTrk' . pack('N', strlen($body)) . $body;
    }
}
