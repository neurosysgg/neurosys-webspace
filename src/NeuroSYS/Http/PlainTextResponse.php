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
     * @param HttpStatusCode $status  The HTTP status code.
     * @param string         $body    The response body.
     * @param list<Header>   $headers Extra headers to send, e.g. `Allow:` on a 405.
     */
    public function __construct(
        private HttpStatusCode $status,
        private string         $body,
        private array          $headers = [],
    ) {}

    #[NoReturn]
    public function send(Request $request): never
    {
        http_response_code($this->status->value);
        header(new Header(ResponseHeader::ContentType, MimeType::plainText()->render())->line());

        foreach ($this->headers as $header) {
            header($header->line());
        }
        echo $this->body;
        exit;
    }
}
