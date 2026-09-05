<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Flp;

/**
 * The MarkerType enum. What a playlist or piano-roll marker is for.
 *
 * FL keeps all three kinds in one list and tells them apart by the **top byte of the marker's
 * position dword** — the low three bytes are the tick, which is why a project would need to be
 * 194 days long before the two fields could collide.
 *
 * They are three different questions wearing the same event id, and only reading the type keeps
 * them apart: `4/4` and `DROP` and `D# Minor Natural (Aeolian)` all arrive as
 * {@link EventId::MarkerName}.
 */
enum MarkerType: int
{
    /** A named point in the arrangement — `INTRO`, `DROP`, `BUILDDOWN`. */
    case Structure = 0x00;

    /** A time signature, whose name is written `4/4`. */
    case TimeSignature = 0x08;

    /** A key/scale marker — the piano roll's key lock. Its root is {@link EventId::MarkerRoot}. */
    case Scale = 0x0C;

    /**
     * The type packed into a marker's position dword.
     *
     * @param int $position
     * @return self|null null for a type this reader has no question about.
     */
    public static function of(int $position): ?self
    {
        return self::tryFrom(($position >> 24) & 0xFF);
    }

    /**
     * The tick a marker sits at, with the type masked back off.
     *
     * @param int $position
     * @return int
     */
    public static function tickOf(int $position): int
    {
        return $position & 0x00FFFFFF;
    }
}
