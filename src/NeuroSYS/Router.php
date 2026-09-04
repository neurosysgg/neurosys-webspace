<?php

declare(strict_types=1);

namespace NeuroSYS;

use NeuroSYS\Controller\NotFoundController;
use NeuroSYS\Http\HttpStatusCode;
use NeuroSYS\Http\PlainTextResponse;
use NeuroSYS\Http\Request;
use NeuroSYS\Http\Response;
use NeuroSYS\Support\Collection;
use NeuroSYS\Support\Route;

/** Maps incoming requests to controllers using a registered Collection<Route>. */
readonly class Router
{
    /**
     * Constructs an instance of {@link self}.
     * @param Collection<Route> $routes
     */
    public function __construct(private Collection $routes) {}

    /**
     * Dispatches the given {@link Request} to the appropriate {@link Controller}.
     * @param Request $request
     * @return Response
     */
    public function dispatch(Request $request): Response
    {
        // Every route is a read. Without this, POST/PUT/DELETE to a download route
        // would 303 exactly like a GET.
        if (!$request->isReadOnly()) {
            return new PlainTextResponse(
                HttpStatusCode::MethodNotAllowed,
                "This site is read-only.\n",
                ['Allow: GET, HEAD'],
            );
        }

        foreach ($this->routes as $route) {
            if (($params = $route->matches($request->path())) !== false) {
                return $route->createController($params)->handle($request);
            }
        }
        return new NotFoundController($request->path())->handle($request);
    }
}
