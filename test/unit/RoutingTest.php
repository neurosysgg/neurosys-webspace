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
use NeuroSYS\Support\Route;
use NeuroSYS\Support\RouteInitialization;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(Route::class)]
#[CoversClass(RouteInitialization::class)]
final class RoutingTest extends TestCase
{
    /**
     * @return void
     */
    public function testStaticPatternMatchesExactlyAndCapturesNothing(): void
    {
        $route = new Route('/releases', fn() => new ReleasesController());

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
        $route = new Route('/releases/{slug}', fn($slug) => new ReleaseController($slug));

        self::assertSame(['ill'], $route->matches('/releases/ill'));
        self::assertSame(['hello-world'], $route->matches('/releases/hello-world'));
    }

    /**
     * @return void
     */
    public function testPlaceholderDoesNotSpanASlash(): void
    {
        $route = new Route('/releases/{slug}', fn($slug) => new ReleaseController($slug));

        self::assertFalse($route->matches('/releases/ill/flac'));
    }

    /**
     * @return void
     */
    public function testMultiplePlaceholdersCaptureInOrder(): void
    {
        $route = new Route(
            '/releases/{slug}/{format}',
            fn($slug, $format) => new DownloadController($slug, $format),
        );

        self::assertSame(['ill', 'flac'], $route->matches('/releases/ill/flac'));
    }

    /**
     * @return void
     */
    public function testEmptySegmentDoesNotMatchAPlaceholder(): void
    {
        $route = new Route('/releases/{slug}', fn($slug) => new ReleaseController($slug));

        self::assertFalse($route->matches('/releases/'));
    }

    /**
     * @return void
     */
    public function testFactoryReceivesTheCapturedParams(): void
    {
        $route = new Route(
            '/releases/{slug}/{format}',
            fn($slug, $format) => new DownloadController($slug, $format),
        );

        self::assertInstanceOf(
            DownloadController::class,
            $route->createController($route->matches('/releases/ill/flac') ?: []),
        );
    }

    /**
     * The pattern is interpolated straight into a regex, so a literal that happens to be
     * a metacharacter would silently become a wildcard. Every registered pattern must
     * therefore stay metacharacter-free — this asserts that, rather than the escaping.
     *
     * @return void
     */
    public function testEveryRegisteredPatternIsFreeOfRegexMetacharacters(): void
    {
        foreach (RouteInitialization::routes() as $route) {
            $pattern = new ReflectionProperty(Route::class, 'pattern')->getValue($route);
            self::assertMatchesRegularExpression(
                '#^(/|(/[\w-]+|/\{\w+\})+)$#',
                $pattern,
                "Route pattern '$pattern' contains something that is not a plain segment "
                . 'or a {placeholder}; Route::matches() does not preg_quote it.',
            );
        }
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
}
