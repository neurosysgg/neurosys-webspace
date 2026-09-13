<?php

declare(strict_types=1);

namespace NeuroSYS\Controller;

use NeuroSYS\DataFile;
use NeuroSYS\Site;
use NeuroSYS\View\PrivacyView;
use Phpanta\Controller\Controller;
use Phpanta\Http\Request;
use Phpanta\Http\Response;
use Phpanta\Http\ViewResponse;

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
            // The request's language leads: the visitor's choice, else their browser's, else
            // English — see Request::language(). Both halves are rendered either way.
            $request->language(),
        ));
    }

    /**
     * The policy document, or an empty string if it is not there.
     *
     * Read through {@link \Phpanta\Support\File::read()}, which answers null for a file that is
     * absent and for one that is present and unreadable. `is_file()` would guard only the first,
     * and `file_get_contents()` on the second emits a **warning** — after the response headers
     * have gone out, so it would print into the page ahead of the doctype rather than anywhere a
     * log would catch it. See docs/history/types.md.
     *
     * Both halves read the same way, and either being absent is an empty half rather than an
     * error — which is the state a clone missing one is already allowed to be in.
     *
     * @param DataFile $half
     * @return string
     */
    private static function policy(DataFile $half): string
    {
        return Site::current()->dataFile($half)->read() ?? '';
    }
}
