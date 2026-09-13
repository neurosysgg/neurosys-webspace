<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Controller\DownloadController;
use NeuroSYS\Controller\HomeController;
use NeuroSYS\Controller\ImprintController;
use NeuroSYS\Controller\PrivacyController;
use NeuroSYS\Controller\ReleaseController;
use NeuroSYS\Controller\ReleasesController;
use NeuroSYS\Controller\StatsController;
use NeuroSYS\Site;
use NeuroSYS\Support\RouteInitialization;
use NeuroSYS\Support\SitePath;
use Phpanta\App;
use Phpanta\Http\HttpMethod;
use Phpanta\Service\Layer\AdminGate;
use Phpanta\Support\ApiPath;
use Phpanta\Support\MethodPolicy;
use Phpanta\Support\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The site's addresses and its route table.
 *
 * How a pattern matches, what `to()` fills it with, and how the router answers are the framework's,
 * and asserted in its own suite over fixture paths. What is here is this site's vocabulary — every
 * address written out in full, so a wrong case fails — and what its table does with it.
 */
#[CoversClass(App::class)]
#[CoversClass(ApiPath::class)]
#[CoversClass(Route::class)]
#[CoversClass(MethodPolicy::class)]
#[CoversClass(RouteInitialization::class)]
#[CoversClass(SitePath::class)]
final class RoutingTest extends TestCase
{
    /**
     * Every pattern is plain segments and placeholders. `Route` quotes a pattern's static parts, so
     * a metacharacter would match only itself now; this keeps the addresses the site names plain,
     * which is a decision about URLs rather than a guard for the regex.
     *
     * Over the vocabulary's cases rather than over the registered routes, which is stricter in the
     * direction that matters: a case is a pattern whether or not anything has registered it yet,
     * and it is also what a view builds a link from.
     *
     * @return void
     */
    public function testEveryPatternIsPlainSegmentsAndPlaceholders(): void
    {
        foreach (SitePath::cases() as $path) {
            self::assertMatchesRegularExpression(
                '#^(/|(/[\w-]+|/\{\w+\})+)$#',
                $path->value,
                "{$path->name} contains something that is not a plain segment or a {placeholder}.",
            );
        }
    }

    /**
     * @return void
     */
    public function testToFillsPlaceholdersInOrder(): void
    {
        self::assertSame('/', SitePath::Home->to());
        self::assertSame('/releases', SitePath::Releases->to());
        self::assertSame('/releases/ill', SitePath::Release->to('ill'));
        self::assertSame('/releases/ill/flac', SitePath::Download->to('ill', 'flac'));
        self::assertSame('/demos/wna-bootleg/v4', SitePath::DemoAudio->to('wna-bootleg', 'v4'));
    }

    /**
     * Every path a view can build is one the router answers on. That is the whole reason the
     * patterns are an enum: one vocabulary for both, rather than two files that nothing compares.
     * The site's come first, in declaration order, and the framework's API last.
     *
     * @return void
     */
    public function testEveryDeclaredPathIsRegistered(): void
    {
        $registered = [];

        foreach (Site::current()->routeTable() as $route) {
            $registered[] = $route->path();
        }

        self::assertSame([...SitePath::cases(), ...ApiPath::cases()], $registered);
    }

    /**
     * @return iterable<array{string, class-string}>
     */
    public static function dispatchProvider(): iterable
    {
        yield ['/', HomeController::class];
        yield ['/releases', ReleasesController::class];
        yield ['/releases/ill', ReleaseController::class];
        yield ['/releases/ill/flac', DownloadController::class];
        yield ['/admin/stats', StatsController::class];
        yield ['/imprint', ImprintController::class];
        yield ['/privacy', PrivacyController::class];
    }

    /**
     * @param string $path
     * @param string $expected
     * @return void
     */
    #[DataProvider('dispatchProvider')]
    public function testTheRouteTableResolvesEachPathToItsController(string $path, string $expected): void
    {
        foreach (Site::current()->routeTable() as $route) {
            if (($params = $route->matches($path)) !== false) {
                self::assertInstanceOf($expected, $route->createController($params));
                return;
            }
        }

        self::fail("No route matched $path");
    }

