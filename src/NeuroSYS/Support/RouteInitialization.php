<?php

declare(strict_types=1);

namespace NeuroSYS\Support;

use Closure;
use NeuroSYS\Controller\DemoAudioController;
use NeuroSYS\Controller\DemoController;
use NeuroSYS\Controller\DownloadController;
use NeuroSYS\Controller\HomeController;
use NeuroSYS\Controller\ImprintController;
use NeuroSYS\Controller\LanguageController;
use NeuroSYS\Controller\PrivacyController;
use NeuroSYS\Controller\ReleaseController;
use NeuroSYS\Controller\ReleasesController;
use Phpanta\Controller\Layer;
use Phpanta\Support\Collection;
use Phpanta\Support\MethodPolicy;
use Phpanta\Support\Route;

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
            ->addRoute(SitePath::Imprint, fn() => new ImprintController())
            ->addRoute(SitePath::Privacy, fn() => new PrivacyController())
            ->addRoute(SitePath::Language, fn($language) => new LanguageController($language))
            // No admin route: /admin and everything under it is the framework's, and
            // App::routeTable() appends it after these.
            ->collection;
    }

    /**
     * @param SitePath $pattern
     * @param Closure $factory
     * @param MethodPolicy $methods The default is what every route here wants, and none of them
     *                              states it.
     * @param Closure|null $exports Which pages a static export writes for it — see Route. Only a
     *                              route behind a password needs one, to say it has none.
     * @param Layer|null   $through What stands around the route's controller. No route here has a
     *                              gate of its own today; a demo's is in its controllers, because it
     *                              needs the demo it looks up.
     * @return $this
     */
    private function addRoute(
        SitePath $pattern,
        Closure $factory,
        MethodPolicy $methods = MethodPolicy::ReadOnly,
        ?Closure $exports = null,
        ?Layer $through = null,
    ): static {
        $route = new Route($pattern, $factory, $methods, $exports);

        // Collection::with() copies rather than appends, and so does through(): both results kept.
        $this->collection = $this->collection->with($through === null ? $route : $route->through($through));
        return $this;
    }
}
