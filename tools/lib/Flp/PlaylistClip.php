<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Flp;

/**
 * The PlaylistClip class. One block on the playlist — a pattern, or something that is not one.
 *
 * The playlist is where a project stops being a pile of patterns and becomes an arrangement, and a
 * clip is one placement in it: this pattern, at this tick, for this long. A pattern's notes are
 * written once and may be placed a dozen times, which is why {@link Score::arrangement()} produces
 * more notes than the patterns hold — 7,564 against 3,309 in the project this was measured on.
 *
 * **Most clips are not patterns.** 566 of 715 in that project are audio or automation, which have
 * no notes and are the reason {@link self::$pattern} is nullable rather than the reason it is
 * wrong. They are decoded and carried rather than skipped at the door, so a count of what was
 * passed over is available to say out loud.
 */
final readonly class PlaylistClip
{
    /**
     * The value {@link self::$index} is offset by when it names a pattern rather than a channel.
     *
     * Constant across every project tested, FL 12 to FL 26, and it is what
     * {@link Playlist::stride()} uses as its canary — see there.
     */
    public const int PATTERN_BASE = 20480;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param int      $position    Ticks from the start of the song.
     * @param int      $length      Ticks. Notes falling outside this are trimmed away by the clip.
     * @param int      $index       The raw field: a pattern when at or above
     *                              {@link self::PATTERN_BASE}, a rack channel below it.
     * @param int      $startOffset Ticks trimmed from the pattern's front, or -1 for untrimmed.
     *                              **Read as a signed int and not a float**: an untrimmed clip
     *                              writes `ff ff ff ff`, which as a float is `NAN` and as an int
     *                              is the -1 it means.
     * @param int      $track       The playlist track, raw. Its encoding is version-dependent — FL
     *                              20 and later store `500 - track` — so it is carried undecoded
     *                              and nothing here depends on it. A clip without its track is one
     *                              that cannot be found again in the project.
     */
    public function __construct(
        public int $position,
        public int $length,
        public int $index,
        public int $startOffset,
        public int $track,
    ) {}

    /**
     * The pattern this clip places, or null when it places something that is not a pattern.
     *
     * @return int|null
     */
    public function pattern(): ?int
    {
        return $this->index >= self::PATTERN_BASE ? $this->index - self::PATTERN_BASE : null;
    }

    /**
     * The tick the pattern is read from — the offset, or zero where the clip is untrimmed.
     *
     * @return int
     */
    public function readFrom(): int
    {
        return max(0, $this->startOffset);
    }
}
