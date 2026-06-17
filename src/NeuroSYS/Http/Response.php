<?php
declare(strict_types=1);

namespace NeuroSYS\Http;

/**
 * The Response interface. Represents an HTTP response that can be sent to the client.
 */
interface Response
{
    /** Sends the response to the client. */
    public function send(Request $request): void;
}
