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
use NeuroSYS\Exception\RouteException;
use NeuroSYS\Support\Route;
use NeuroSYS\Support\RouteInitialization;
use NeuroSYS\Support\SitePath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(Route::class)]
#[CoversClass(RouteInitialization::class)]
#[CoversClass(SitePath::class)]
final class RoutingTest extends TestCase
{
    /**
     * @return void
     */
    public function testStaticPatternMatchesExactlyAndCapturesNothing(): void
    {
        $route = new Route(SitePath::Releases, fn() => new ReleasesController());

        self::assertSame([], $route->matches('/releases'));
        self::assertFalse($route->matches('/releases/ill'));
        self::assertFalse($route->matches('/release'));
        self::assertFalse($route->matches('/'));
    }

    /**
     * @return void
     */
    public function testPlaceholderCapturesOneSegment(): void
    {
        $route = new Route(SitePath::Release, fn($slug) => new ReleaseController($slug));

        self::assertSame(['ill'], $route->matches('/releases/ill'));
        self::assertSame(['hello-world'], $route->matches('/releases/hello-world'));
    }

    /**
     * @return void
     */
    public function testPlaceholderDoesNotSpanASlash(): void
    {
        $route = new Route(SitePath::Release, fn($slug) => new ReleaseController($slug));

        self::assertFalse($route->matches('/releases/ill/flac'));
    }

    /**
     * @return void
     */
    public function testMultiplePlaceholdersCaptureInOrder(): void
    {
        $route = new Route(
            SitePath::Download,
            fn($slug, $format) => new DownloadController($slug, $format),
        );

        self::assertSame(['ill', 'flac'], $route->matches('/releases/ill/flac'));
    }

    /**
     * @return void
     */
    public function testEmptySegmentDoesNotMatchAPlaceholder(): void
    {
        $route = new Route(SitePath::Release, fn($slug) => new ReleaseController($slug));

        self::assertFalse($route->matches('/releases/'));
    }

    /**
     * @return void
     */
    public function testFactoryReceivesTheCapturedParams(): void
    {
        $route = new Route(
            SitePath::Download,
            fn($slug, $format) => new DownloadController($slug, $format),
        );

        self::assertInstanceOf(
            DownloadController::class,
            $route->createController($route->matches('/releases/ill/flac') ?: []),
        );
    }

    /**
     * The pattern is interpolated straight into a regex, so a literal that happens to be
     * a metacharacter would silently become a wildcard. Every pattern must therefore stay
     * metacharacter-free — this asserts that, rather than the escaping.
     *
     * Over {@link SitePath::cases()} rather than over the registered routes, which is stricter in
     * the direction that matters now: a case is a pattern whether or not anything has registered
     * it yet, and it is also what a view builds a link from.
     *
     * @return void
     */
    public function testEveryPatternIsFreeOfRegexMetacharacters(): void
    {
        foreach (SitePath::cases() as $path) {
            self::assertMatchesRegularExpression(
                '#^(/|(/[\w-]+|/\{\w+\})+)$#',
                $path->value,
                "SitePath::{$path->name} contains something that is not a plain segment "
                . 'or a {placeholder}; Route::matches() does not preg_quote it.',
            );
        }
    }

    /**
     * @return void
     */
    public function testEveryRegisteredRouteUsesADeclaredPath(): void
    {
        foreach (RouteInitialization::routes() as $route) {
            self::assertInstanceOf(
                SitePath::class,
                new ReflectionProperty(Route::class, 'pattern')->getValue($route),
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
     * The mistake an arrow function makes here: `fn()` captures by value, so a shift inside it
     * leaves the outer list untouched and every placeholder is filled with the first value —
     * `/releases/ill/ill`, which is well formed, matches a route, and is the wrong page.
     *
     * @return void
     */
    public function testToDoesNotRepeatTheFirstValue(): void
    {
        self::assertSame('/releases/ill/wav', SitePath::Download->to('ill', 'wav'));
    }

    /**
     * @return void
     */
    public function testToEncodesEachValueAsOneSegment(): void
    {
        self::assertSame('/releases/hello%20world', SitePath::Release->to('hello world'));
        self::assertSame('/releases/a%2Fb', SitePath::Release->to('a/b'));
    }

    /**
     * @return void
     */
    public function testToRefusesTooFewValues(): void
    {
        $this->expectException(RouteException::class);
        $this->expectExceptionMessage('SitePath::Download takes 2 value(s)');

        SitePath::Download->to('ill');
    }

    /**
     * @return void
     */
    public function testToRefusesTooManyValues(): void
    {
        $this->expectException(RouteException::class);
        $this->expectExceptionMessage('SitePath::Home takes 0 value(s)');

        SitePath::Home->to('ill');
    }

    /**
     * Every path a view can build is one the router answers on. That is the whole reason the
     * patterns are an enum: the two used to be written in different files and nothing compared
     * them.
     *
     * @return void
     */
    public function testEveryDeclaredPathIsRegistered(): void
    {
        $registered = [];

        foreach (RouteInitialization::routes() as $route) {
            $registered[] = new ReflectionProperty(Route::class, 'pattern')->getValue($route);
        }

        self::assertSame(SitePath::cases(), $registered);
    }

    /**
     * @return iterable
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
        foreach (RouteInitialization::routes() as $route) {
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
        foreach (RouteInitialization::routes() as $route) {
            if ($route->matches('/releases') !== false) {
                $matched = $route->createController([]);
                break;
            }
        }

        self::assertInstanceOf(ReleasesController::class, $matched);
    }

    /**
     * @return iterable
     */
    public static function unmatchedProvider(): iterable
    {
        yield ['/nope'];
        yield ['/releases/ill/flac/extra'];
        yield ['/admin'];
        yield ['/admin/stats/extra'];
        yield ['/imprints'];
    }

    /**
     * @param string $path
     * @return void
     */
    #[DataProvider('unmatchedProvider')]
    public function testUnknownPathsMatchNoRoute(string $path): void
    {
        foreach (RouteInitialization::routes() as $route) {
            self::assertFalse($route->matches($path), "$path unexpectedly matched a route");
        }
    }

    /**
     * A placeholder matches anything at all, malformed included — which is the fact
     * {@link \NeuroSYS\Http\Request::normalisePath()} spent a paragraph assuming the opposite of.
     *
     * That docblock argued its verbatim fallback was safe because "no route pattern matches a
     * malformed target, so handing it through unchanged 404s the way every other unknown path
     * does". `{slug}` compiles to `([^/]+)`, so a raw `"` matches like any other byte and the demo
     * route claimed it — and since the fallback was the whole target rather than its path, a query
     * string arrived inside the captured slug. That slug names a demo's `WWW-Authenticate` realm.
     *
     * Pinned here rather than only over there because it is a fact about **this** class: the claim
     * was written in a file that does not import `Route`, and checking it was one `matches()` call.
     * The row is the check nobody made.
     *
     * @return void
     */
    public function testAMalformedTargetStillMatchesAPlaceholderRoute(): void
    {
        $demo = new Route(SitePath::Demo, static fn(string $slug): HomeController => new HomeController());

        self::assertSame(['a"b'], $demo->matches('/demos/a"b'));
        self::assertSame(['x?a=1'], $demo->matches('/demos/x?a=1'));
    }
}
