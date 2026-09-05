<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Release;

/**
 * The AudioStream class. What `ffprobe` says about one audio file.
 *
 * `bits` is null for a lossy codec, which has no bit depth to report. {@link Preflight} only
 * compares it across lossless formats, where it is always there.
 */
final readonly class AudioStream
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $codec
     * @param int    $rate     Samples per second.
     * @param int|null $bits   Bits per sample, or null for a lossy codec.
     * @param float  $duration Seconds.
     */
    public function __construct(
        public string $codec,
        public int $rate,
        public ?int $bits,
        public float $duration,
    ) {}

    /**
     * Whether two files were exported at the same resolution.
     *
     * @param self $other
     * @return bool
     */
    public function matchesResolutionOf(self $other): bool
    {
        return $this->rate === $other->rate && $this->bits === $other->bits;
    }

    /**
     * `24-bit/48.0kHz`, for a report.
     *
     * @return string
     */
    public function resolution(): string
    {
        return sprintf('%d-bit/%.1fkHz', $this->bits ?? 0, $this->rate / 1000);
    }
}
