<?php

declare(strict_types=1);

namespace NeuroSYS\Controller;

use NeuroSYS\Http\HttpStatusCode;
use NeuroSYS\Http\Request;
use NeuroSYS\Http\Response;
use NeuroSYS\Http\ViewResponse;
use NeuroSYS\Service\ReleaseRepository;
use NeuroSYS\View\NotFoundView;
use NeuroSYS\View\ReleaseView;

/**
 * The ReleaseController class. Handles requests to a single release detail page.
 */
readonly class ReleaseController implements Controller
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $slug The URL slug identifying the release.
     * @param ReleaseRepository|null $releases The catalogue to read, or null for the
     *                                         canonical one. Only tests pass this.
     */
    public function __construct(
        private string $slug,
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

        return new ViewResponse(new ReleaseView($release, $this->slug));
    }
}
