<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Config;
use NeuroSYS\Controller\DemoAudioController;
use NeuroSYS\Controller\DemoController;
use NeuroSYS\Exception\MimeTypeException;
use NeuroSYS\Exception\ReleaseVerificationException;
use NeuroSYS\Exception\SecurityPolicyException;
use NeuroSYS\Http\AcceptRanges;
use NeuroSYS\Http\ByteRange;
use NeuroSYS\Http\ContentLength;
use NeuroSYS\Http\ContentRange;
use NeuroSYS\Http\ETag;
use NeuroSYS\Http\FileResponse;
use NeuroSYS\Http\Header;
use NeuroSYS\Http\HttpStatusCode;
use NeuroSYS\Http\MimeType;
use NeuroSYS\Http\Request;
use NeuroSYS\Http\RobotsDirective;
use NeuroSYS\Http\RobotsPolicy;
use NeuroSYS\Http\ViewResponse;
use NeuroSYS\Model\Demo;
use NeuroSYS\Model\DemoTrack;
use NeuroSYS\Service\Auth;
use NeuroSYS\Service\DemoRepository;
use NeuroSYS\Support\Collection;
use NeuroSYS\Support\Directory;
use NeuroSYS\Support\File;
use NeuroSYS\Support\PasswordHash;
use NeuroSYS\View\DemoView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * The demo half of the site: the gate, the bytes behind it, and the page in front of it.
 *
 * What is under test here is not "a page renders". It is a set of claims that each fail *silently*
 * if they stop being true, and that together are the difference between a demo and a release:
 *
 * - **The password covers the audio, not just the page.** A release redirects to a HiDrive share
 *   URL, which anyone can forward and which outlives a password change. A demo's files sit under
 *   `data/`, and the only route to them asks the same question the page does.
 * - **An unknown slug is refused exactly like a wrong password** — status code *and* elapsed time —
 *   because a 404 for one and a 401 for the other is a list of unreleased tracks, guessable one
 *   name at a time.
 * - **Nothing builds a path out of a URL.** The last segment is matched against declared labels;
 *   `DemoTrack` refusing a name with a slash in it is the second guard, for a typo in the data file
 *   rather than for anything a visitor can send.
 * - **Ranges work**, which is not a nicety: an `<audio>` element seeks by asking for one, so a
 *   server that ignores them gives you a player that will not skip, with nothing in any console.
 */
#[CoversClass(Demo::class)]
#[CoversClass(DemoTrack::class)]
#[CoversClass(PasswordHash::class)]
#[CoversClass(DemoRepository::class)]
#[CoversClass(DemoController::class)]
#[CoversClass(DemoAudioController::class)]
#[CoversClass(DemoView::class)]
#[CoversClass(ByteRange::class)]
#[CoversClass(ContentRange::class)]
#[CoversClass(ContentLength::class)]
#[CoversClass(AcceptRanges::class)]
#[CoversClass(RobotsPolicy::class)]
#[CoversClass(FileResponse::class)]
#[CoversClass(MimeType::class)]
final class DemoTest extends TestCase
{
    private const string PASSWORD = 'DEMO1-DEMO2-DEMO3-DEMO4';

    /** @var array<string, mixed> */
    private array $serverBackup;

    private Directory $fixtures;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        $this->fixtures     = Directory::temporary('neurosys-demo-');
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;

