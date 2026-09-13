<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Controller\DemoAudioController;
use NeuroSYS\Controller\DemoController;
use NeuroSYS\Exception\ReleaseVerificationException;
use NeuroSYS\Model\Demo;
use NeuroSYS\Model\DemoTrack;
use NeuroSYS\Service\DemoGate;
use NeuroSYS\Service\DemoRepository;
use NeuroSYS\Site;
use NeuroSYS\View\DemoView;
use Phpanta\Http\Answer;
use Phpanta\Http\BasicChallenge;
use Phpanta\Http\Header;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\Request;
use Phpanta\Http\ResponseHeader;
use Phpanta\Http\RobotsPolicy;
use Phpanta\Http\ViewResponse;
use Phpanta\Service\Auth;
use Phpanta\Support\Collection;
use Phpanta\Support\Directory;
use Phpanta\Support\PasswordHash;
use Phpanta\Test\TestRequest;
use Phpanta\Text\Language;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

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
 *
 * The framework's half — the bcrypt digest a demo's password is, the byte ranges an `<audio>`
 * element seeks with, and the file response that answers them — is its own `AuthTest` and
 * `FileResponseTest`; the served 200, 206 and 416 are `test/basic_test.sh`'s, over HTTP.
 */
#[CoversClass(Demo::class)]
#[CoversClass(DemoTrack::class)]
#[CoversClass(DemoRepository::class)]
#[CoversClass(DemoGate::class)]
#[CoversClass(DemoController::class)]
#[CoversClass(DemoAudioController::class)]
#[CoversClass(DemoView::class)]
#[CoversClass(PasswordHash::class)]
#[CoversClass(Auth::class)]
#[CoversClass(RobotsPolicy::class)]
#[CoversClass(Answer::class)]
final class DemoTest extends TestCase
{
    private const string PASSWORD = 'DEMO1-DEMO2-DEMO3-DEMO4';

    private Directory $fixtures;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->fixtures = Directory::temporary('neurosys-demo-');
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
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
     * A request for the demo page carrying Basic credentials, the demo's own by default.
     *
     * @param string $user
     * @param string $password
     * @return Request
     */
    private static function request(string $user = Site::DEMO_USER, string $password = self::PASSWORD): Request
    {
        return TestRequest::get('/demos/alien-house')->withCredentials($user, $password)->request();
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
        $repository = $this->oneDemoRepository();

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

        self::assertTrue(DemoGate::admits(self::request(), $demo));
        self::assertFalse(DemoGate::admits(self::request(password: 'wrong'), $demo));
        self::assertFalse(DemoGate::admits(self::request(password: ''), $demo));

        // The user name is fixed and public; it is still compared, so a request that omits it is
        // refused even carrying the right password.
        self::assertFalse(DemoGate::admits(self::request(user: 'admin'), $demo));
        self::assertFalse(DemoGate::admits(self::request(user: ''), $demo));
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

        self::assertTrue(DemoGate::admits(self::request(), self::demo()));
        self::assertFalse(DemoGate::admits(self::request(), $other));
    }

    /**
     * The realm is what the browser keys saved credentials by, so it has to be per demo — one
     * shared realm is a browser volunteering one demo's password to another demo's prompt.
     *
     * @return void
     */
    public function testEachDemoChallengesInItsOwnRealm(): void
    {
        self::assertSame('Basic realm="neuro.SYS demo: alien-house"', self::challengeOf('alien-house'));
        self::assertNotSame(self::challengeOf('alien-house'), self::challengeOf('wna-bootleg'));
    }

    /**
     * A demo that exists, asked for with the wrong password, and one that does not, asked for at
     * all, are answered alike: the same 401, each in its own slug's realm, with nothing else on the
     * wire to tell them apart. A 404 for the second would be the catalogue, one guess at a time.
     *
     * @return void
     */
    public function testAWrongPasswordAndAnUnknownDemoAreRefusedAlike(): void
    {
        $demos = $this->oneDemoRepository();
        $asked = [
            'alien-house'  => self::request(password: 'wrong'),
            'no-such-demo' => self::request(),
        ];

        $answers = [];

        foreach ($asked as $slug => $request) {
            $answer = new DemoController($slug, $demos)->handle($request)->answer($request);

            self::assertSame(HttpStatusCode::Unauthorized, $answer->status(), $slug);
            self::assertSame(
                new BasicChallenge('neuro.SYS demo: ' . $slug)->render(),
                $answer->header(ResponseHeader::WwwAuthenticate)?->value->render(),
            );
            self::assertSame('', $answer->body());

            $answers[] = $answer->headers()
                ->where(static fn(Header $header): bool => $header->name !== ResponseHeader::WwwAuthenticate)
                ->map(static fn(Header $header): string => $header->line())
                ->toValues();
        }

        self::assertSame($answers[0], $answers[1], 'the two refusals differ beyond their realm');
    }

