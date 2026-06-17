<?php
declare(strict_types=1);

namespace NeuroSYS\Controller;

use NeuroSYS\Http\HttpStatusCode;
use NeuroSYS\Http\Request;
use NeuroSYS\Http\Response;
use NeuroSYS\Http\ViewResponse;
use NeuroSYS\View\NotFoundView;

/**
 * The NotFoundController class. Handles unmatched routes by rendering a 404 error page.
 */
readonly class NotFoundController implements Controller
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $path The request path that was not found.
     */
    public function __construct(private string $path) {}

    public function handle(Request $request): Response
    {
        return new ViewResponse(new NotFoundView($this->path), HttpStatusCode::NotFound);
    }
}
