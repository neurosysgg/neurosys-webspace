<?php

declare(strict_types=1);

namespace NeuroSYS\Controller;

use NeuroSYS\Config;
use NeuroSYS\Http\Request;
use NeuroSYS\Http\Response;
use NeuroSYS\Http\ViewResponse;
use NeuroSYS\View\PrivacyView;

class PrivacyController implements Controller
{
    /**
     * @param Request $request
     * @return Response
     */
    public function handle(Request $request): Response
    {
        return new ViewResponse(new PrivacyView(self::policy()));
    }

    /**
     * The policy document, or an empty string if it is not there.
     *
     * `is_file()` first, the way every other data-file read on this site does it — `Auth`,
     * `ProfileRepository` and `StatsController` all check before reading. `?:` handles
     * `file_get_contents()`'s return value but not its **warning**, and the headers have already
     * gone out by the time this runs, so on a deployment missing `data/privacy.html` the warning
     * prints into the page ahead of the doctype rather than anywhere a log would catch it.
     *
     * @return string
     */
    private static function policy(): string
    {
        $file = Config::dataPath('privacy.html');

        return is_file($file) ? file_get_contents($file) ?: '' : '';
    }
}
