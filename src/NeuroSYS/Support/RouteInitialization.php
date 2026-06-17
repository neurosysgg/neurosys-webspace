<?php
declare(strict_types=1);

namespace NeuroSYS\Support;

use Closure;
use NeuroSYS\Controller\DownloadController;
use NeuroSYS\Controller\HomeController;
use NeuroSYS\Controller\ReleaseController;
use NeuroSYS\Controller\ReleasesController;
use NeuroSYS\Controller\StatsController;

/** Builds and returns the application route table. */
class RouteInitialization
{
    /** @var Collection<Route> */
    private Collection $collection;

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
            ->collection;
    }

    private function addRoute(string $pattern, Closure $factory): static
    {
        $this->collection->add(new Route($pattern, $factory));
        return $this;
    }
}
