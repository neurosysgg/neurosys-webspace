<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Config;
use NeuroSYS\DataFile;
use NeuroSYS\Exception\UpdateException;
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
     * The provider iterates the {@link DataFile} cases rather than listing names, because a list
     * written out here would have no way to notice a case arriving. So a case added without a
     * file — or a file added without a case — fails here rather than reading as an empty catalogue
     * on a page.
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

    // ───────────────────────────── the webroot ─────────────────────────────

    /**
     * The webroot is the one path here that cannot be derived, so it is asked for — and refused
     * rather than guessed.
     *
     * **This is the method that once emptied this repository**, so what each case asserts is worth
     * saying plainly. The directory is called `public/` here and `neurosys/` on the live host, and
     * nothing under `src/` can know that; `DOCUMENT_ROOT` does. Taking it whole does not work
     * either — the live host reports one directory under two different absolute paths, and a
     * mirror compares paths. So only the *basename* is taken, and the basename is only meaningful
     * once the two are known to be the same tree.
     *
     * @return void
     */
    public function testTheWebrootIsResolvedFromDocumentRoot(): void
    {
        self::assertSame(
            NEUROSYS_ROOT . '/public',
            self::withDocumentRoot(NEUROSYS_ROOT . '/public', static fn(): string => Config::webroot()->path),
        );
    }

    /**
     * A trailing slash is the shape a server is as likely to report as not.
     *
     * @return void
     */
    public function testTheWebrootIgnoresATrailingSlash(): void
    {
        self::assertSame(
            NEUROSYS_ROOT . '/public',
            self::withDocumentRoot(NEUROSYS_ROOT . '/public/', static fn(): string => Config::webroot()->path),
        );
    }

    /**
     * Absent, it stops. There is no default and there must not be one.
     *
     * @param string $root
     * @return void
     */
    #[DataProvider('absentDocumentRootProvider')]
    public function testAnAbsentDocumentRootIsRefusedRatherThanDefaulted(string $root): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('DOCUMENT_ROOT is not set');

        self::withDocumentRoot($root, static fn(): string => Config::webroot()->path);
    }

    /**
     * @return iterable
     */
    public static function absentDocumentRootProvider(): iterable
    {
        yield 'empty'      => [''];
        yield 'whitespace' => ['   '];
    }

    /**
     * A `DOCUMENT_ROOT` outside the deployment is refused, and this is the guard that matters most.
     *
     * A basename grafted onto a different tree names a real directory somewhere else. That is not
     * hypothetical: a test pointing `DOCUMENT_ROOT` at a sandbox whose last segment was `public`
     * got *this repository's* `public/` back, and the update mirror emptied it. `realpath()` on
     * both sides is what collapses the two spellings the live host reports for one directory, and
     * comparing them is what turns a plausible guess into a refusal.
     *
     * @param string $suffix
     * @return void
     */
    #[DataProvider('foreignDocumentRootProvider')]
    public function testADocumentRootOutsideTheDeploymentIsRefused(string $suffix): void
    {
        $sandbox = sys_get_temp_dir() . '/neurosys-webroot-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($sandbox . $suffix, 0o755, true));

        try {
            $this->expectException(UpdateException::class);
            $this->expectExceptionMessage('not a directory inside this deployment');

            self::withDocumentRoot($sandbox . $suffix, static fn(): string => Config::webroot()->path);
        } finally {
            @rmdir($sandbox . $suffix);
            @rmdir(dirname($sandbox . $suffix));
            @rmdir($sandbox);
        }
    }

    /**
     * @return iterable
     */
    public static function foreignDocumentRootProvider(): iterable
    {
        // The first is the exact shape that did the damage: a directory called `public`, somewhere
        // else entirely. The second is a name this deployment has no directory for at all.
        yield 'named public elsewhere' => ['/public'];
        yield 'named anything else'    => ['/htdocs'];
    }

    /**
     * A path that does not exist cannot be shown to be inside the deployment, so it is not.
     *
     * @return void
     */
    public function testADocumentRootThatDoesNotExistIsRefused(): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('not a directory inside this deployment');

        self::withDocumentRoot(
            NEUROSYS_ROOT . '/no-such-directory/public',
            static fn(): string => Config::webroot()->path,
        );
    }

    /**
     * A name whose *parent* is the deployment but which is not there is refused too.
     *
     * The containment check above is satisfied by this — its parent really is the deployment — so
     * without a second question it resolves to a `Directory` that does not exist, and the first
     * push would create it and write the whole webroot into it beside the real one, served by
     * nothing. The two checks ask different things and both are needed.
     *
     * @return void
     */
    public function testADocumentRootNamingNoDirectoryIsRefused(): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('names no directory');

        self::withDocumentRoot(
            NEUROSYS_ROOT . '/not-a-real-webroot',
            static fn(): string => Config::webroot()->path,
        );
    }

    /**
     * A relative `DOCUMENT_ROOT` is refused rather than resolved against the working directory.
     *
     * The containment check reasons about `dirname($root)`, which for a bare name is `.` — whose
     * realpath is the cwd, and under this very runner the cwd *is* the deployment. So without the
     * absolute check a relative value would pass containment and graft its basename onto the
     * deployment, the one confusion an absolute path cannot cause. A real server never reports one.
     *
     * @return void
     */
    public function testARelativeDocumentRootIsRefused(): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('not an absolute path');

        self::withDocumentRoot('public', static fn(): string => Config::webroot()->path);
    }

    /**
     * The replay serial sits above the webroot, in neither tree a push mirrors and in no rsync.
     *
     * @return void
     */
    public function testTheUpdateSerialSitsAboveTheWebroot(): void
    {
        self::assertSame(NEUROSYS_ROOT . '/.update-serial', Config::updateSerial()->path);
        self::assertSame(NEUROSYS_ROOT, Config::above()->path);
    }

    /**
     * Runs $body with `DOCUMENT_ROOT` set to $root, and puts the superglobal back either way.
     *
     * @param string $root
     * @param callable(): string $body
     * @return string
     */
    private static function withDocumentRoot(string $root, callable $body): string
    {
        $previous = $_SERVER['DOCUMENT_ROOT'] ?? null;
        $_SERVER['DOCUMENT_ROOT'] = $root;

        try {
            return $body();
        } finally {
            if ($previous === null) {
                unset($_SERVER['DOCUMENT_ROOT']);
            } else {
                $_SERVER['DOCUMENT_ROOT'] = $previous;
            }
        }
    }
}
