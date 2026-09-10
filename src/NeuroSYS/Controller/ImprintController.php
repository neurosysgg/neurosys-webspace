<?php

declare(strict_types=1);

namespace NeuroSYS\Controller;

use NeuroSYS\Http\Request;
use NeuroSYS\Http\Response;
use NeuroSYS\Http\ViewResponse;
use NeuroSYS\View\ImprintView;

class ImprintController implements Controller
{
    /**
     * @param Request $request
     * @return Response
     */
    public function handle(Request $request): Response
    {
        // The request's language leads: the visitor's choice, else their browser's, else English —
        // see Request::language(). The German half is rendered either way.
        return new ViewResponse(new ImprintView($request->language()));
    }
}
