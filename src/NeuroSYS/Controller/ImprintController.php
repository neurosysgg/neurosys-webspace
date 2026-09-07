<?php

declare(strict_types=1);

namespace NeuroSYS\Controller;

use NeuroSYS\Http\Request;
use NeuroSYS\Http\Response;
use NeuroSYS\Http\ViewResponse;
use NeuroSYS\View\Html\Language;
use NeuroSYS\View\ImprintView;

class ImprintController implements Controller
{
    /**
     * @param Request $request
     * @return Response
     */
    public function handle(Request $request): Response
    {
        // English first, so a request naming neither language gets the site's own, and so does a
        // tie — see AcceptedLanguages::preferred(). The German half is rendered either way.
        return new ViewResponse(new ImprintView(
            $request->acceptedLanguages()->preferred(Language::English, Language::German),
        ));
    }
}
