<?php
declare(strict_types=1);

namespace NeuroSYS\Controller;

use NeuroSYS\Http\Request;
use NeuroSYS\Http\Response;
use NeuroSYS\Http\ViewResponse;
use NeuroSYS\View\PrivacyView;

class PrivacyController implements Controller
{
    public function handle(Request $request): Response
    {
        $html = file_get_contents(__DIR__ . '/../../../data/privacy.html') ?: '';
        return new ViewResponse(new PrivacyView($html));
    }
}