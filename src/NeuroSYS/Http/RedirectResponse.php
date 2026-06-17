<?php
declare(strict_types=1);

namespace NeuroSYS\Http;

use JetBrains\PhpStorm\NoReturn;

/**
 * The RedirectResponse class. Issues an HTTP redirect to the given URL and terminates.
 */
readonly class RedirectResponse implements Response
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string         $url    The URL to redirect to.
     * @param HttpStatusCode $status The HTTP status code; defaults to 303 See Other.
     */
    public function __construct(
        private string         $url,
        private HttpStatusCode $status = HttpStatusCode::SeeOther,
    ) {}

    #[NoReturn]
    public function send(Request $request): never
    {
        header('Location: ' . $this->url, true, $this->status->value);
        exit;
    }
}
