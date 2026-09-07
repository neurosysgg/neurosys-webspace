<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Config;
use NeuroSYS\DataFile;
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
#[CoversClass(DataFile::class)]
final class ConfigTest extends TestCase
{
    // ───────────────────────────── paths ─────────────────────────────

    /**
     * @return void
     */
    public function testDataPathResolvesInsideTheRepositoryDataDirectory(): void
    {
        self::assertSame(NEUROSYS_ROOT . '/data/releases.php', Config::dataFile(DataFile::Releases)->path);
    }

    /**
     * @return void
     */
    public function testDataPathTakesANestedFile(): void
    {
        self::assertSame(NEUROSYS_ROOT . '/data/logs/downloads.log', Config::dataFile(DataFile::DownloadLog)->path);
    }

    /**
     * The one derivation of that path instead of seven, and the reason it is worth one: the
     * credentials live there. `data/` is uploaded separately and must never resolve to somewhere
     * Apache serves — a `dirname()` off by one level would put admin.php under the webroot.
     *
     * @return void
     */
    public function testTheDataDirectoryIsOutsideTheWebroot(): void
    {
        $data = Config::data();

        self::assertTrue($data->exists());
        self::assertStringStartsNotWith(NEUROSYS_ROOT . '/public/', $data->path);
    }

    /**
     * Both classes that reach for the log have to reach for the same file.
     *
     * @return void
     */
    public function testTheDownloadLogIsNamedRelativeToTheDataDirectory(): void
    {
        self::assertSame(Config::dataFile(DataFile::DownloadLog)->path, Config::downloadLog()->path);
    }

    /**
     * Every data file the application actually loads has to be one dataFile() resolves.
     *
     * The provider used to be four names written out here, which is the arrangement
     * {@link DataFile} was extracted from: it listed the four the repository carries, said nothing
     * about the three it does not, and had no way to notice a fifth arriving. Iterating the cases
     * asks the enum instead, so a case added without a file — or a file added without a case —
     * fails here rather than reading as an empty catalogue on a page.
     *
     * @param DataFile $file
     * @return void
     */
    #[DataProvider('dataFileProvider')]
    public function testTheDataFilesTheSiteLoadsAreWhereDataPathSaysTheyAre(DataFile $file): void
    {
        self::assertTrue(
            Config::dataFile($file)->exists(),
            $file->value . ' should be where dataFile() says',
        );
    }

    /**
     * The tracked cases, which are the ones a clone is guaranteed to have.
     *
     * @return iterable<string, array{DataFile}>
     */
    public static function dataFileProvider(): iterable
    {
        foreach (DataFile::cases() as $file) {
            if ($file->isTracked()) {
                yield $file->name => [$file];
            }
        }
    }

    /**
     * The other side of {@link DataFile::isTracked()}, and the half a list of names cannot state.
     *
     * An untracked case is untracked for a reason that is the point of it: two are gitignored so a
     * public repository cannot publish what they hold, and the log does not exist until something
     * writes one. What is asserted is that the predicate agrees with git rather than with a comment
     * — so moving a file into or out of the repository fails here until the case is told.
     *
     * @return void
     */
    public function testWhetherADataFileIsTrackedAgreesWithTheRepository(): void
    {
        foreach (DataFile::cases() as $file) {
            $tracked = exec(sprintf(
                'git -C %s ls-files --error-unmatch -- %s 2>/dev/null',
                escapeshellarg(NEUROSYS_ROOT),
                escapeshellarg('data/' . $file->value),
            )) !== '';

            self::assertSame(
                $file->isTracked(),
                $tracked,
                $file->value . ' is ' . ($tracked ? '' : 'not ') . 'in git, and isTracked() disagrees',
            );
        }
    }

    // ───────────────────────────── identity ─────────────────────────────

    /**
     * @return void
     */
    public function testTheDescriptionIsTheNameAndTheTagline(): void
    {
        self::assertSame('neuro.SYS — electronic music.', Config::description());
    }

    /**
     * {@link \NeuroSYS\View\Wordmark} splits the name on its first dot and accents it, so a name
     * with no dot would render as the whole name and an empty second half — a wordmark that is
     * a lookalike of the site's own name, which is the thing Wordmark exists to prevent.
     *
     * @return void
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
     *
     * @param string $origin
     * @return void
     */
    #[DataProvider('originProvider')]
    public function testEveryThirdPartyOriginIsOneTheCspWillAccept(string $origin): void
    {
        self::assertSame($origin, new CspHost($origin)->source());
    }

    /**
     * @return iterable
     */
    public static function originProvider(): iterable
    {
        yield 'file host'   => [Config::FILE_HOST];
        yield 'player host' => [Config::PLAYER_HOST];
    }

    // ───────────────────────────── assets ─────────────────────────────

    /**
     * A mistyped asset path is a 404 nothing reports: the page still renders, unstyled or
     * without its script, and only the browser's network tab says so.
     *
     * @param string $path
     * @return void
     */
    #[DataProvider('assetProvider')]
    public function testEveryAssetPathIsSameOriginAndResolvesToAFile(string $path): void
    {
        self::assertStringStartsWith('/', $path);
        self::assertFileExists(NEUROSYS_ROOT . '/public' . $path);
    }

    /**
     * @return iterable
     */
    public static function assetProvider(): iterable
    {
        yield 'stylesheet'  => [Config::STYLESHEET];
        yield 'script'      => [Config::SCRIPT];
        yield 'placeholder' => [Config::COVER_PLACEHOLDER];
    }
}
