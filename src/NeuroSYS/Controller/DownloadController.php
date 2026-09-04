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
use NeuroSYS\Model\ReleaseFormat;
use NeuroSYS\Service\ReleaseRepository;
use NeuroSYS\View\NotFoundView;

/**
 * The DownloadController class. Handles release download requests.
 *
 * Fetches the release and format, logs the download, and issues a redirect
 * to the file host. Returns 503 if the format has no link configured yet.
 */
readonly class DownloadController implements Controller
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $slug       The release slug.
     * @param string $formatType The format segment from the URL, which is whatever was requested
     *                           and not necessarily a {@link ReleaseFormat}.
     * @param ReleaseRepository|null $releases The catalogue to read, or null for the
     *                                         canonical one. Only tests pass this — it
     *                                         is the seam for exercising the staged
     *                                         (link-less) branch without a real release.
     */
    public function __construct(
        private string $slug,
        private string $formatType,
        private ?ReleaseRepository $releases = null,
    ) {}

    public function handle(Request $request): Response
    {
        $release = ($this->releases ?? new ReleaseRepository())->find($this->slug);

        if ($release === null) {
            return new ViewResponse(new NotFoundView($request->path()), HttpStatusCode::NotFound);
        }

        $type   = ReleaseFormat::tryFrom($this->formatType);
        $format = $type === null ? null : $release->findFormat($type);

        if ($format === null) {
            return new ViewResponse(new NotFoundView($request->path()), HttpStatusCode::NotFound);
        }

        if ($format->link === null) {
            return new PlainTextResponse(
                HttpStatusCode::ServiceUnavailable,
                "This file isn't available yet — check back soon.\n",
            );
        }

        new DownloadLogger()->log($this->slug, $type);

        return new RedirectResponse($format->link->url());
    }
}
