<?php

declare(strict_types=1);

namespace NeuroSYS\Controller;

use NeuroSYS\View\NotFoundView;
use Phpanta\Controller\Controller;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\Request;
use Phpanta\Http\Response;
use Phpanta\Http\ViewResponse;

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

    /**
     * @param Request $request
     * @return Response
     */
    public function handle(Request $request): Response
    {
        return new ViewResponse(new NotFoundView($this->path), HttpStatusCode::NotFound);
    }
}
