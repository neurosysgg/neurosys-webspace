<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Config;
use NeuroSYS\Http\Security\CspHost;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The facts about this site. A constant here is one two files would otherwise each have a copy
 * of, so what is worth asserting is not the value but that the readers still agree with it —
 * a bare origin the CSP will accept, an asset path that resolves to a file, a data directory
 * that lands outside the webroot.
 */
#[CoversClass(Config::class)]
final class ConfigTest extends TestCase
{
    // ───────────────────────────── paths ─────────────────────────────

    public function testDataPathResolvesInsideTheRepositoryDataDirectory(): void
    {
        self::assertSame(NEUROSYS_ROOT . '/data/releases.php', Config::dataPath('releases.php'));
    }

    public function testDataPathTakesANestedFile(): void
    {
        self::assertSame(NEUROSYS_ROOT . '/data/logs/downloads.log', Config::dataPath('logs/downloads.log'));
    }

    /**
     * The one derivation of that path instead of seven, and the reason it is worth one: the
     * credentials live there. `data/` is uploaded separately and must never resolve to somewhere
     * Apache serves — a `dirname()` off by one level would put admin.php under the webroot.
     */
    public function testTheDataDirectoryIsOutsideTheWebroot(): void
    {
        $data = Config::dataPath('');

        self::assertDirectoryExists($data);
        self::assertStringStartsNotWith(NEUROSYS_ROOT . '/public/', $data);
    }

    /** Both classes that reach for the log have to reach for the same file. */
    public function testTheDownloadLogIsNamedRelativeToTheDataDirectory(): void
    {
        self::assertSame(Config::dataPath('logs/downloads.log'), Config::downloadLog());
    }

    /** Every data file the application actually loads has to be one dataPath() resolves. */
    #[DataProvider('dataFileProvider')]
    public function testTheDataFilesTheSiteLoadsAreWhereDataPathSaysTheyAre(string $file): void
    {
        self::assertFileExists(Config::dataPath($file));
    }

    public static function dataFileProvider(): iterable
    {
        yield 'catalogue' => ['releases.php'];
        yield 'profiles'  => ['profiles.php'];
        yield 'admin'     => ['admin.php'];
        yield 'privacy'   => ['privacy.html'];
    }

    // ───────────────────────────── identity ─────────────────────────────

    public function testTheDescriptionIsTheNameAndTheTagline(): void
    {
        self::assertSame('neuro.SYS — electronic music.', Config::description());
    }

    /**
     * {@link \NeuroSYS\View\Wordmark} splits the name on its first dot and accents it, so a name
     * with no dot would render as the whole name and an empty second half — a wordmark that is
     * a lookalike of the site's own name, which is the thing Wordmark exists to prevent.
     */
    public function testTheNameCarriesTheDotTheWordmarkSplitsOn(): void
    {
        self::assertStringContainsString('.', Config::NAME);
    }

    // ───────────────────── third-party origins ─────────────────────

    /**
     * Both origins are read twice: once to build a URL, once by the CSP that has to allow it.
     * A path or a trailing slash on either is a directive the browser drops on the floor while
     * the URLs stay perfectly valid — covers that load right up until the policy blocks them.
     */
    #[DataProvider('originProvider')]
    public function testEveryThirdPartyOriginIsOneTheCspWillAccept(string $origin): void
    {
        self::assertSame($origin, new CspHost($origin)->source());
    }

    public static function originProvider(): iterable
    {
        yield 'file host'   => [Config::FILE_HOST];
        yield 'player host' => [Config::PLAYER_HOST];
    }

    // ───────────────────────────── assets ─────────────────────────────

    /**
     * A mistyped asset path is a 404 nothing reports: the page still renders, unstyled or
     * without its script, and only the browser's network tab says so.
     */
    #[DataProvider('assetProvider')]
    public function testEveryAssetPathIsSameOriginAndResolvesToAFile(string $path): void
    {
        self::assertStringStartsWith('/', $path);
        self::assertFileExists(NEUROSYS_ROOT . '/public' . $path);
    }

    public static function assetProvider(): iterable
    {
        yield 'stylesheet'  => [Config::STYLESHEET];
        yield 'script'      => [Config::SCRIPT];
        yield 'placeholder' => [Config::COVER_PLACEHOLDER];
    }
}
