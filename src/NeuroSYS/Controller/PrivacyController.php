<?php

declare(strict_types=1);

namespace NeuroSYS\Controller;

use NeuroSYS\Config;
use NeuroSYS\DataFile;
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
     * This used to be `is_file($f) ? file_get_contents($f) ?: '' : ''`, written the way every other
     * data-file read on this site was written — and it had the flaw that made
     * {@link \NeuroSYS\Support\File} worth
     * having. `is_file()` guards a file that is absent and does nothing about one that is present
     * and unreadable, so `file_get_contents()` emitted a **warning**; the response headers have
     * already gone out by the time this runs, so that warning printed into the page ahead of the
     * doctype rather than anywhere a log would catch it. `read()` answers null for both causes,
     * which is what the ternary was collapsing them to anyway.
     *
     * @return string
     */
    private static function policy(): string
    {
        return Config::dataFile(DataFile::Privacy)->read() ?? '';
    }
}
