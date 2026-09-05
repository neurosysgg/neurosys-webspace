<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Release;

/**
 * The Level enum. How seriously to take a {@link Finding}.
 *
 * `Warn` is the useful middle and the reason there are three rather than two: a release with its
 * cover still embedded in the FLAC is publishable, and one whose WAV disagrees with its FLAC is not.
 * Collapsing those into a single "problem" would make the report worth ignoring.
 */
enum Level: string
{
    case Ok   = 'ok';
    case Warn = 'warn';
    case Fail = 'fail';

    /**
     * The four-column label the report renders, matching `test/basic_test.sh`'s own `OK`/`FAIL`.
     *
     * @return string
     */
    public function label(): string
    {
        return str_pad(strtoupper($this->value), 4);
    }

    /**
     * Whether this level should stop the command.
     *
     * @return bool
     */
    public function isFailure(): bool
    {
        return $this === self::Fail;
    }
}
