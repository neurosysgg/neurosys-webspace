<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Release;

/**
 * The Finding class. One thing {@link Preflight} noticed about a folder.
 *
 * Replaces the `array{0: string, 1: string}` tuples this used to be, where reading a finding meant
 * remembering which end the severity was on.
 */
final readonly class Finding
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Level  $level
     * @param string $message Written for someone about to upload, so it says what to do about it.
     */
    public function __construct(public Level $level, public string $message) {}

    /**
     * @param string $message
     * @return self
     */
    public static function ok(string $message): self
    {
        return new self(Level::Ok, $message);
    }

    /**
     * @param string $message
     * @return self
     */
    public static function warn(string $message): self
    {
        return new self(Level::Warn, $message);
    }

    /**
     * @param string $message
     * @return self
     */
    public static function fail(string $message): self
    {
        return new self(Level::Fail, $message);
    }
}