        $this->fixtures->remove();
    }

    // ───────────────────────────── fixtures ─────────────────────────────

    /**
     * Cost 4 is bcrypt's minimum and keeps the suite fast; `password_verify()` reads the cost out
     * of the hash, so this is the same code path a real one takes.
     *
     * @param string $password
     * @return PasswordHash
     */
    private static function hash(string $password = self::PASSWORD): PasswordHash
    {
        return new PasswordHash(password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]));
    }

    /**
     * @param list<DemoTrack> $tracks
     * @return Demo
     */
    private static function demo(array $tracks = []): Demo
    {
        return new Demo(
            title:    'alien house',
            password: self::hash(),
            tracks:   new Collection(DemoTrack::class)->with(
                ...($tracks === [] ? [new DemoTrack('v3', 'v3.mp3', 158)] : $tracks),
            ),
        );
    }

    /**
     * @param string $user
     * @param string $password
     * @param string $range
     * @return Request
     */
    private static function request(
        string $user = Config::DEMO_USER,
        string $password = self::PASSWORD,
        string $range = '',
    ): Request {
        $_SERVER = ['REQUEST_URI' => '/demos/alien-house', 'PHP_AUTH_USER' => $user, 'PHP_AUTH_PW' => $password];

        if ($range !== '') {
            $_SERVER['HTTP_RANGE'] = $range;
        }

        return Request::fromGlobals();
    }

    /**
     * A repository backed by a written data file, which is the only way to get one — the real
     * `data/demos.php` is gitignored, so no test may assume it exists.
     *
     * @param string $php
     * @return DemoRepository
     */
    private function repository(string $php): DemoRepository
    {
        $file = $this->fixtures->file('demos.php');

        $file->write($php);

        return new DemoRepository($file);
    }

    /**
     * @param FileResponse $response
     * @param Request      $request
     * @return string
     */
    private static function body(FileResponse $response, Request $request): string
    {
        ob_start();
        $response->send($request);

        return (string) ob_get_clean();
    }

    // ───────────────────────────── PasswordHash ─────────────────────────────

    /**
     * @return void
     */
    public function testABcryptDigestVerifiesTheRightPasswordAndNothingElse(): void
    {
        $hash = self::hash('hunter2');

        self::assertTrue($hash->matches('hunter2'));
        self::assertFalse($hash->matches('hunter3'));
        self::assertFalse($hash->matches(''));
    }

    /**
     * The failure this class exists for: `password_verify()` answers false both for a wrong
     * password and for a digest that is not one, and those are opposite problems. A truncated paste
     * would otherwise present as a password that quietly stopped working.
     *
     * @param string $digest
     * @return void
     */
    #[DataProvider('nonDigestProvider')]
    public function testSomethingThatIsNotABcryptDigestIsRefusedWhereItIsWritten(string $digest): void
    {
        $this->expectException(ReleaseVerificationException::class);

        new PasswordHash($digest);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonDigestProvider(): iterable
    {
        yield 'a plain password'  => ['hunter2'];
        yield 'the empty string'  => [''];
        yield 'a truncated paste' => ['$2y$12$.UgLy0HhsjKSHB.qMCnqRu6MewIN'];
        yield 'argon2i'           => [password_hash('x', PASSWORD_ARGON2I)];
        yield 'a bare md5'        => [md5('hunter2')];
    }

    /**
     * The empty string is the one non-digest that is not a mistake: it is how `data/admin.php`
     * spells an unconfigured gate. Null, not an exception, and not a hash that accepts everything.
     *
     * @return void
     */
    public function testAnEmptyHashIsAnUnconfiguredGateRatherThanAMalformedOne(): void
    {
        self::assertNull(PasswordHash::configured(''));
        self::assertInstanceOf(PasswordHash::class, PasswordHash::configured(self::hash()->digest()));
    }

    /**
     * The dummy the demo gate burns time against when there is no demo to compare with. It must be
     * a real digest — `password_verify()` on a malformed one returns immediately, which would
     * defeat the whole point — and it must match nothing.
     *
     * @return void
     */
    public function testTheUnmatchableDigestIsRealAndMatchesNothing(): void
    {
        $hash = PasswordHash::unmatchable();

        self::assertSame(PASSWORD_BCRYPT, password_get_info($hash->digest())['algo']);
        self::assertFalse($hash->matches(''));
        self::assertFalse($hash->matches(self::PASSWORD));
        self::assertFalse($hash->matches($hash->digest()));
    }

    // ───────────────────────────── DemoTrack ─────────────────────────────

    /**
     * The path-traversal boundary, checked where it is written rather than where it is used.
     *
     * @param string $file
     * @return void
     */
    #[DataProvider('badFileNameProvider')]
    public function testATrackFileNameThatCouldLeaveItsDirectoryIsRefused(string $file): void
    {
        $this->expectException(ReleaseVerificationException::class);

        new DemoTrack('v3', $file);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badFileNameProvider(): iterable
    {
        yield 'the parent'          => ['..'];
        yield 'up and out'          => ['../../data/admin.php'];
        yield 'an absolute path'    => ['/etc/passwd'];
        yield 'a subdirectory'      => ['stems/v3.mp3'];
        yield 'a backslash'         => ['..\\admin.php'];
        yield 'a leading dot'       => ['.htaccess'];
        yield 'empty'               => [''];
        yield 'a null byte'         => ["v3.mp3\0.txt"];
    }

    /**
     * A label is a URL segment. One carrying a separator names a version no address can reach,
     * which is a page that renders perfectly with a player that 404s.
     *
     * @param string $label
     * @return void
     */
    #[DataProvider('badLabelProvider')]
    public function testATrackLabelThatIsNotAUrlSegmentIsRefused(string $label): void
    {
        $this->expectException(ReleaseVerificationException::class);

        new DemoTrack($label, 'v3.mp3');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badLabelProvider(): iterable
    {
        yield 'a slash'      => ['v3/v4'];
        yield 'upper case'   => ['V3'];
        yield 'a space'      => ['v 3'];
        yield 'a dot'        => ['v3.1'];
        yield 'empty'        => [''];
        yield 'a query'      => ['v3?x=1'];
    }

    /**
     * @return void
     */
    public function testADurationRendersAsMinutesAndSecondsAndAnUnreadOneRendersAsNothing(): void
    {
        self::assertSame('2:38', new DemoTrack('v3', 'v3.mp3', 158)->duration());
        self::assertSame('0:07', new DemoTrack('v3', 'v3.mp3', 7)->duration());
        self::assertSame('10:00', new DemoTrack('v3', 'v3.mp3', 600)->duration());

        // Zero is "ffprobe said nothing", which is a different page from "this track is 0:00 long".
        self::assertNull(new DemoTrack('v3', 'v3.mp3', 0)->duration());
    }

    /**
     * @return void
     */
    public function testANegativeDurationIsRefused(): void
    {
        $this->expectException(ReleaseVerificationException::class);

        new DemoTrack('v3', 'v3.mp3', -1);
    }

    // ───────────────────────────── Demo ─────────────────────────────

    /**
     * A release with no formats is still a page. A demo is the audio.
     *
     * @return void
     */
    public function testADemoWithNoTracksIsRefused(): void
    {
        $this->expectException(ReleaseVerificationException::class);

        new Demo('alien house', self::hash(), new Collection(DemoTrack::class));
    }

    /**
     * @return void
     */
    public function testACollectionOfSomethingElseIsRefused(): void
    {
        $this->expectException(ReleaseVerificationException::class);

        /** @phpstan-ignore-next-line deliberately the wrong element type */
        new Demo('alien house', self::hash(), new Collection(Demo::class));
    }

    /**
     * The label is the URL, so a repeat means one version is unreachable — and the staged file is
     * named for it, so it also means one overwrote the other on disk.
     *
     * @return void
     */
    public function testTwoTracksMayNotShareALabel(): void
    {
        $this->expectException(ReleaseVerificationException::class);

        self::demo([new DemoTrack('v3', 'a.mp3', 10), new DemoTrack('v3', 'b.mp3', 10)]);
    }

    /**
     * @return void
     */
    public function testATrackIsFoundByItsLabelAndAnythingElseIsNull(): void
    {
        $demo = self::demo([new DemoTrack('v4', 'v4.mp3', 157), new DemoTrack('v3', 'v3.mp3', 158)]);

        self::assertSame('v4.mp3', $demo->findTrack('v4')?->file);
        self::assertSame('v3.mp3', $demo->findTrack('v3')?->file);
        self::assertNull($demo->findTrack('v5'));
        self::assertNull($demo->findTrack('../v3'));
        self::assertNull($demo->findTrack(''));
    }

    // ───────────────────────────── DemoRepository ─────────────────────────────

    /**
     * `data/demos.php` is gitignored, so every clone starts without one. Absent has to mean an
     * empty catalogue rather than a fatal, the way `data/profiles.php`'s does — unlike
     * `data/releases.php`, whose absence is a broken deployment.
     *
     * @return void
     */
    public function testAMissingDataFileIsNoDemosRatherThanAnError(): void
    {
        $repository = new DemoRepository($this->fixtures->file('nothing-here.php'));

        self::assertTrue($repository->all()->isEmpty());
        self::assertNull($repository->find('alien-house'));
    }

    /**
     * @return void
     */
    public function testDemosAreKeyedByTheirSlug(): void
    {
        $repository = $this->repository(
            '<?php return ['
            . "'alien-house' => new \\NeuroSYS\\Model\\Demo("
            . "'alien house',"
            . "new \\NeuroSYS\\Support\\PasswordHash(" . var_export(self::hash()->digest(), true) . '),'
            . 'new \\NeuroSYS\\Support\\Collection(\\NeuroSYS\\Model\\DemoTrack::class)->with('
            . "new \\NeuroSYS\\Model\\DemoTrack('v3', 'v3.mp3', 158)"
            . ')),'
            . '];',
        );

        self::assertSame('alien house', $repository->find('alien-house')?->title);
        self::assertNull($repository->find('no-such-demo'));
    }

    // ───────────────────────────── the gate ─────────────────────────────

    /**
     * @return void
     */
    public function testTheRightPasswordIsAdmittedAndNothingElseIs(): void
    {
        $demo = self::demo();

        self::assertTrue(Auth::admits(self::request(), $demo));
        self::assertFalse(Auth::admits(self::request(password: 'wrong'), $demo));
        self::assertFalse(Auth::admits(self::request(password: ''), $demo));

        // The user name is fixed and public; it is still compared, so a request that omits it is
        // refused even carrying the right password.
        self::assertFalse(Auth::admits(self::request(user: 'admin'), $demo));
        self::assertFalse(Auth::admits(self::request(user: ''), $demo));
    }

    /**
     * One demo's password must not open another's. That is the property the whole per-demo
     * arrangement exists for, and it would still "work" — one shared password — if it broke.
     *
     * @return void
     */
    public function testOneDemosPasswordDoesNotOpenAnother(): void
    {
        $other = new Demo(
            title:    'wna bootleg',
            password: self::hash('SOMET-HINGE-LSEEN-TIRLY'),
            tracks:   new Collection(DemoTrack::class)->with(new DemoTrack('v4', 'v4.mp3', 157)),
        );

        self::assertTrue(Auth::admits(self::request(), self::demo()));
        self::assertFalse(Auth::admits(self::request(), $other));
    }

    /**
     * The realm is what the browser keys saved credentials by, so it has to be per demo — one
     * shared realm is a browser volunteering one demo's password to another demo's prompt.
     *
     * @return void
     */
    public function testEachDemoChallengesInItsOwnRealm(): void
    {
        self::assertSame('neuro.SYS demo: alien-house', self::demoRealmOf('alien-house'));
        self::assertNotSame(self::demoRealmOf('alien-house'), self::demoRealmOf('wna-bootleg'));
    }

    /**
     * A slug is encoded on the way into the realm, because a slug is what a visitor writes.
     *
     * An unknown demo is challenged exactly like a known one — that is the whole point of
     * {@link Auth::requireDemoAuth()} taking a nullable `Demo` — so **any** `/demos/…` target
     * reaches this, including one carrying bytes no route was meant to claim. `Request::path()`
     * hands a target the URI parser refused through with only its query and fragment cut, and
     * `{slug}` matches anything, so `a"b` arrives here — and concatenated into a quoted-string it
     * would break out of it. See docs/history/security.md.
     *
     * {@link \NeuroSYS\Http\BasicChallenge} refuses that, which is right for a realm written
     * wrong in this repository and would be wrong here: it would turn a hostile target into a 500
     * where a 401 belongs. So the slug is `rawurlencode`d and there is nothing left to refuse —
     * the same treatment {@link \NeuroSYS\Support\SitePath::to()} gives the same value on the way
     * out, and a no-op for every slug `tools/stage-demo.php` can mint, which is what keeps a saved
     * credential keyed to the realm it was saved under.
     *
     * @return void
     */
    public function testAHostileSlugIsEncodedRatherThanRefused(): void
    {
        self::assertSame('neuro.SYS demo: a%22b', self::demoRealmOf('a"b'));
        self::assertSame('neuro.SYS demo: x%22y%3Fa%3D1', self::demoRealmOf('x"y?a=1'));

        // A real slug is untouched, so this costs nothing where it matters.
        self::assertSame('neuro.SYS demo: wna-bootleg', self::demoRealmOf('wna-bootleg'));

        // And the challenge still renders, which is the property the encoding exists to keep.
        self::assertSame(
            'Basic realm="neuro.SYS demo: a%22b"',
            new ReflectionMethod(Auth::class, 'demoRealm')->invoke(null, 'a"b')->render(),
        );
    }

    /**
     * The realm one demo's challenge names, read off the private property that holds it.
     *
     * @param string $slug
     * @return string
     */
    private static function demoRealmOf(string $slug): string
    {
        return new ReflectionProperty(\NeuroSYS\Http\BasicChallenge::class, 'realm')->getValue(
            new ReflectionMethod(Auth::class, 'demoRealm')->invoke(null, $slug),
        );
    }

    // ───────────────────────────── ByteRange ─────────────────────────────

    /**
     * @param string   $header
     * @param int      $size
     * @param int|null $first Null where the header names nothing this reads.
     * @param int|null $last
     * @return void
     */
    #[DataProvider('rangeProvider')]
    public function testARangeHeaderResolvesAgainstTheSizeOfTheFile(
        string $header,
        int $size,
        ?int $first,
        ?int $last,
    ): void {
        $range = ByteRange::parse($header, $size);

        if ($first === null) {
            self::assertNull($range, 'this header names no single byte range');

            return;
        }

        self::assertNotNull($range);
        self::assertSame($first, $range->first);
        self::assertSame($last, $range->last);
    }

    /**
     * @return iterable<string, array{string, int, ?int, ?int}>
     */
    public static function rangeProvider(): iterable
    {
        yield 'a closed range'        => ['bytes=0-499', 5000, 0, 499];
        yield 'one byte'              => ['bytes=0-0', 5000, 0, 0];
        yield 'open ended'            => ['bytes=500-', 5000, 500, 4999];
        yield 'a suffix'              => ['bytes=-500', 5000, 4500, 4999];
        yield 'a suffix longer than the file' => ['bytes=-9000', 5000, 0, 4999];
        yield 'clamped to the end'    => ['bytes=4000-9999', 5000, 4000, 4999];
        yield 'surrounding space'     => [' bytes=0-9 ', 5000, 0, 9];

        // Understood, and unsatisfiable — a 416, which is a different answer from ignoring it.
        yield 'past the end'          => ['bytes=9000-', 5000, 9000, 4999];
        yield 'a zero-length suffix'  => ['bytes=-0', 5000, 1, 0];

        // Not understood. Every one of these means "send the whole file", which is always legal.
        yield 'no header'             => ['', 5000, null, null];
        yield 'backwards'             => ['bytes=500-100', 5000, null, null];
        yield 'a list'                => ['bytes=0-99,200-299', 5000, null, null];
        yield 'another unit'          => ['items=0-99', 5000, null, null];
        yield 'no unit'               => ['0-99', 5000, null, null];
        yield 'nothing at all'        => ['bytes=-', 5000, null, null];
        yield 'not a number'          => ['bytes=a-b', 5000, null, null];
        yield 'negative'              => ['bytes=--5', 5000, null, null];
    }

    /**
     * @return void
     */
    public function testSatisfiabilityIsAskedSeparatelyFromUnderstanding(): void
    {
        self::assertTrue(ByteRange::parse('bytes=0-499', 5000)?->isSatisfiable());
        self::assertSame(500, ByteRange::parse('bytes=0-499', 5000)?->length());

        // Understood; the file is simply too short.
        self::assertFalse(ByteRange::parse('bytes=9000-', 5000)?->isSatisfiable());
        self::assertSame(0, ByteRange::parse('bytes=9000-', 5000)?->length());
        self::assertFalse(ByteRange::parse('bytes=-0', 5000)?->isSatisfiable());

        // An empty file holds no byte, so no range over it is satisfiable.
        self::assertFalse(ByteRange::parse('bytes=0-', 0)?->isSatisfiable());
    }

    /**
     * @return void
     */
    public function testContentRangeStatesThePartOrTheSizeAlone(): void
    {
        $range = ByteRange::parse('bytes=0-1023', 5000);

        self::assertNotNull($range);
        self::assertSame('bytes 0-1023/5000', ContentRange::of($range)->render());
        self::assertSame('bytes */5000', ContentRange::unsatisfiable(5000)->render());
    }

    /**
     * @return void
     */
    public function testTheSmallHeaderValuesRenderAsTheirGrammarRequires(): void
    {
        self::assertSame('4096', new ContentLength(4096)->render());
        self::assertSame('0', new ContentLength(0)->render());
        self::assertSame('bytes', AcceptRanges::Bytes->render());
        self::assertSame('none', AcceptRanges::None->render());
        self::assertSame('noindex, nofollow, noarchive', RobotsPolicy::hide()->render());
        self::assertSame('noindex', RobotsPolicy::of(RobotsDirective::NoIndex)->render());
    }

    /**
     * A negative length is not a header. The way to get one is arithmetic on a range that went
     * wrong, which is precisely the code this class ships alongside.
     *
     * @return void
     */
    public function testANegativeContentLengthIsRefused(): void
    {
        $this->expectException(SecurityPolicyException::class);

        new ContentLength(-1);
    }

    /**
     * An empty `X-Robots-Tag` is a malformed header rather than a permissive one — the same rule
     * `CacheControl::of()` and `Vary::on()` follow. A response with nothing to ask omits it.
     *
     * @return void
     */
    public function testAnEmptyRobotsPolicyIsRefused(): void
    {
        $this->expectException(SecurityPolicyException::class);

        RobotsPolicy::of();
    }

    // ───────────────────────────── MimeType ─────────────────────────────

    /**
     * Audio is bytes, not characters, so it carries no charset — and an extension nothing knows
     * throws rather than falling back, because `nosniff` means a wrong type cannot be corrected by
     * the browser: an MP3 typed as octet-stream downloads instead of playing.
     *
     * @return void
     */
    public function testAudioTypesCarryNoCharsetAndAnUnknownExtensionThrows(): void
    {
        self::assertSame('audio/mpeg', MimeType::forAudio('mp3')->render());
        self::assertSame('audio/mpeg', MimeType::forAudio('MP3')->render());
        self::assertSame('audio/flac', MimeType::forAudio('flac')->render());
        self::assertSame('audio/wav', MimeType::forAudio('wav')->render());
        self::assertSame('audio/mp4', MimeType::forAudio('m4a')->render());
        self::assertSame('audio/ogg', MimeType::forAudio('ogg')->render());
        self::assertSame('audio/opus', MimeType::forAudio('opus')->render());
        self::assertNull(MimeType::forAudio('mp3')->charset);

        $this->expectException(MimeTypeException::class);
        MimeType::forAudio('exe');
    }

    // ───────────────────────────── FileResponse ─────────────────────────────

    /**
     * @return void
     */
    public function testAWholeFileIsSentWhenNothingAskedForAPart(): void
    {
        $file = $this->fixtures->file('v3.mp3');
        $file->write('0123456789');

        self::assertSame(
            '0123456789',
            self::body(new FileResponse($file, MimeType::forAudio('mp3')), self::request()),
        );
    }

    /**
     * The bytes have to be exactly the ones named, or a browser treats the response as broken
     * rather than as an approximation.
     *
     * @param string $header
     * @param string $expected
     * @return void
     */
    #[DataProvider('rangeBodyProvider')]
    public function testARangedRequestGetsExactlyTheBytesItNamed(string $header, string $expected): void
    {
        $file = $this->fixtures->file('v3.mp3');
        $file->write('0123456789');

        self::assertSame(
            $expected,
            self::body(new FileResponse($file, MimeType::forAudio('mp3')), self::request(range: $header)),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rangeBodyProvider(): iterable
    {
        yield 'the first three'  => ['bytes=0-2', '012'];
        yield 'the middle'       => ['bytes=3-5', '345'];
        yield 'to the end'       => ['bytes=7-', '789'];
        yield 'the last three'   => ['bytes=-3', '789'];
        yield 'one byte'         => ['bytes=5-5', '5'];
        yield 'clamped'          => ['bytes=8-99', '89'];

        // Unsatisfiable: no body at all, because the whole file would look like the part asked for.
        yield 'past the end'     => ['bytes=50-', ''];

        // Not understood: the whole file, which is always a legal answer to a Range.
        yield 'a list'           => ['bytes=0-1,4-5', '0123456789'];
    }

    /**
     * A HEAD asks what a GET would answer, not for the answer. The site's other responses echo
     * regardless and let the server drop the body; a file is read off disk, so this one does not.
     *
     * @return void
     */
    public function testHeadSendsNoBody(): void
    {
        $file = $this->fixtures->file('v3.mp3');
        $file->write('0123456789');

        $_SERVER = ['REQUEST_URI' => '/demos/alien-house/v3', 'REQUEST_METHOD' => 'HEAD'];

        self::assertSame(
            '',
            self::body(new FileResponse($file, MimeType::forAudio('mp3')), Request::fromGlobals()),
        );
    }

    /**
     * A file that cannot be opened is no body, not a PHP warning printed into the audio.
     *
     * The controller asks `exists()` first, which is `is_file()` and says nothing about whether the
     * file can be *read* — the exact gap `File::read()`'s docblock names, where an unreadable file
     * once put an `E_WARNING` ahead of a page's doctype. Here the headers have already gone out, so
     * a warning would land in the middle of the stream.
     *
     * Deleting it between construction and `send()` is the honest version of that: the same race a
     * live deploy can produce, and it costs no assumptions about file modes or which user is running
     * the suite.
     *
     * @return void
     */
    public function testAFileThatCannotBeOpenedSendsNoBodyAndNoWarning(): void
    {
        $file = $this->fixtures->file('v3.mp3');
        $file->write('0123456789');

        $response = new FileResponse($file, MimeType::forAudio('mp3'));

        $file->delete();

        self::assertSame('', self::body($response, self::request()));
    }

    // ───────────────────────────── the controllers ─────────────────────────────

    /**
     * The gated page says `no-store, private` and `noindex`, and — because `ViewResponse` stands
     * down when a caller has already said how a response may be kept — carries **no `ETag`**, so it
     * can never be handed back on a guessed validator.
     *
     * @return void
     */
    public function testTheDemoPageIsToldNotToBeKeptOrIndexed(): void
    {
        $response = new DemoController('alien-house', $this->oneDemoRepository())->handle(self::request());

        self::assertInstanceOf(ViewResponse::class, $response);

        /** @var Collection<Header> $headers */
        $headers = new ReflectionProperty(ViewResponse::class, 'headers')->getValue($response);
        $lines   = $headers->map(static fn(Header $header): string => $header->line())->toValues();

        self::assertContains('Cache-Control: no-store, private', $lines);
        self::assertContains('X-Robots-Tag: noindex, nofollow, noarchive', $lines);

        // And the consequence, which is the half that is easy to lose: a caller that has already
        // said how its response may be kept gets no validator, so there is no 304 to be had and a
        // gated page cannot come back on a guessed ETag. Asked of the method rather than of the
        // wire, because `header()` is a no-op under CLI — see test/basic_test.sh for the other end.
        ob_start();
        $response->send(self::request());
        $markup = (string) ob_get_clean();

        /** @var Collection<Header> $cache */
        $cache = new ReflectionMethod(ViewResponse::class, 'cacheHeaders')
            ->invoke($response, ETag::forBody($markup));

        self::assertTrue($cache->isEmpty());
    }

    /**
     * Past the gate, an unknown label is a 404 — which reveals nothing, since whoever is asking
     * holds the password and can read every label off the page.
     *
     * @return void
     */
    public function testAnUnknownTrackLabelIsNotFound(): void
    {
        $response = new DemoAudioController('alien-house', 'v9', $this->oneDemoRepository())
            ->handle(self::request());

        self::assertInstanceOf(ViewResponse::class, $response);
        self::assertSame(
            HttpStatusCode::NotFound,
            new ReflectionProperty(ViewResponse::class, 'status')->getValue($response),
        );
    }

    /**
     * A label naming no track is a null before any path is built, whatever it is made of.
     *
     * @param string $label
     * @return void
     */
    #[DataProvider('traversalLabelProvider')]
    public function testNoUrlSegmentCanNameAFileOutsideTheDemo(string $label): void
    {
        $response = new DemoAudioController('alien-house', $label, $this->oneDemoRepository())
            ->handle(self::request());

        self::assertSame(
            HttpStatusCode::NotFound,
            new ReflectionProperty(ViewResponse::class, 'status')->getValue($response),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function traversalLabelProvider(): iterable
    {
        yield 'the parent'       => ['..'];
        yield 'an encoded slash' => ['%2e%2e%2fadmin.php'];
        yield 'a file name'      => ['v3.mp3'];
        yield 'a real file'      => ['admin.php'];
        yield 'empty'            => [''];
    }

    /**
     * A track whose file is missing is a staging mistake, not a half-state the model carries — a
     * release `Format` is deliberately allowed to exist before its upload does, and this is not.
     * It is a 404 rather than a 500 or an empty 200.
     *
     * The slug is one nothing stages, so `Config::demoDir()` resolves under the real `data/demos/`
     * and finds nothing there whatever this machine happens to hold. The **served** case is the one
     * this cannot reach — it needs a real file at a real path — and `test/basic_test.sh` covers it
     * over HTTP, which is where the 200, the 206 and the 416 are observable anyway.
     *
     * @return void
     */
    public function testATrackWithNoFileBehindItIsNotFound(): void
    {
        $repository = $this->repository(
            '<?php return ['
            . "'never-staged' => new \\NeuroSYS\\Model\\Demo("
            . "'never staged',"
            . 'new \\NeuroSYS\\Support\\PasswordHash(' . var_export(self::hash()->digest(), true) . '),'
            . 'new \\NeuroSYS\\Support\\Collection(\\NeuroSYS\\Model\\DemoTrack::class)->with('
            . "new \\NeuroSYS\\Model\\DemoTrack('v3', 'nothing-wrote-this.mp3', 158)"
            . ')),'
            . '];',
        );

        $response = new DemoAudioController('never-staged', 'v3', $repository)->handle(self::request());

        self::assertInstanceOf(ViewResponse::class, $response);
        self::assertSame(
            HttpStatusCode::NotFound,
            new ReflectionProperty(ViewResponse::class, 'status')->getValue($response),
        );
    }

    // ───────────────────────────── the page ─────────────────────────────

    /**
     * @return void
     */
    public function testThePageCarriesANativePlayerPerMixAndNamesNoFile(): void
    {
        $html = new DemoView(
            self::demo([new DemoTrack('v4', 'v4.mp3', 157), new DemoTrack('v3', 'v3.mp3', 158)]),
            'alien-house',
        )->content()->render();

        // Native <audio>, not a custom element: the browser's own control seeks, takes a keyboard,
        // and works with JS off — which for something sent to one person to listen to is the point.
        self::assertSame(2, substr_count($html, '<audio '));
        self::assertStringContainsString('controls', $html);
        self::assertStringContainsString('preload="none"', $html);

        // The URL names the label, never the file. Nothing of `v4.mp3` reaches the page.
        self::assertStringContainsString('src="/demos/alien-house/v4"', $html);
        self::assertStringContainsString('src="/demos/alien-house/v3"', $html);
        self::assertStringNotContainsString('.mp3', $html);

        self::assertStringContainsString('2:37', $html);
        self::assertStringContainsString('2:38', $html);
    }

    /**
     * The one part of the arrangement whoever was sent the link can actually see.
     *
     * @return void
     */
    public function testThePageSaysWhatIsBeingAsked(): void
    {
        $html = new DemoView(self::demo(), 'alien-house')->content()->render();

        self::assertStringContainsString('unreleased', $html);
        self::assertStringContainsString('demo-notice', $html);
        self::assertStringContainsString('demo.log', $html);
    }

    /**
     * A track with no measured duration renders no duration element at all, rather than `0:00` —
     * an empty span would still be spaced by the stylesheet.
     *
     * @return void
     */
    public function testAnUnmeasuredTrackShowsNoDuration(): void
    {
        $html = new DemoView(self::demo([new DemoTrack('v3', 'v3.mp3', 0)]), 'alien-house')
            ->content()->render();

        self::assertStringNotContainsString('demo-time', $html);
        self::assertStringContainsString('demo-label', $html);
    }

    /**
     * @return DemoRepository
     */
    private function oneDemoRepository(): DemoRepository
    {
        return $this->repository(
            '<?php return ['
            . "'alien-house' => new \\NeuroSYS\\Model\\Demo("
            . "'alien house',"
            . 'new \\NeuroSYS\\Support\\PasswordHash(' . var_export(self::hash()->digest(), true) . '),'
            . 'new \\NeuroSYS\\Support\\Collection(\\NeuroSYS\\Model\\DemoTrack::class)->with('
            . "new \\NeuroSYS\\Model\\DemoTrack('v3', 'v3.mp3', 158)"
            . ')),'
            . '];',
        );
    }
}
