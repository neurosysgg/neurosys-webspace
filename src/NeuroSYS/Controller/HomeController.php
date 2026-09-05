<?php

declare(strict_types=1);

namespace NeuroSYS\Controller;

use NeuroSYS\Http\Request;
use NeuroSYS\Http\Response;
use NeuroSYS\Http\ViewResponse;
use NeuroSYS\View\HomeView;

/**
 * The HomeController class. Handles requests to the home page.
 */
class HomeController implements Controller
{
    /**
     * @param Request $request
     * @return Response
     */
    public function handle(Request $request): Response
    {
        return new ViewResponse(new HomeView());
    }
}
