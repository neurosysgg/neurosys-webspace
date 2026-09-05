<?php

declare(strict_types=1);

namespace NeuroSYS\Support;

use Closure;
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
            ->addRoute('/', fn() => new HomeController())
            ->addRoute('/releases', fn() => new ReleasesController())
            ->addRoute('/releases/{slug}', fn($slug) => new ReleaseController($slug))
            ->addRoute('/releases/{slug}/{format}', fn($slug, $format) => new DownloadController($slug, $format))
            ->addRoute('/admin/stats', fn() => new StatsController())
            ->addRoute('/imprint', fn() => new ImprintController())
            ->addRoute('/privacy', fn() => new PrivacyController())
            ->collection;
    }

    /**
     * @param string $pattern
     * @param Closure $factory
     * @return $this
     */
    private function addRoute(string $pattern, Closure $factory): static
    {
        // Collection::with() copies rather than appends, so the result has to be kept.
        $this->collection = $this->collection->with(new Route($pattern, $factory));
        return $this;
    }
}
