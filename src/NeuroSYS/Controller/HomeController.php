<?php

declare(strict_types=1);

namespace NeuroSYS\Controller;

use NeuroSYS\View\HomeView;
use Phpanta\Controller\Controller;
use Phpanta\Http\Request;
use Phpanta\Http\Response;
use Phpanta\Http\ViewResponse;

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
