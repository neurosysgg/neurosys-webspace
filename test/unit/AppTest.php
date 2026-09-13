<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\AssetManifest;
use NeuroSYS\DataFile;
use NeuroSYS\Site;
use Phpanta\App;
use Phpanta\CredentialFile;
use Phpanta\DataFileName;
use Phpanta\Http\Security\CspDirective;
use Phpanta\Http\Security\CspHost;
use Phpanta\Http\Security\CspSource;
use Phpanta\Http\ServerVariable;
use Phpanta\Model\Health\Requirement;
use Phpanta\Support\RequirementInitialization;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The facts about this site, and the paths the booted app derives from them.
 *
 * The {@link Site}'s constants are each one two files would otherwise have a copy of, so what is
 * worth asserting is not the value but that the readers still agree with it — a bare origin the
 * CSP will accept, an asset path that resolves to a file, a data directory that lands outside the
 * webroot, a data file that is where the app says it is.
 *
 * What the framework does with any app — one per process, the webroot's refusals, the paths it
 * derives — is the framework's own `AppTest`, under its test app. What stays here is what only
 * this site's answers make true: that its deployment is this repository, its webroot `public/`.
 */
#[CoversClass(App::class)]
#[CoversClass(Site::class)]
#[CoversClass(DataFile::class)]
#[CoversClass(CredentialFile::class)]
final class AppTest extends TestCase
{
    // ───────────────────────────── paths ─────────────────────────────

    /**
     * @return void
     */
    public function testDataPathResolvesInsideTheRepositoryDataDirectory(): void
    {
        self::assertSame(NEUROSYS_ROOT . '/data/releases.php', Site::current()->dataFile(DataFile::Releases)->path);
    }

    /**
     * @return void
     */
    public function testDataPathTakesANestedFile(): void
    {
        self::assertSame(
            NEUROSYS_ROOT . '/data/logs/downloads.log',
            Site::current()->dataFile(DataFile::DownloadLog)->path,
        );
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
        $data = Site::current()->data();

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
        self::assertSame(Site::current()->dataFile(DataFile::DownloadLog)->path, Site::current()->downloadLog()->path);
    }

    /**
     * The error log sits beside the download log, in the one directory the site writes into, and
     * is this month's file.
     *
     * @return void
     */
    public function testTheErrorLogIsThisMonthsFileInTheLogDirectory(): void
    {
        $site = Site::current();

        self::assertSame(NEUROSYS_ROOT . '/data/logs', $site->logs()->path);
        self::assertSame($site->downloadLog()->directory()->path, $site->logs()->path);
        self::assertSame($site->logs()->file('php-' . date('Y-m') . '.log')->path, $site->errorLog()->path);
    }

    /**
     * Every data file the application actually loads has to be one dataFile() resolves.
     *
     * The provider iterates the {@link DataFile} cases rather than listing names, because a list
     * written out here would have no way to notice a case arriving. So a case added without a
     * file — or a file added without a case — fails here rather than reading as an empty catalogue
     * on a page.
     *
     * @param DataFileName $file
     * @return void
     */
    #[DataProvider('dataFileProvider')]
    public function testTheDataFilesTheSiteLoadsAreWhereDataPathSaysTheyAre(DataFileName $file): void
    {
        self::assertTrue(
            Site::current()->dataFile($file)->exists(),
            $file->value . ' should be where dataFile() says',
        );
    }

