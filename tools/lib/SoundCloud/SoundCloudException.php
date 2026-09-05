<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\SoundCloud;

use NeuroSYS\Tool\Http\Response;
use RuntimeException;

/**
 * The SoundCloudException class. The API answered, and the answer was no.
 *
 * Distinct from {@link \NeuroSYS\Tool\Http\TransportException}, which is nothing having arrived at
 * all. This carries the status and the body, because the body is where the API says which field it
 * disliked — and an upload refused for a reason nobody printed is an upload retried blind.
 */
final class SoundCloudException extends RuntimeException
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $message
     * @param int    $status  The HTTP status, or 0 where the failure was not one.
     * @param string $body    The response body, verbatim.
     */
    public function __construct(string $message, public readonly int $status = 0, public readonly string $body = '')
    {
        parent::__construct($message);
    }

    /**
     * The exception for a response that failed.
     *
     * The body is trimmed to a length that fits a terminal and no further: an API's own words about
     * what it refused are the most useful thing on the screen at that moment.
     *
     * @param Attempt  $what     What was being attempted, whose value is the phrase this reads it
     *                            back as. It was a `string` needing this line to describe its own
     *                            format, which is the argument the enum was written on.
     * @param Response $response
     * @return self
     */
    public static function refused(Attempt $what, Response $response): self
    {
        $reason = $response->code()?->name ?? 'an unnamed status';

        return new self(
            sprintf(
                "SoundCloud refused %s with %d (%s):\n  %s",
                $what->value,
                $response->status,
                $reason,
                mb_substr(trim($response->body), 0, 500) ?: '(no body)',
            ),
            $response->status,
            $response->body,
        );
    }
}
