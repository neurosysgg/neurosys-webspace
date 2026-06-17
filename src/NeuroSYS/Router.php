<?php
declare(strict_types=1);

namespace NeuroSYS;

use NeuroSYS\Controller\Controller;
use NeuroSYS\Controller\DownloadController;
use NeuroSYS\Controller\HomeController;
use NeuroSYS\Controller\NotFoundController;
use NeuroSYS\Controller\ReleaseController;
use NeuroSYS\Controller\ReleasesController;
use NeuroSYS\Controller\StatsController;
use NeuroSYS\Http\Request;
use NeuroSYS\Http\Response;

/**
 * The Router class. Maps incoming request paths to controllers based solely on URL structure.
 *
 * The Router has no data dependencies — it does not access release data or any other
 * domain resources. Each controller is responsible for fetching its own data.
 */
class Router
{
    /**
     * Resolves and dispatches the request to the appropriate controller.
     *
     * @param Request $request The incoming request.
     * @return Response The response returned by the resolved controller.
     */
    public function dispatch(Request $request): Response
    {
        return $this->resolve($request)->handle($request);
    }

    /** Resolves the request to a controller based on URL segments. */
    private function resolve(Request $request): Controller
    {
        $segments = $request->segments();

        if (count($segments) === 0) {
            return new HomeController();
        }

        if ($segments[0] === 'releases') {
            if (count($segments) === 1) {
                return new ReleasesController();
            }

            $slug   = $segments[1];
            $action = $segments[2] ?? 'index';

            if ($action === 'index') {
                return new ReleaseController($slug);
            }

            return new DownloadController($slug, $action);
        }

        if ($segments[0] === 'admin' && ($segments[1] ?? '') === 'stats') {
            return new StatsController();
        }

        return new NotFoundController($request->path());
    }
}
