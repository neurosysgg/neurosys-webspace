<?php

declare(strict_types=1);

namespace NeuroSYS;

use NeuroSYS\Controller\UnroutedController;
use NeuroSYS\Http\Allow;
use NeuroSYS\Http\Header;
use NeuroSYS\Http\HttpStatusCode;
use NeuroSYS\Http\PlainTextResponse;
use NeuroSYS\Http\Request;
use NeuroSYS\Http\Response;
use NeuroSYS\Http\ResponseHeader;
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
        // The path is asked first and the method second, because the method question belongs to
        // the route: each Route carries a MethodPolicy. Nine of the ten are read-only, so POST to a
        // download route 405s with `Allow: GET, HEAD` rather than 303'ing like a GET; SitePath::Api
        // delegates the question to its own controller. See docs/history/api.md.
        foreach ($this->routes as $route) {
            if (($params = $route->matches($request->path())) !== false) {
                return $route->accepts($request->method())
                    ? $route->createController($params)->handle($request)
                    : self::refuse();
            }
        }

        // No route claimed the path, so there is no route's opinion to ask. UnroutedController
        // owns that answer — a 404 for a read verb, a 405 for a write one — and owns it because
        // ApiController has to give the identical one for a request it will not verify.
        return new UnroutedController()->handle($request);
    }

    /**
     * The 405, naming the methods that would have worked.
     *
     * The `Allow` is always the read-only set, never the matched route's own. Nine routes have no
     * other set to name; the tenth has one it must not name, because `Allow: GET, HEAD, POST` on
     * `/api` announces the endpoint that exists to be unannounceable — so it never reaches here
     * at all, having {@link \NeuroSYS\Support\MethodPolicy::Delegated} instead.
     *
     * @return PlainTextResponse
     */
    private static function refuse(): PlainTextResponse
    {
        return new PlainTextResponse(
            HttpStatusCode::MethodNotAllowed,
            UnroutedController::REFUSAL,
            new Collection(Header::class)->with(new Header(ResponseHeader::Allow, Allow::readOnly())),
        );
    }
}
