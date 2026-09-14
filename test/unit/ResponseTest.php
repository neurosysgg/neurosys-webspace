<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Controller\DownloadController;
use NeuroSYS\Controller\HomeController;
use NeuroSYS\Controller\ImprintController;
use NeuroSYS\Controller\LanguageController;
use NeuroSYS\Controller\NotFoundController;
use NeuroSYS\Controller\PrivacyController;
use NeuroSYS\Controller\ReleaseController;
use NeuroSYS\Controller\ReleasesController;
use NeuroSYS\DataFile;
use NeuroSYS\Service\ReleaseRepository;
use NeuroSYS\Site;
use NeuroSYS\View\HomeView;
use NeuroSYS\View\ImprintView;
use NeuroSYS\View\NotFoundView;
use NeuroSYS\View\PrivacyView;
use NeuroSYS\View\ReleasesView;
use Phpanta\Http\Answer;
use Phpanta\Http\Header;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\MimeType;
use Phpanta\Http\PlainTextResponse;
use Phpanta\Http\RedirectResponse;
use Phpanta\Http\Request;
use Phpanta\Http\RequestHeader;
use Phpanta\Http\ResponseHeader;
use Phpanta\Http\SecurityHeader;
use Phpanta\Http\TextBody;
use Phpanta\Http\ViewResponse;
use Phpanta\Support\Directory;
use Phpanta\Support\File;
use Phpanta\Test\TestRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use ReflectionProperty;

/**
 * The site's controllers, and the site's shell around a page.
 *
 * What a {@link ViewResponse} does with any view — the document or the fragment, the validator,
 * the 304 — is the framework's, and asserted in its own suite under its test app. What is here is
 * which page each of this site's controllers answers with, and the one end-to-end proof that this
 * site's layout wraps a page and not the fragment.
 */