    /**
     * The tracked cases, which are the ones a clone is guaranteed to have.
     *
     * @return iterable<string, array{DataFileName}>
     */
    public static function dataFileProvider(): iterable
    {
        foreach (Site::current()->dataFiles() as $file) {
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
        foreach (Site::current()->dataFiles() as $file) {
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

    /**
     * The framework's files and the site's are two vocabularies over one directory, so a name both
     * declared would be one file with two meanings — and whichever reader asked second would be
     * reading the other one's credentials, or its catalogue.
     *
     * @return void
     */
    public function testNoTwoDataFilesShareAName(): void
    {
        $names = Site::current()->dataFiles()->map(static fn(DataFileName $file): string => (string) $file->value);

        self::assertSame($names->toValues(), $names->unique()->toValues());
        self::assertSame(count(CredentialFile::cases()) + count(DataFile::cases()), $names->count());
    }

    // ───────────────────────────── what a site may add ─────────────────────────────

    /**
     * What `health v1` checks is the framework's floor with the site's own on top — and this site
     * adds none, so the two are the same list.
     *
     * @return void
     */
    public function testTheRequirementsAreTheFrameworksFloorWhenTheSiteAddsNone(): void
    {
        // By name: a requirement can hold the closure that checks it, and closures do not compare.
        $names = static fn(Requirement $requirement): string => $requirement->name();

        self::assertSame(
            RequirementInitialization::requirements(Site::current())->map($names)->toValues(),
            Site::current()->requirements()->map($names)->toValues(),
        );
    }

    /**
     * This site's two hosts, each under the one directive it needs, and nothing anywhere else — a
     * host added to a directive it does not need widens the policy for nothing.
     *
     * @return void
     */
    public function testTheSitesHostsGoWhereTheyAreNeededAndNowhereElse(): void
    {
        foreach (CspDirective::cases() as $directive) {
            $hosts = Site::current()->contentHosts($directive)
                ->map(static fn(CspSource $source): string => $source->source())
                ->toValues();

            self::assertSame(
                match ($directive) {
                    CspDirective::ImgSrc   => [Site::FILE_HOST],
                    CspDirective::FrameSrc => [Site::PLAYER_HOST],
                    default                => [],
                },
                $hosts,
                $directive->value,
            );
        }
    }

    /**
     * The build `update v1 version` reports is the entry script's stamped URL — the stamp changes
     * exactly when a build does.
     *
     * @return void
     */
    public function testTheBuildIsTheEntryScriptsStamp(): void
    {
        self::assertSame(AssetManifest::SCRIPT, Site::current()->buildId());
    }

    // ───────────────────────────── identity ─────────────────────────────

    /**
     * {@link \NeuroSYS\View\Wordmark} splits the name on its first dot and accents it, so a name
     * with no dot would render as the whole name and an empty second half — a wordmark that is
     * a lookalike of the site's own name, which is the thing Wordmark exists to prevent.
     *
     * @return void
     */
    public function testTheNameCarriesTheDotTheWordmarkSplitsOn(): void
    {
        self::assertStringContainsString('.', Site::NAME);
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
        yield 'file host'   => [Site::FILE_HOST];
        yield 'player host' => [Site::PLAYER_HOST];
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
        yield 'stylesheet'  => [Site::STYLESHEET];
        yield 'script'      => [Site::SCRIPT];
        yield 'placeholder' => [Site::COVER_PLACEHOLDER];
    }

    // ───────────────────────────── the deployment ─────────────────────────────

    /**
     * This site's webroot is `public/`, resolved from the `DOCUMENT_ROOT` a server reports for it.
     *
     * Every refusal around this — blank, relative, a dot segment, outside the deployment, naming
     * nothing — is the framework's `AppTest`, against its fixture deployment. What only this suite
     * can say is that this repository's own layout passes them.
     *
     * @return void
     */
    public function testTheWebrootIsResolvedFromDocumentRoot(): void
    {
        $key      = ServerVariable::DocumentRoot->value;
        $previous = $_SERVER[$key] ?? null;

        // webroot() reads the process's own server variables rather than a request's, so this is
        // the one variable a test still sets — and puts back.
        $_SERVER[$key] = NEUROSYS_ROOT . '/public';

        try {
            self::assertSame(NEUROSYS_ROOT . '/public', Site::current()->webroot()->path);
        } finally {
            if ($previous === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $previous;
            }
        }
    }

    /**
     * The replay serial sits above the webroot, in neither tree a push mirrors and in no rsync.
     *
     * @return void
     */
    public function testTheUpdateSerialSitsAboveTheWebroot(): void
    {
        self::assertSame(NEUROSYS_ROOT . '/.update-serial', Site::current()->updateSerial()->path);
        self::assertSame(NEUROSYS_ROOT, Site::current()->above()->path);
    }

    /**
     * The deployment directory is the one the autoloader sits in, whatever the framework's own files
     * sit in. The update serial and the push's mirror both hang off it, so an `above()` that drifted
     * — into `phpanta/`, say — would move the serial and reset replay protection with nothing to
     * say so.
     *
     * @return void
     */
    public function testTheDeploymentIsTheDirectoryHoldingTheAutoloader(): void
    {
        $above = Site::current()->above();

        self::assertSame(realpath(NEUROSYS_ROOT), realpath($above->path));
        self::assertTrue($above->file('autoload.php')->exists());
    }

    /**
     * The app the bootstrap booted is the site, and it is named for it.
     *
     * @return void
     */
    public function testTheBootedAppIsTheSiteAndNamedForIt(): void
    {
        self::assertSame(Site::current(), App::current());
        self::assertSame(Site::NAME, App::current()->name());
    }
}
