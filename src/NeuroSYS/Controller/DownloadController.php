<?php

declare(strict_types=1);

namespace NeuroSYS\Controller;

use NeuroSYS\Model\ReleaseFormat;
use NeuroSYS\Service\DownloadLogger;
use NeuroSYS\Service\ReleaseRepository;
use NeuroSYS\Text\Texts;
use NeuroSYS\View\NotFoundView;
use Phpanta\Controller\Controller;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\Location;
use Phpanta\Http\PlainTextResponse;
use Phpanta\Http\RedirectResponse;
use Phpanta\Http\Request;
use Phpanta\Http\Response;
use Phpanta\Http\ViewResponse;

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

    /**
     * @param Request $request
     * @return Response
     */
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
                Texts::Errors::NotYetAvailable->in($request->language()) . "\n",
            );
        }

        new DownloadLogger()->log($this->slug, $type);

        return new RedirectResponse(new Location($format->link->url()));
    }
}
