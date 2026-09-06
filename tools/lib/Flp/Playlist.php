<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Flp;

use NeuroSYS\Support\Collection;

/**
 * The Playlist class. Every clip on the playlist, read out of the one event that holds them all.
 *
 * {@link EventId::Playlist} is a single variable-width event carrying every clip end to end, with
 * no count and no separators — so the only thing that says where one clip stops is how wide a clip
 * is, and **that width changes between FL Studio versions**. It is 32 bytes in FL 12.4 and 80 in
 * FL 25 and 26. Nothing in the file says which.
 *
 * That is the same shape of trap as {@link EventWidth::NARROW_DWORD}, and it fails the same quiet
 * way: a wrong width still divides some lengths evenly, still reads plausible small integers out of
 * the middle of a struct, and produces an arrangement that is merely *wrong* rather than an error.
 * A project that renders as MIDI and simply plays the wrong notes is the worst available outcome,
 * because nothing about it looks broken.
 *
 * **So the width is probed, against a canary the format hands over.** Every clip's `u16` at offset
 * 4 is {@link PlaylistClip::PATTERN_BASE} — 20480 — in every project tested, FL 12.4 through
 * FL 26. A candidate width is accepted only when it divides the event exactly *and* every clip it
 * implies carries that value at that offset, and the **smallest** surviving candidate wins: a
 * multiple of the true width always passes too, since it only ever checks a subset of the clips.
 *
 * Two bounds keep the probe honest. It will not consider a width below
 * {@link self::MINIMUM_STRIDE}, because this class reads out to offset 28 and no FL version has
 * written a clip smaller than 32 — without that floor a pathological file could match at a width
 * that cannot hold the fields being read out of it. And a project whose playlist matches at no
 * width at all comes back **null** rather than half-read, the way
 * {@link \NeuroSYS\Http\Request::path()} answers null rather than guessing.
 */
final readonly class Playlist
{
    /** The narrowest clip any FL Studio has written, and the narrowest this reads out to. */
    private const int MINIMUM_STRIDE = 32;

    /** The widest worth trying. FL doubled the clip once already; this leaves room for it again. */
    private const int MAXIMUM_STRIDE = 256;

    /** Clip widths have always been a multiple of this, being a struct of dwords and words. */
    private const int STRIDE_STEP = 4;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param Collection<PlaylistClip> $clips  In file order, which is not playing order.
     * @param int                      $stride The width the probe settled on, kept because it is
     *                                         the one fact worth printing when an arrangement
     *                                         comes out wrong.
     */
    private function __construct(public Collection $clips, public int $stride) {}

    /**
     * Reads a project's playlist.
     *
     * @param FlpFile $flp
     * @return self|null null where the project holds no playlist event, or holds one whose clip
     *                   width could not be established. Both mean the same thing to a caller —
     *                   there is no arrangement to be had — and neither is an error, because a
     *                   project of nothing but patterns is a real project.
     */
    public static function of(FlpFile $flp): ?self
    {
        $event = $flp->first(EventId::Playlist);
        $bytes = $event?->value;

        if (!is_string($bytes) || $bytes === '') {
            return null;
        }

        $stride = self::stride($bytes);

        if ($stride === null) {
            return null;
        }

        $clips = new Collection(PlaylistClip::class);

        for ($offset = 0; $offset + $stride <= strlen($bytes); $offset += $stride) {
            $fields = unpack('Vposition/vbase/vindex/Vlength/Vtrack', substr($bytes, $offset, 16));

            $clips = $clips->with(new PlaylistClip(
                position:    $fields['position'],
                length:      $fields['length'],
                index:       $fields['index'],
                startOffset: unpack('l', substr($bytes, $offset + 24, 4))[1],
                track:       $fields['track'],
            ));
        }

        return new self($clips, $stride);
    }

    /**
     * The clip width this event is written at, or null where no candidate holds.
     *
     * @param string $bytes
     * @return int|null
     */
    private static function stride(string $bytes): ?int
    {
        $length = strlen($bytes);

        for ($stride = self::MINIMUM_STRIDE; $stride <= self::MAXIMUM_STRIDE; $stride += self::STRIDE_STEP) {
            if ($length % $stride !== 0) {
                continue;
            }

            if (self::holds($bytes, $stride)) {
                return $stride;
            }
        }

        return null;
    }

    /**
     * Whether every clip at this width carries the canary.
     *
     * @param string $bytes
     * @param int    $stride
     * @return bool
     */
    private static function holds(string $bytes, int $stride): bool
    {
        for ($offset = 0; $offset + $stride <= strlen($bytes); $offset += $stride) {
            if (unpack('v', substr($bytes, $offset + 4, 2))[1] !== PlaylistClip::PATTERN_BASE) {
                return false;
            }
        }

        return true;
    }
}