#[CoversClass(ViewResponse::class)]
#[CoversClass(RedirectResponse::class)]
#[CoversClass(PlainTextResponse::class)]
#[CoversClass(Answer::class)]
#[CoversClass(TextBody::class)]
#[CoversClass(HttpStatusCode::class)]
#[CoversClass(MimeType::class)]
#[CoversClass(DownloadController::class)]
#[CoversClass(ReleaseController::class)]
#[CoversClass(NotFoundController::class)]
#[CoversClass(HomeController::class)]
#[CoversClass(ImprintController::class)]
#[CoversClass(LanguageController::class)]
#[CoversClass(PrivacyController::class)]
#[CoversClass(ReleasesController::class)]
final class ResponseTest extends TestCase
{
    /** @var list<File> */
    private array $fixtures = [];

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->fixtures as $file) {
            $file->directory()->remove();
        }
        $this->fixtures = [];
    }

    /**
     * A GET for $path.
     *
     * @param string $path
     * @return Request
     */
    private static function request(string $path): Request
    {
        return TestRequest::get($path)->request();
    }

    /**
     * What $controller answers a GET for $path with — as Navigation asks for it, when $fragment,
     * which is the page's title and content with none of the layout around it.
     *
     * @param object $controller A controller of this site's.
     * @param string $path
     * @param bool   $fragment
     * @return Answer
     */
    private static function answer(object $controller, string $path, bool $fragment = false): Answer
    {
        $request = $fragment
            ? TestRequest::get($path)->with(RequestHeader::RequestedWith, 'XMLHttpRequest')->request()
            : self::request($path);

        return $controller->handle($request)->answer($request);
    }

    /**
     * The view a response renders, which it offers no accessor for: which view a route means is
     * the whole of what a static route's controller decides.
     *
     * @param ViewResponse $response
     * @return object
     * @throws ReflectionException
     */
    private static function viewOf(ViewResponse $response): object
    {
        return new ReflectionProperty($response, 'view')->getValue($response);
    }

    // ───────────────────────────── the layout ─────────────────────────────

    /**
     * The whole site, end to end: a page arrives in the site's layout, its footer and all, and the
     * fragment Navigation asks for arrives as the home page's title and content and nothing else.
     *
     * @return void
     */
    public function testTheLayoutWrapsAPageAndNeverTheFragment(): void
    {
        $page     = TestRequest::get('/')->answer()->body();
        $fragment = TestRequest::get('/')->with(RequestHeader::RequestedWith, 'XMLHttpRequest')->answer()->body();

        self::assertStringStartsWith('<!DOCTYPE html>', $page);
        self::assertStringContainsString('<html lang="en">', $page);
        self::assertStringContainsString('site-footer', $page);

        self::assertStringStartsWith('<title>neuro.SYS</title>', $fragment);
        self::assertStringContainsString('home-hero', $fragment);
        self::assertStringNotContainsString('site-footer', $fragment);
        self::assertStringNotContainsString('<html', $fragment);
    }

    // ───────────────────────────── controllers ─────────────────────────────

    /**
     * @return void
     */
    public function testAnUnknownSlugProducesA404(): void
    {
        $response = new ReleaseController('no-such-release')->handle(self::request('/releases/no-such-release'));

        self::assertInstanceOf(ViewResponse::class, $response);
        self::assertSame(HttpStatusCode::NotFound, $response->status());
    }

    /**
     * @return void
     */
    public function testAKnownSlugProducesAnOkPage(): void
    {
        self::assertSame(
            HttpStatusCode::Ok,
            self::answer(new ReleaseController('hello-world'), '/releases/hello-world')->status(),
        );
    }

    /**
     * @return void
     */
    public function testTheNotFoundControllerReportsTheRequestedPath(): void
    {
        $response = new NotFoundController('/gone')->handle(self::request('/gone'));

        self::assertInstanceOf(ViewResponse::class, $response);
        self::assertInstanceOf(NotFoundView::class, self::viewOf($response));
        self::assertSame(HttpStatusCode::NotFound, $response->status());
    }

    // ───────────────────────────── the language switch ─────────────────────────────

    /**
     * Back is the `Referer`'s path and nothing else: its host is dropped, a path that names another
     * host is refused, and a switch is never sent back to a switch.
     *
     * @param string $referer
     * @param string $expected
     * @return void
     */
    #[DataProvider('switchProvider')]
    public function testASwitchSendsTheVisitorBackToThePageTheyWereOn(string $referer, string $expected): void
    {
        self::assertSame($expected, LanguageController::back($referer));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function switchProvider(): iterable
    {
        yield 'a page of this site'         => ['https://neurosys.gg/releases/ill', '/releases/ill'];
        yield 'its query dropped'           => ['https://neurosys.gg/releases?x=1', '/releases'];
        yield 'another host, its path kept' => ['https://evil.example/releases/ill', '/releases/ill'];
        yield 'a path that is another host' => ['https://evil.example//evil.example/x', '/'];
        yield 'no referrer'                 => ['', '/'];
        yield 'not a URL'                   => ['releases/ill', '/'];
        yield 'a switch'                    => ['https://neurosys.gg/language/de', '/'];
    }

    /**
     * A language the site has: a 303 with the cookie, never stored.
     *
     * @return void
     */
    public function testASwitchToALanguageTheSiteHasSetsTheCookieAndRedirects(): void
    {
        $answer = TestRequest::get('/language/de')->answer();
        $own    = $answer->headers()
            ->where(static fn(Header $header): bool => !$header->name instanceof SecurityHeader)
            ->map(static fn(Header $header): string => $header->line())
            ->toValues();

        self::assertSame(HttpStatusCode::SeeOther, $answer->status());
        self::assertSame(
            [
                'Set-Cookie: lang=de; Path=/; Max-Age=31536000; SameSite=Lax; Secure; HttpOnly',
                'Cache-Control: no-store, private',
                'Location: /',
            ],
            $own,
        );
        self::assertSame('', $answer->body());
    }

    /**
     * A language it does not have is an address it does not have.
     *
     * @return void
     */
    public function testASwitchToALanguageTheSiteDoesNotHaveIsNotThere(): void
    {
        $answer = self::answer(new LanguageController('fr'), '/language/fr');

        self::assertSame(HttpStatusCode::NotFound, $answer->status());
    }

    // ───────────────────────────── downloads ─────────────────────────────

    /**
     * @return iterable<string, array{string, string, class-string}>
     */
    public static function downloadProvider(): iterable
    {
        yield 'known release and format' => ['hello-world', 'flac', RedirectResponse::class];
        yield 'unknown release'          => ['nope', 'flac', ViewResponse::class];
        yield 'unknown format'           => ['hello-world', 'wma', ViewResponse::class];
        yield 'path traversal attempt'   => ['hello-world', '../../data/releases.php', ViewResponse::class];
    }

    /**
     * @param string $slug
     * @param string $format
     * @param string $expected
     * @return void
     */
    #[DataProvider('downloadProvider')]
    public function testDownloadRoutesResolveToTheRightResponseKind(
        string $slug,
        string $format,
        string $expected,
    ): void {
        $response = new DownloadController($slug, $format)->handle(self::request("/releases/$slug/$format"));

        self::assertInstanceOf($expected, $response);
    }

    /**
     * @return void
     */
    public function testADownloadRedirectsToTheFileHostWithSeeOther(): void
    {
        $answer = self::answer(new DownloadController('hello-world', 'flac'), '/releases/hello-world/flac');

        self::assertSame(HttpStatusCode::SeeOther, $answer->status());
        self::assertStringStartsWith(
            'https://my.hidrive.com/api/sharelink/download?id=',
            $answer->header(ResponseHeader::Location)?->value->render() ?? '',
        );
    }

    /**
     * A staged release: the format is declared but its file isn't uploaded yet. The card
     * renders, and clicking it must say "not yet" rather than 404 or redirect nowhere.
     *
     * @return void
     */
    public function testAFormatWithNoLinkYetReturnsServiceUnavailable(): void
    {
        $request  = self::request('/releases/staged/flac');
        $response = new DownloadController('staged', 'flac', $this->stagedCatalogue())->handle($request);
        $answer   = $response->answer($request);

        self::assertInstanceOf(PlainTextResponse::class, $response);
        self::assertSame(HttpStatusCode::ServiceUnavailable, $answer->status());
        self::assertStringContainsString("isn't available yet", $answer->body());
    }

    /**
     * @return void
     */
    public function testAStagedReleaseStillRendersItsPage(): void
    {
        self::assertSame(
            HttpStatusCode::Ok,
            self::answer(new ReleaseController('staged', $this->stagedCatalogue()), '/releases/staged')->status(),
        );
    }

    /**
     * A catalogue holding one release whose only format has no link yet.
     *
     * @return ReleaseRepository
     */
    private function stagedCatalogue(): ReleaseRepository
    {
        $file = Directory::temporary('neurosys-staged-')->file('releases.php');

        $file->write(<<<'PHP'
            <?php
            use NeuroSYS\Model\{Format, Genre, MusicalKey, Release, ReleaseFormat};
            use Phpanta\Support\Collection;
            return ['staged' => new Release(
                'staged.', 140, MusicalKey::CMajor, Genre::Dubstep, 'unreleased', null,
                new Collection(Format::class)->with(new Format(ReleaseFormat::FLAC)),
            )];
            PHP);

        $this->fixtures[] = $file;

        return new ReleaseRepository($file);
    }

    // ───────────────────────── the pages with no parameters ─────────────────────────

    /**
     * Each of these is one line, and the line is which view the route means.
     *
     * @param class-string $controller
     * @param string       $path
     * @param class-string $view
     * @return void
     */
    #[DataProvider('staticRouteProvider')]
    public function testAStaticRouteRendersItsOwnView(string $controller, string $path, string $view): void
    {
        $response = new $controller()->handle(self::request($path));

        self::assertInstanceOf(ViewResponse::class, $response);
        self::assertInstanceOf($view, self::viewOf($response));
        self::assertSame(HttpStatusCode::Ok, $response->status());
    }

    /**
     * @return iterable<string, array{class-string, string, class-string}>
     */
    public static function staticRouteProvider(): iterable
    {
        yield 'home'     => [HomeController::class, '/', HomeView::class];
        yield 'imprint'  => [ImprintController::class, '/imprint', ImprintView::class];
        yield 'privacy'  => [PrivacyController::class, '/privacy', PrivacyView::class];
        yield 'releases' => [ReleasesController::class, '/releases', ReleasesView::class];
    }

    /**
     * `file_get_contents(...) ?: ''` means a policy that has moved renders as a blank page rather
     * than an error — a privacy policy that silently says nothing. Assert the document arrives.
     *
     * @return void
     */
    public function testThePrivacyControllerReadsTheRealPolicyDocument(): void
    {
        $html = self::answer(new PrivacyController(), '/privacy', fragment: true)->body();

        $lines = explode("\n", (string) Site::current()->dataFile(DataFile::PrivacyEnglish)->read())
                |> (fn($x) => array_map(trim(...), $x))
                |> array_filter(...)
                |> array_values(...);

        // First and last line rather than the whole document: the policy is parsed and rendered
        // back out, so a character reference comes out as the character it names and equality would
        // fail on `&auml;` instead of on what this is about — that the file was read, whole, rather
        // than defaulted to ''. Both of those lines happen to be plain ASCII, which is what keeps
        // this check honest rather than lucky; a line with an entity in it would not survive.
        self::assertNotSame([], $lines);
        self::assertStringContainsString((string) array_first($lines), $html);
        self::assertStringContainsString((string) array_last($lines), $html);
        self::assertStringContainsString('HiDrive', $html);
    }

    /**
     * The catalogue is injectable so a test does not depend on what is released today.
     *
     * @return void
     */
    public function testTheCatalogueControllerListsTheReleasesItWasGiven(): void
    {
        $html = self::answer(new ReleasesController($this->stagedCatalogue()), '/releases', fragment: true)->body();

        self::assertStringContainsString('staged', $html);
        self::assertStringNotContainsString('hello-world', $html);
    }

    /**
     * With none given it reads the real one, which is what the route actually does.
     *
     * @return void
     */
    public function testTheCatalogueControllerFallsBackToTheRealCatalogue(): void
    {
        self::assertStringContainsString(
            'hello-world',
            self::answer(new ReleasesController(), '/releases', fragment: true)->body(),
        );
    }
}
