<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Model\Platform;
use NeuroSYS\Model\Release;
use NeuroSYS\Service\DownloadLogEntry;
use NeuroSYS\Service\DownloadLogger;
use NeuroSYS\Model\Profile;
use NeuroSYS\Service\ProfileRepository;
use NeuroSYS\Service\ReleaseRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReleaseRepository::class)]
#[CoversClass(ProfileRepository::class)]
#[CoversClass(DownloadLogger::class)]
#[CoversClass(DownloadLogEntry::class)]
final class ServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/neurosys-test-' . bin2hex(random_bytes(6)) . '.php';
    }

    protected function tearDown(): void
    {
        if (is_file($this->tmp)) {
            unlink($this->tmp);
        }
    }

    private function dataFile(string $php): string
    {
        file_put_contents($this->tmp, "<?php\nreturn $php;\n");
        return $this->tmp;
    }

    // ───────────────────────── ReleaseRepository ─────────────────────────

    public function testLoadsTheRealCatalogue(): void
    {
        $releases = new ReleaseRepository()->all();

        self::assertGreaterThan(0, $releases->count());
        self::assertSame(Release::class, $releases->type);
    }

    public function testFindsAReleaseBySlug(): void
    {
        self::assertInstanceOf(Release::class, new ReleaseRepository()->find('hello-world'));
    }

    public function testReturnsNullForAnUnknownSlug(): void
    {
        self::assertNull(new ReleaseRepository()->find('does-not-exist'));
    }

    public function testTheCatalogueIsOnlyReadOnce(): void
    {
        $repository = new ReleaseRepository();

        self::assertSame($repository->all(), $repository->all());
    }

    /** Every slug has to survive a round trip through a URL unencoded. */
    public function testEverySlugIsUrlSafe(): void
    {
        foreach (new ReleaseRepository()->all() as $slug => $_) {
            self::assertMatchesRegularExpression('/^[a-z0-9-]+$/', $slug, "slug '$slug' is not URL-safe");
        }
    }

    /** Every release the catalogue ships must actually render. */
    public function testEveryReleaseInTheCatalogueIsWellFormed(): void
    {
        foreach (new ReleaseRepository()->all() as $slug => $release) {
            self::assertNotSame('', $release->title, "$slug has no title");
            self::assertGreaterThan(0, $release->bpm, "$slug has no bpm");
            self::assertGreaterThan(0, $release->formats->count(), "$slug has no formats");
        }
    }

    // ───────────────────────── ProfileRepository ─────────────────────────

    public function testSkipsPlatformsWithNoUrl(): void
    {
        $file = $this->dataFile("['spotify' => '', 'github' => 'https://github.com/x']");

        $links = new ProfileRepository($file)->all();

        self::assertCount(1, $links);
        self::assertSame(Platform::GitHub, $links->all()[0]->platform);
    }

    public function testReturnsNothingWhenNoProfilesAreConfigured(): void
    {
        self::assertCount(0, new ProfileRepository($this->dataFile('[]'))->all());
    }

    public function testReturnsNothingWhenTheDataFileIsMissing(): void
    {
        self::assertCount(0, new ProfileRepository('/nonexistent/profiles.php')->all());
    }

    /** SoundCloud is the primary presence and renders first — that is enum declaration order. */
    public function testLinksComeBackInEnumDeclarationOrderNotFileOrder(): void
    {
        $file = $this->dataFile(
            "['github' => 'https://g', 'soundcloud' => 'https://s', 'youtube' => 'https://y']",
        );

        $order = array_map(
            static fn(Profile $p): Platform => $p->platform,
            new ProfileRepository($file)->all()->all(),
        );

        self::assertSame([Platform::SoundCloud, Platform::YouTube, Platform::GitHub], $order);
    }

    public function testIgnoresAKeyThatIsNotAKnownPlatform(): void
    {
        $file = $this->dataFile("['myspace' => 'https://myspace.com/x']");

        self::assertCount(0, new ProfileRepository($file)->all());
    }

    public function testTheConfiguredCatalogueOnlyLinksHttpsUrls(): void
    {
        foreach (new ProfileRepository()->all() as $profile) {
            self::assertStringStartsWith('https://', $profile->url);
        }
    }

    // ───────────────────────── DownloadLogger ─────────────────────────

    /**
     * Logging is deliberately off for legal reasons — data/privacy.html makes no
     * download-tracking claim. Flipping this is a policy decision before a code one.
     */
    public function testLoggingIsSwitchedOff(): void
    {
        self::assertFalse(DownloadLogger::ENABLED);
    }

    public function testLoggingWritesNothingWhileItIsOff(): void
    {
        $log = NEUROSYS_ROOT . '/data/logs/downloads.log';
        $before = is_file($log) ? filesize($log) : -1;

        new DownloadLogger()->log('test-slug', 'flac');

        clearstatcache();
        self::assertSame($before, is_file($log) ? filesize($log) : -1);
    }

    /** The referrer is never even read — the guard returns before the entry is built. */
    public function testTheReferrerIsNotReadWhileLoggingIsOff(): void
    {
        $_SERVER['HTTP_REFERER'] = 'https://example.invalid/leak';

        new DownloadLogger()->log('test-slug', 'flac');

        $log = NEUROSYS_ROOT . '/data/logs/downloads.log';
        self::assertFalse(
            is_file($log) && str_contains((string) file_get_contents($log), 'example.invalid'),
        );

        unset($_SERVER['HTTP_REFERER']);
    }

    // ───────────────────────── DownloadLogEntry ─────────────────────────

    public function testTheLogEntryRoundTripsThroughJson(): void
    {
        $entry = new DownloadLogEntry('2026-06-17T00:00:00+00:00', 'ill', 'flac', 'https://ref');

        $decoded = DownloadLogEntry::fromJson((string) $entry);

        self::assertSame($entry->time, $decoded?->time);
        self::assertSame($entry->slug, $decoded?->slug);
        self::assertSame($entry->format, $decoded?->format);
        self::assertSame($entry->referrer, $decoded?->referrer);
    }

    public static function malformedJsonProvider(): iterable
    {
        yield 'not json'   => ['not json'];
        yield 'empty'      => [''];
        yield 'scalar'     => ['42'];
        yield 'json null'  => ['null'];
        yield 'truncated'  => ['{"slug":"ill"'];
    }

    #[DataProvider('malformedJsonProvider')]
    public function testMalformedLogLinesDecodeToNullRatherThanCrashing(string $json): void
    {
        self::assertNull(DownloadLogEntry::fromJson($json));
    }

    public function testMissingFieldsDefaultToEmptyStrings(): void
    {
        $entry = DownloadLogEntry::fromJson('{"slug":"ill"}');

        self::assertSame('ill', $entry?->slug);
        self::assertSame('', $entry?->format);
        self::assertSame('', $entry?->referrer);
    }

    public function testSlashesAreNotEscapedInTheJson(): void
    {
        $entry = new DownloadLogEntry('t', 'ill', 'flac', 'https://example.test/a/b');

        self::assertStringContainsString('https://example.test/a/b', (string) $entry);
    }
}
