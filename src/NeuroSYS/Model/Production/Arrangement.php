<?php

declare(strict_types=1);

namespace NeuroSYS\Model\Production;

use NeuroSYS\Exception\ReleaseVerificationException;
use NeuroSYS\Support\Collection;

/**
 * The Arrangement class. How a release is laid out in time.
 *
 * `INTRO → BUILDUP → DROP → SWITCH 1 → SWITCH 2 → BRIDGE → DROP → SWITCH → BUILDDOWN → OUTRO` is
 * `ill.`, read from the markers in its own project file. It is the one fact on a release that
 * nothing outside the `.flp` knows: the masters do not carry it, the filenames do not imply it, and
 * a person would have to type it out by hand.
 *
 * Holds the **pulse rate** alongside the sections because a tick means nothing without it, and it
 * is a property of the project rather than of any one section. 96 is what every project tested
 * uses, but reading it is free and assuming it would be the kind of constant that is right until it
 * is silently wrong.
 */
final readonly class Arrangement
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Collection<Section> $sections In playing order.
     * @param int                 $ppq      Pulses per quarter note, from the project header.
     *
     * @throws ReleaseVerificationException if constructed with invalid data.
     */
    public function __construct(
        public Collection $sections,
        public int $ppq = 96,
    ) {
        // Collection::with() rejects the wrong item; only its element type is left to check, which
        // is the one thing a PHP generic cannot say. Same guard as Release::verify(), which is
        // also where the reason it asks is_a() rather than !== is written down.
        if (!is_a($this->sections->type, Section::class, true)) {
            throw new ReleaseVerificationException(
                'Arrangement::sections must be a Collection of \Section.'
            );
        }

        if ($this->ppq < 1) {
            throw new ReleaseVerificationException(
                'Arrangement::ppq must be greater than 0, or no tick can be placed in time.'
            );
        }
    }

    /**
     * Whether there is anything to draw.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->sections->isEmpty();
    }

    /**
     * How far into the track the last section starts, in seconds.
     *
     * Deliberately **not** the track's duration: a `.flp` has no end, only a last marker, and the
     * outro runs on past it. `ill.`'s last marker is at 164s of a 187s master. Naming this
     * `duration()` would have been a small lie that every caller then repeated.
     *
     * @param int $bpm
     * @return float
     */
    public function lastStart(int $bpm): float
    {
        return $this->sections->last()?->seconds($bpm, $this->ppq) ?? 0.0;
    }

    /**
     * Each section with the fraction of the arrangement it begins at, for drawing a timeline.
     *
     * Lazy, like every `map()`: a {@link SectionPosition} out of range is reported when the
     * collection is materialised rather than here. Sections arrive in playing order from the
     * project file, so that guard is for a hand-written entry.
     *
     * @param int $bpm
     * @return Collection<SectionPosition>
     * @throws ReleaseVerificationException on materialisation, if a section
     *                        starts after the last one does.
     */
    public function positions(int $bpm): Collection
    {
        $span = $this->lastStart($bpm);

        return $this->sections->map(fn(Section $section): SectionPosition => new SectionPosition(
            $section,
            // A single-section arrangement has no span to divide by and sits at the start.
            $span > 0.0 ? $section->seconds($bpm, $this->ppq) / $span : 0.0,
        ));
    }
}
