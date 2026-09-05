<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Config;
use NeuroSYS\Exception\ReleaseVerificationException;
use NeuroSYS\Model\Platform;
use NeuroSYS\Model\Release;
use NeuroSYS\Service\DownloadLogEntry;
use NeuroSYS\Service\DownloadLogger;
use NeuroSYS\Model\Profile;
use NeuroSYS\Model\ReleaseFormat;
use NeuroSYS\Service\ProfileRepository;
use NeuroSYS\Service\ReleaseRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReleaseRepository::class)]
#[CoversClass(ProfileRepository::class)]
#[CoversClass(Profile::class)]
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
        self::assertSame(Platform::GitHub, array_first($links->all())?->platform);
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
        self::assertFalse(Config::DOWNLOAD_LOGGING);
    }

    public function testLoggingWritesNothingWhileItIsOff(): void
    {
        $log = NEUROSYS_ROOT . '/data/logs/downloads.log';
        $before = is_file($log) ? filesize($log) : -1;

        new DownloadLogger()->log('test-slug', ReleaseFormat::FLAC);

        clearstatcache();
        self::assertSame($before, is_file($log) ? filesize($log) : -1);
    }

    /** The referrer is never even read — the guard returns before the entry is built. */
    public function testTheReferrerIsNotReadWhileLoggingIsOff(): void
    {
        $_SERVER['HTTP_REFERER'] = 'https://example.invalid/leak';

        new DownloadLogger()->log('test-slug', ReleaseFormat::FLAC);

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

    /**
     * Missing is empty; present-but-not-a-string is a line to skip. The two are different cases and
     * used to be the same one.
     *
     * Every field is typed `string`, and under `strict_types=1` a number, a bool or a nested object
     * reaching that constructor is an uncaught TypeError — not a null the caller can skip, a fatal.
     * {@link \NeuroSYS\Controller\StatsController} reads the log line by line and skips whatever
     * comes back null, so before this a single malformed line 500'd the whole stats page instead.
     */
    #[DataProvider('wrongTypeProvider')]
    public function testAFieldOfTheWrongTypeIsSkippedRatherThanFatal(string $json): void
    {
        self::assertNull(DownloadLogEntry::fromJson($json));
    }

    public static function wrongTypeProvider(): iterable
    {
        yield 'numeric time'   => ['{"time":123}'];
        yield 'float time'     => ['{"time":1.5}'];
        yield 'bool format'    => ['{"format":true}'];
        yield 'nested slug'    => ['{"slug":{"a":1}}'];
        yield 'array referrer' => ['{"referrer":["a"]}'];

        // The one that hydrated silently rather than throwing: json_decode(assoc: true) renders {}
        // and [] as the same empty array, so a list passed the is_array() guard and became an entry
        // of four empty strings — counted in the total, filed under '/'. Corrupt input read as data.
        yield 'a json list'    => ['[1,2,3]'];
        yield 'an empty list'  => ['[]'];
        yield 'a list of rows' => ['[{"slug":"ill"}]'];
    }

    /** The other side of that: an object with nothing in it is still an object, and still decodes. */
    public function testAnEmptyObjectIsStillAnEntry(): void
    {
        $entry = DownloadLogEntry::fromJson('{}');

        self::assertNotNull($entry);
        self::assertSame('', $entry->slug);
    }

    /** A null field is an absent one, which is the existing contract and stays that way. */
    public function testANullFieldIsTreatedAsAbsent(): void
    {
        self::assertSame('', DownloadLogEntry::fromJson('{"referrer":null}')?->referrer);
    }

    public function testSlashesAreNotEscapedInTheJson(): void
    {
        $entry = new DownloadLogEntry('t', 'ill', 'flac', 'https://example.test/a/b');

        self::assertStringContainsString('https://example.test/a/b', (string) $entry);
    }

    /**
     * All a Profile carries is the pairing: the footer asks the platform for its own label, icon
     * and height. It replaced an `['platform' => …, 'url' => …]` array shape, which is a value
     * object nobody named — nothing checked the keys, and a caller destructuring it wrongly got
     * null rather than an error.
     */
    public function testAProfileCarriesItsPlatformAndUrl(): void
    {
        $profile = new Profile(Platform::SoundCloud, 'https://soundcloud.com/' . Config::HANDLE);

        self::assertSame(Platform::SoundCloud, $profile->platform);
        self::assertSame('https://soundcloud.com/' . Config::HANDLE, $profile->url);
    }

    /**
     * The URL is verified the way HiDriveLink's share id is, and for the same reason: it is the one
     * address on the site that arrives as free text from a data file instead of being built from
     * parts. Element refuses a `javascript:` href at render regardless — but that is the backstop,
     * and a backstop reports the fault on whatever page happens to draw the footer. Here it is
     * reported when `data/profiles.php` loads, which is where the mistake actually is.
     */
    #[DataProvider('badProfileUrlProvider')]
    public function testAProfileUrlThatIsNotAnHttpsAddressIsRefused(string $url): void
    {
        $this->expectException(ReleaseVerificationException::class);

        new Profile(Platform::X, $url);
    }

    public static function badProfileUrlProvider(): iterable
    {
        yield 'javascript'        => ['javascript:alert(document.domain)'];
        yield 'data'              => ['data:text/html,alert()'];
        yield 'protocol-relative' => ['//evil.example/neurosysgg'];
        yield 'plaintext http'    => ['http://x.com/neurosysgg'];
        yield 'no scheme'         => ['x.com/neurosysgg'];
        yield 'scheme only'       => ['https://'];
        yield 'empty'             => [''];

        // \S throughout the pattern, because browsers strip whitespace out of a URL before
        // resolving it — which is how `jav<tab>ascript:` arrives at the parser as a scheme.
        yield 'embedded space'    => ['https://x.com/a b'];
        yield 'embedded tab'      => ["https://x.com/a\tb"];

        // \z rather than $, because $ also matches immediately before a trailing newline.
        yield 'trailing newline'  => ["https://x.com/a\n"];
    }

    /** Every profile the site actually ships, checked as the data file declares them. */
    public function testEveryShippedProfileUrlIsAccepted(): void
    {
        $profiles = new ProfileRepository()->all();

        self::assertGreaterThan(0, $profiles->count());

        foreach ($profiles as $profile) {
            self::assertStringStartsWith('https://', $profile->url);
        }
    }
}
