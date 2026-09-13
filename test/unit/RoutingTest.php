<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Controller\DownloadController;
use NeuroSYS\Controller\HomeController;
use NeuroSYS\Controller\ImprintController;
use NeuroSYS\Controller\PrivacyController;
use NeuroSYS\Controller\ReleaseController;
use NeuroSYS\Controller\ReleasesController;
use NeuroSYS\Site;
use NeuroSYS\Support\RouteInitialization;
use NeuroSYS\Support\SitePath;
use Phpanta\App;
use Phpanta\Http\HttpMethod;
use Phpanta\Service\Layer\AdminGate;
use Phpanta\Support\AdminPath;
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
#[CoversClass(AdminPath::class)]
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
     * The site's come first, in declaration order, and the framework's admin last.
     *
     * @return void
     */
    public function testEveryDeclaredPathIsRegistered(): void
    {
        $registered = [];

        foreach (Site::current()->routeTable() as $route) {
            $registered[] = $route->path();
        }

        self::assertSame([...SitePath::cases(), ...AdminPath::cases()], $registered);
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
        yield ['/imprints'];

        // One past the admin's four depths, and every depth of `/api`, where the admin used to be.
        // The framework asserts its own patterns match none of these; over this table they also say
        // no route of the site's claims one, so `/api` falls through to the same 404 as any other
        // address that is not there. See AdminPath.
        yield ['/admin/update/v1/patch/extra'];
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
     * The admin's entrance is the one route without placeholders that is not a page: an export asks
     * anonymously, and the admin has nothing to show an anonymous visitor — the route says it has no
     * pages instead.
     *
     * @return void
     */
    public function testTheAdminIsNeverExported(): void
    {
        $exported = [];

        foreach (Site::current()->routeTable() as $route) {
            if (!$route->exportedPaths()->isEmpty()) {
                $exported[] = $route->path()->value;
            }
        }

        self::assertNotContains(AdminPath::Index->value, $exported);
        self::assertContains(SitePath::Home->value, $exported, 'a route without an $exports closure is still a page');
    }

    /**
     * No route carries the Basic admin gate: the page it stood in front of went when the admin moved
     * to `/admin`, and a gate is its route's — so the route table is where its absence is asserted.
     *
     * @return void
     */
    public function testNoRouteCarriesTheBasicAdminGate(): void
    {
        $gated = [];

        foreach (Site::current()->routeTable() as $route) {
            foreach ($route->layers() as $layer) {
                if ($layer instanceof AdminGate) {
                    $gated[] = $route->path();
                }
            }
        }

        self::assertSame([], $gated);
    }

    /**
     * The admin's routes are the ones that accept a write method, and the only ones.
     *
     * Asserted over the real table rather than by reading the registration, because what matters is
     * what the router will do and not what anybody wrote down. Both directions: a second route
     * accepting a write, and a second route made `Delegated`, are each a hole. A delegated route is
     * the one that accepts even a method nobody recognises, which a read-only one never does.
     *
     * @return void
     */
    public function testOnlyTheAdminRoutesAcceptAWriteMethod(): void
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

        self::assertSame(AdminPath::cases(), $accepting);
        self::assertSame(AdminPath::cases(), $delegated, 'another route stopped being method-gated');
    }
}