    /**
     * '/releases' must be tried before '/releases/{slug}' or the listing page is unreachable.
     *
     * @return void
     */
    public function testStaticRoutesAreRegisteredBeforeTheirPlaceholderSiblings(): void
    {
        $matched = null;
        foreach (Site::current()->routeTable() as $route) {
            if ($route->matches('/releases') !== false) {
                $matched = $route->createController([]);
                break;
            }
        }

        self::assertInstanceOf(ReleasesController::class, $matched);
    }

    /**
     * @return iterable<array{string}>
     */
    public static function unmatchedProvider(): iterable
    {
        yield ['/nope'];
        yield ['/releases/ill/flac/extra'];
        yield ['/admin'];
        yield ['/admin/stats/extra'];
        yield ['/imprints'];

        // Every depth short of `/api`'s four segments, and one past it. The framework asserts its
        // own pattern matches none of these; over this table they also say no route of the site's
        // claims one, which is what keeps `/api` falling through to the same 404 as any other
        // address that is not there. See ApiPath::Api.
        yield ['/api'];
        yield ['/api/update'];
        yield ['/api/update/v1'];
        yield ['/api/update/v1/patch/extra'];
        yield ['/api/health'];
        yield ['/api/health/v1'];
        yield ['/api/health/v1/report/extra'];
    }

    /**
     * @param string $path
     * @return void
     */
    #[DataProvider('unmatchedProvider')]
    public function testUnknownPathsMatchNoRoute(string $path): void
    {
        foreach (Site::current()->routeTable() as $route) {
            self::assertFalse($route->matches($path), "$path unexpectedly matched a route");
        }
    }

    /**
     * The one read-only route without placeholders that is not a page. It is behind the admin
     * password, so a static export that asked it would be answered with a 401 — the route says it
     * has no pages instead.
     *
     * @return void
     */
    public function testTheStatsPageIsNeverExported(): void
    {
        $exported = [];

        foreach (Site::current()->routeTable() as $route) {
            if (!$route->exportedPaths()->isEmpty()) {
                $exported[] = $route->path()->value;
            }
        }

        self::assertNotContains(SitePath::Stats->value, $exported);
        self::assertContains(SitePath::Home->value, $exported, 'a route without an $exports closure is still a page');
    }

    /**
     * The stats page's password is its route's, not its controller's — so the route table is where
     * it is asserted, and it is the one route that carries a gate.
     *
     * @return void
     */
    public function testTheStatsPageIsTheOneRouteBehindTheAdminGate(): void
    {
        $gated = [];

        foreach (Site::current()->routeTable() as $route) {
            foreach ($route->layers() as $layer) {
                if ($layer instanceof AdminGate) {
                    $gated[] = $route->path();
                }
            }
        }

        self::assertSame([SitePath::Stats], $gated);
    }

    /**
     * `/api` is the one route that accepts a write method, and the only one.
     *
     * Asserted over the real table rather than by reading the registration, because what matters is
     * what the router will do and not what anybody wrote down. Both directions: a second route
     * accepting a write, and a second route made `Delegated`, are each a hole. A delegated route is
     * the one that accepts even a method nobody recognises, which a read-only one never does.
     *
     * @return void
     */
    public function testOnlyTheApiRouteAcceptsAWriteMethod(): void
    {
        $accepting = [];
        $delegated = [];

        foreach (Site::current()->routeTable() as $route) {
            if ($route->accepts(HttpMethod::Post)) {
                $accepting[] = $route->path();
            }

            if ($route->accepts(null)) {
                $delegated[] = $route->path();
            }
        }

        self::assertSame([ApiPath::Api], $accepting);
        self::assertSame([ApiPath::Api], $delegated, 'a second route stopped being method-gated');
    }
}