    /**
     * A slug is encoded on the way into the realm, because a slug is what a visitor writes.
     *
     * An unknown demo is challenged exactly like a known one — that is the whole point of
     * {@link DemoGate::enter()} taking a nullable `Demo` — so **any** `/demos/…` target
     * reaches this, including one carrying bytes no route was meant to claim. `Request::path()`
     * hands a target the URI parser refused through with only its query and fragment cut, and
     * `{slug}` matches anything, so `a"b` arrives here — and concatenated into a quoted-string it
     * would break out of it. See docs/history/security.md.
     *
     * {@link \Phpanta\Http\BasicChallenge} refuses that, which is right for a realm written
     * wrong in this repository and would be wrong here: it would turn a hostile target into a 500
     * where a 401 belongs. So the slug is `rawurlencode`d and there is nothing left to refuse —
     * the same treatment {@link \NeuroSYS\Support\SitePath::to()} gives the same value on the way
     * out, and a no-op for every slug `tools/stage-demo.php` can mint, which is what keeps a saved
     * credential keyed to the realm it was saved under. That the challenge still renders at all is
     * the property the encoding exists to keep.
     *
     * @return void
     */
    public function testAHostileSlugIsEncodedRatherThanRefused(): void
    {
        self::assertSame('Basic realm="neuro.SYS demo: a%22b"', self::challengeOf('a"b'));
        self::assertSame('Basic realm="neuro.SYS demo: x%22y%3Fa%3D1"', self::challengeOf('x"y?a=1'));

        // A real slug is untouched, so this costs nothing where it matters.
        self::assertSame('Basic realm="neuro.SYS demo: wna-bootleg"', self::challengeOf('wna-bootleg'));
    }

    /**
     * The challenge one demo's refusal carries, rendered as it goes on the wire.
     *
     * @param string $slug
     * @return string
     */
    private static function challengeOf(string $slug): string
    {
        return new ReflectionMethod(DemoGate::class, 'realm')->invoke(null, $slug)->render();
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
        $request  = self::request();
        $response = new DemoController('alien-house', $this->oneDemoRepository())->handle($request);

        self::assertInstanceOf(ViewResponse::class, $response);

        $answer = $response->answer($request);

        self::assertSame(HttpStatusCode::Ok, $answer->status());
        self::assertSame('no-store, private', $answer->header(ResponseHeader::CacheControl)?->value->render());
        self::assertSame('noindex, nofollow, noarchive', $answer->header(ResponseHeader::Robots)?->value->render());

        // And the consequence, which is the half that is easy to lose: a caller that has already
        // said how its response may be kept gets no validator, so there is no 304 to be had and a
        // gated page cannot come back on a guessed ETag. Only the Vary is added, because it says
        // what the body depends on.
        self::assertNull($answer->header(ResponseHeader::ETag));
        self::assertSame(
            'X-Requested-With, Accept-Language, Cookie',
            $answer->header(ResponseHeader::Vary)?->value->render(),
        );
    }

    /**
     * Past the gate, an unknown label is a 404 — which reveals nothing, since whoever is asking
     * holds the password and can read every label off the page.
     *
     * @return void
     */
    public function testAnUnknownTrackLabelIsNotFound(): void
    {
        $request  = self::request();
        $response = new DemoAudioController('alien-house', 'v9', $this->oneDemoRepository())->handle($request);

        self::assertInstanceOf(ViewResponse::class, $response);
        self::assertSame(HttpStatusCode::NotFound, $response->answer($request)->status());
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
        $request  = self::request();
        $response = new DemoAudioController('alien-house', $label, $this->oneDemoRepository())->handle($request);

        self::assertSame(HttpStatusCode::NotFound, $response->answer($request)->status());
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
     * The slug is one nothing stages, so `Site::demoDir()` resolves under the real `data/demos/`
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
            . 'new \\Phpanta\\Support\\PasswordHash(' . var_export(self::hash()->digest(), true) . '),'
            . 'new \\Phpanta\\Support\\Collection(\\NeuroSYS\\Model\\DemoTrack::class)->with('
            . "new \\NeuroSYS\\Model\\DemoTrack('v3', 'nothing-wrote-this.mp3', 158)"
            . ')),'
            . '];',
        );

        $request  = self::request();
        $response = new DemoAudioController('never-staged', 'v3', $repository)->handle($request);

        self::assertInstanceOf(ViewResponse::class, $response);
        self::assertSame(HttpStatusCode::NotFound, $response->answer($request)->status());
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
        )->content()->render(0, Language::English);

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
        $html = new DemoView(self::demo(), 'alien-house')->content()->render(0, Language::English);

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
            ->content()->render(0, Language::English);

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
            . 'new \\Phpanta\\Support\\PasswordHash(' . var_export(self::hash()->digest(), true) . '),'
            . 'new \\Phpanta\\Support\\Collection(\\NeuroSYS\\Model\\DemoTrack::class)->with('
            . "new \\NeuroSYS\\Model\\DemoTrack('v3', 'v3.mp3', 158)"
            . ')),'
            . '];',
        );
    }
}
