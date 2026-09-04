<?php

declare(strict_types=1);

namespace NeuroSYS\Http;

use JetBrains\PhpStorm\NoReturn;

/**
 * The PlainTextResponse class. Sends a plain-text HTTP response and terminates.
 */
readonly class PlainTextResponse implements Response
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param HttpStatusCode $status The HTTP status code.
     * @param string         $body   The response body.
     */
    public function __construct(
        private HttpStatusCode $status,
        private string         $body,
    ) {}

    #[NoReturn]
    public function send(Request $request): never
    {
        http_response_code($this->status->value);
        header('Content-Type: text/plain; charset=utf-8');
        echo $this->body;
        exit;
    }
}
