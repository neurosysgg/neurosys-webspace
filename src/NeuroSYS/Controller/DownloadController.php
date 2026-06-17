<?php
declare(strict_types=1);

namespace NeuroSYS\Controller;

use NeuroSYS\Http\HttpStatusCode;
use NeuroSYS\Http\PlainTextResponse;
use NeuroSYS\Http\RedirectResponse;
use NeuroSYS\Http\Request;
use NeuroSYS\Http\Response;
use NeuroSYS\Http\ViewResponse;
use NeuroSYS\Service\DownloadLogger;
use NeuroSYS\Service\ReleaseRepository;
use NeuroSYS\View\NotFoundView;

/**
 * The DownloadController class. Handles release download requests.
 *
 * Fetches the release and format, logs the download, and issues a redirect
 * to the file host. Returns 503 if the format URL has not yet been configured.
 */
readonly class DownloadController implements Controller
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $slug       The release slug.
     * @param string $formatType The format type value (e.g. 'flac', 'mp3').
     */
    public function __construct(
        private string $slug,
        private string $formatType,
    ) {}

    public function handle(Request $request): Response
    {
        $release = new ReleaseRepository()->find($this->slug);

        if ($release === null) {
            return new ViewResponse(new NotFoundView($request->path()), HttpStatusCode::NotFound);
        }

        $format = $release->findFormat($this->formatType);

        if ($format === null) {
            return new ViewResponse(new NotFoundView($request->path()), HttpStatusCode::NotFound);
        }

        if ($format->url === '') {
            return new PlainTextResponse(
                HttpStatusCode::ServiceUnavailable,
                "This file isn't available yet — check back soon.\n",
            );
        }

        new DownloadLogger()->log($this->slug, $this->formatType);

        return new RedirectResponse($format->url);
    }
}
