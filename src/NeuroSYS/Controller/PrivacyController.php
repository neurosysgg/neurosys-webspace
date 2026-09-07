<?php

declare(strict_types=1);

namespace NeuroSYS\Controller;

use NeuroSYS\Config;
use NeuroSYS\DataFile;
use NeuroSYS\Http\Request;
use NeuroSYS\Http\Response;
use NeuroSYS\Http\ViewResponse;
use NeuroSYS\View\Html\Language;
use NeuroSYS\View\PrivacyView;

class PrivacyController implements Controller
{
    /**
     * @param Request $request
     * @return Response
     */
    public function handle(Request $request): Response
    {
        return new ViewResponse(new PrivacyView(
            self::policy(DataFile::PrivacyGerman),
            self::policy(DataFile::PrivacyEnglish),
            // English first, so a request naming neither language gets the site's own — and a tie
            // does too. See AcceptedLanguages::preferred(), where that argument order *is* the
            // default rather than merely the first thing tried.
            $request->acceptedLanguages()->preferred(Language::English, Language::German),
        ));
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
     * Both halves read the same way, and either being absent is an empty half rather than an
     * error — which is the state a clone missing one is already allowed to be in.
     *
     * @param DataFile $half
     * @return string
     */
    private static function policy(DataFile $half): string
    {
        return Config::dataFile($half)->read() ?? '';
    }
}
