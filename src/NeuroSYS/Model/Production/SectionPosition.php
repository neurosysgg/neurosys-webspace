<?php

declare(strict_types=1);

namespace NeuroSYS\Model\Production;

use NeuroSYS\Exception\ReleaseVerificationException;

/**
 * The SectionPosition class. One {@link Section} and where along a timeline it is drawn.
 *
 * A pair, and it is a class rather than an `array{section: Section, offset: float}` for the reason
 * {@link \NeuroSYS\Support\Collection} exists at all: a two-slot array is a shape a docblock
 * promises and nothing checks, read back at the far end as `$p['offset']` — where a typo is a
 * warning and a null rather than an error naming the key. An array is also a thing
 * {@link \NeuroSYS\Support\TypedItems::SCALARS} will not let a collection hold, so naming it is
 * what lets {@link Arrangement::positions()} answer with one.
 *
 * The offset is a **fraction of the arrangement** rather than a time, because that is what a
 * timeline needs and it is the only form that survives a change of tempo. {@link Section} keeps the
 * tick and refuses to know a bpm; this keeps the ratio and refuses to know a pixel.
 */
final readonly class SectionPosition
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Section $section The section being placed.
     * @param float   $offset  Where it begins, as a fraction of the whole arrangement: 0.0 at the
     *                         start, 1.0 at the last section's own start — which is not the end of
     *                         the track; see {@link Arrangement::lastStart()}.
     *
     * @throws ReleaseVerificationException if the offset falls outside that range.
     */
    public function __construct(
        public Section $section,
        public float $offset,
    ) {
        if ($this->offset < 0.0 || $this->offset > 1.0) {
            throw new ReleaseVerificationException(sprintf(
                'SectionPosition::offset is a fraction of the arrangement and must be between 0 '
                . 'and 1; got %s for %s.',
                $this->offset,
                $this->section->label,
            ));
        }
    }
}
