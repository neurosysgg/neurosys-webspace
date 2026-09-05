<?php

declare(strict_types=1);

namespace NeuroSYS\Controller;

use NeuroSYS\Http\Request;
use NeuroSYS\Http\Response;
use NeuroSYS\Http\ViewResponse;
use NeuroSYS\Service\ReleaseRepository;
use NeuroSYS\View\ReleasesView;

/**
 * The ReleasesController class. Handles requests to the releases listing page.
 */
readonly class ReleasesController implements Controller
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param ReleaseRepository|null $releases The catalogue to read, or null for the
     *                                         canonical one. Only tests pass this.
     */
    public function __construct(private ?ReleaseRepository $releases = null) {}

    public function handle(Request $request): Response
    {
        return new ViewResponse(new ReleasesView(($this->releases ?? new ReleaseRepository())->all()));
    }
}
