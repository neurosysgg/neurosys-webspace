<?php

declare(strict_types=1);

namespace NeuroSYS\Model\Production;

use NeuroSYS\Exception\ReleaseVerificationException;

/**
 * The Section class. One named part of a release's arrangement.
 *
 * A marker from the project's playlist: what it is called, and where it starts. The position is a
 * **tick** rather than a time, because that is what the project stores and what stays true — the
 * seconds are derived from the tempo, and deriving them here would bake one release's tempo into a
 * value object that has no business knowing it.
 */
final readonly class Section
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string          $label The marker's own text, which is what renders.
     * @param int             $tick  Where it starts, in pulses; see {@link Arrangement::$ppq}.
     * @param SectionKind|null $kind Which accent the stylesheet gives it, or null for none.
     *
     * @throws ReleaseVerificationException if the tick is negative.
     */
    public function __construct(
        public string $label,
        public int $tick,
        public ?SectionKind $kind = null,
    ) {
        if ($this->tick < 0) {
            throw new ReleaseVerificationException('Section::tick cannot be negative.');
        }
    }

    /**
     * A section from a marker's label, with its kind classified.
     *
     * @param string $label
     * @param int    $tick
     * @return self
     */
    public static function named(string $label, int $tick): self
    {
        return new self($label, $tick, SectionKind::classify($label));
    }

    /**
     * Where this section starts, in seconds.
     *
     * @param int $bpm
     * @param int $ppq
     * @return float
     */
    public function seconds(int $bpm, int $ppq): float
    {
        return $bpm > 0 && $ppq > 0 ? ($this->tick / $ppq) * (60 / $bpm) : 0.0;
    }

    /**
     * Where this section starts, as `1:23`.
     *
     * @param int $bpm
     * @param int $ppq
     * @return string
     */
    public function timestamp(int $bpm, int $ppq): string
    {
        $seconds = (int) floor($this->seconds($bpm, $ppq));

        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
