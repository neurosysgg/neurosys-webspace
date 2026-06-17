<?php
declare(strict_types=1);

namespace NeuroSYS\Controller;

use NeuroSYS\Http\Request;
use NeuroSYS\Http\Response;
use NeuroSYS\Http\ViewResponse;
use NeuroSYS\Service\ReleaseRepository;
use NeuroSYS\View\ReleasesView;

/**
 * The ReleasesController class. Handles requests to the releases listing page.
 */
class ReleasesController implements Controller
{
    public function handle(Request $request): Response
    {
        return new ViewResponse(new ReleasesView(new ReleaseRepository()->all()));
    }
}
