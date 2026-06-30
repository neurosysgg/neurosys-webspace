<?php
declare(strict_types=1);

namespace NeuroSYS\Controller;

use NeuroSYS\Http\Request;
use NeuroSYS\Http\Response;
use NeuroSYS\Http\ViewResponse;
use NeuroSYS\View\ImprintView;

class ImprintController implements Controller
{
    public function handle(Request $request): Response
    {
        return new ViewResponse(new ImprintView());
    }
}