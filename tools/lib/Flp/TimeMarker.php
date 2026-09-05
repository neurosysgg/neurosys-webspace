<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Flp;

/**
 * The TimeMarker class. One marker, with its type resolved and its position unpacked.
 *
 * Replaces the three-events-in-a-row this is assembled from — a position, a name, and a root note
 * that only means anything on a scale marker.
 */
final readonly class TimeMarker
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param MarkerType $type
     * @param int        $tick In ppq units; divide by the project's ppq for beats.
     * @param string     $name As FL displays it.
     * @param int        $root A pitch class, `0` = C. Only meaningful on {@link MarkerType::Scale}.
     */
    public function __construct(
        public MarkerType $type,
        public int $tick,
        public string $name,
        public int $root = 0,
    ) {}

    /**
     * Where this marker falls, in seconds.
     *
     * @param float $tempo The project's tempo, in BPM.
     * @param int   $ppq   The project's pulses per quarter note.
     * @return float
     */
    public function seconds(float $tempo, int $ppq): float
    {
        return $tempo > 0.0 && $ppq > 0 ? ($this->tick / $ppq) * (60.0 / $tempo) : 0.0;
    }
}
