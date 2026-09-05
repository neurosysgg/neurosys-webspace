<?php

declare(strict_types=1);

namespace NeuroSYS\Model\Production;

use NeuroSYS\Exception\ReleaseVerificationException;

/**
 * The ProductionTime class. How long a release took, as FL Studio counted it.
 *
 * `ill.` is 60 hours and 7 minutes; `hello world!` is 42 hours and 48 minutes. The project keeps a
 * running total and this reads it, which makes it the rare fact on a release that is both exact and
 * impossible to reconstruct from anything that shipped.
 *
 * Seconds in, because that is the unit the source has once its day-fraction is unwound, and a class
 * rather than an int for the reason `MimeType` is one: the value has a rendering, and every call
 * site that formatted it by hand would format it slightly differently.
 */
final readonly class ProductionTime
{
    private const int SECONDS_PER_HOUR = 3600;
    private const int SECONDS_PER_MINUTE = 60;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param int $seconds
     *
     * @throws ReleaseVerificationException if the total is negative.
     */
    public function __construct(public int $seconds)
    {
        if ($this->seconds < 0) {
            throw new ReleaseVerificationException('ProductionTime::seconds cannot be negative.');
        }
    }

    /**
     * @param int $hours
     * @param int $minutes
     * @return self
     */
    public static function of(int $hours, int $minutes = 0): self
    {
        return new self($hours * self::SECONDS_PER_HOUR + $minutes * self::SECONDS_PER_MINUTE);
    }

    /**
     * @return int
     */
    public function hours(): int
    {
        return intdiv($this->seconds, self::SECONDS_PER_HOUR);
    }

    /**
     * @return int
     */
    public function minutes(): int
    {
        return intdiv($this->seconds % self::SECONDS_PER_HOUR, self::SECONDS_PER_MINUTE);
    }

    /**
     * The value as it reads on the page — `60h 07m`, or `48m` under the hour.
     *
     * @return string
     */
    public function render(): string
    {
        return $this->hours() > 0
            ? sprintf('%dh %02dm', $this->hours(), $this->minutes())
            : sprintf('%dm', $this->minutes());
    }
}
