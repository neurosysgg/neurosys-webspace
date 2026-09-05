<?php

declare(strict_types=1);

namespace NeuroSYS\Http;

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

    /**
     * Sends the body and ends the request.
     *
     * `never` rather than `void` — see the note on {@link RedirectResponse::send()} for why the
     * JetBrains attribute that used to sit here as well is gone.
     */
    public function send(Request $request): never
    {
        http_response_code($this->status->value);
        header(new Header(ResponseHeader::ContentType, MimeType::plainText())->line());

        foreach ($this->headers as $header) {
            header($header->line());
        }
        echo $this->body;
        exit;
    }
}
