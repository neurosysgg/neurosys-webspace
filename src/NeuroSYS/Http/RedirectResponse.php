<?php

declare(strict_types=1);

namespace NeuroSYS\Http;

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

    /**
     * Sends the redirect and ends the request.
     *
     * `never` rather than `void`, and that is the whole declaration: the caller cannot have code
     * after this, and the engine knows it. It carried a `#[JetBrains\PhpStorm\NoReturn]` alongside
     * for a while, from a package this project does not require and does not have — so it was an
     * undefined class in the one place a reader looks for a type, restating what the native return
     * type already says. {@link \NeuroSYS\Service\Auth::challenge()} has always been plain `never`.
     */
    public function send(Request $request): never
    {
        header(new Header(ResponseHeader::Location, new Location($this->url))->line(), true, $this->status->value);
        exit;
    }
}
