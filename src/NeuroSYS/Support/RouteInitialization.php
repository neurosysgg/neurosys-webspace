<?php

declare(strict_types=1);

namespace NeuroSYS\Support;

use Closure;
use NeuroSYS\Controller\DemoAudioController;
use NeuroSYS\Controller\DemoController;
use NeuroSYS\Controller\DownloadController;
use NeuroSYS\Controller\HomeController;
use NeuroSYS\Controller\ImprintController;
use NeuroSYS\Controller\PrivacyController;
use NeuroSYS\Controller\ReleaseController;
use NeuroSYS\Controller\ReleasesController;
use NeuroSYS\Controller\StatsController;

/** Builds and returns the application route table. */
class RouteInitialization
{
    /** @var Collection<Route> */
    private Collection $collection;

    /** Starts an empty table; {@link self::routes()} is the only way in. */
    private function __construct()
    {
        $this->collection = new Collection(Route::class);
    }

    /** @return Collection<Route> */
    public static function routes(): Collection
    {
        return new static()
            ->addRoute(SitePath::Home, fn() => new HomeController())
            ->addRoute(SitePath::Releases, fn() => new ReleasesController())
            ->addRoute(SitePath::Release, fn($slug) => new ReleaseController($slug))
            ->addRoute(SitePath::Download, fn($slug, $format) => new DownloadController($slug, $format))
            // No SitePath::Demos, and none on the enum either. A listing would publish the names
            // of unreleased tracks, which is the one thing this half of the site is arranged to
            // keep quiet — see DemoController.
            ->addRoute(SitePath::Demo, fn($slug) => new DemoController($slug))
            ->addRoute(SitePath::DemoAudio, fn($slug, $label) => new DemoAudioController($slug, $label))
            ->addRoute(SitePath::Stats, fn() => new StatsController())
            ->addRoute(SitePath::Imprint, fn() => new ImprintController())
            ->addRoute(SitePath::Privacy, fn() => new PrivacyController())
            ->collection;
    }

    /**
     * @param SitePath $pattern
     * @param Closure $factory
     * @return $this
     */
    private function addRoute(SitePath $pattern, Closure $factory): static
    {
        // Collection::with() copies rather than appends, so the result has to be kept.
        $this->collection = $this->collection->with(new Route($pattern, $factory));
        return $this;
    }
}
