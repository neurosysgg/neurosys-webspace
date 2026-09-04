<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Config;
use NeuroSYS\Exception\MimeTypeException;
use NeuroSYS\Controller\DownloadController;
use NeuroSYS\Controller\HomeController;
use NeuroSYS\Controller\ImprintController;
use NeuroSYS\Controller\NotFoundController;
use NeuroSYS\Controller\PrivacyController;
use NeuroSYS\Controller\ReleaseController;
use NeuroSYS\Controller\ReleasesController;
use NeuroSYS\Http\Header;
use NeuroSYS\Http\HttpStatusCode;
use NeuroSYS\Http\MimeType;
use NeuroSYS\Http\PlainTextResponse;
use NeuroSYS\Http\RedirectResponse;
use NeuroSYS\Http\Request;
use NeuroSYS\Http\ResponseHeader;
use NeuroSYS\Http\TopLevelType;
use NeuroSYS\Http\ViewResponse;
use NeuroSYS\View\Html\Element;
use NeuroSYS\View\Html\HtmlTag;
use NeuroSYS\View\Html\Node;
use NeuroSYS\Service\ReleaseRepository;
use NeuroSYS\Support\Charset;
use NeuroSYS\View\HomeView;
use NeuroSYS\View\ImprintView;
use NeuroSYS\View\NotFoundView;
use NeuroSYS\View\PrivacyView;
use NeuroSYS\View\ReleasesView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(ViewResponse::class)]
#[CoversClass(RedirectResponse::class)]
#[CoversClass(PlainTextResponse::class)]
#[CoversClass(HttpStatusCode::class)]
#[CoversClass(MimeType::class)]
#[CoversClass(Charset::class)]
#[CoversClass(DownloadController::class)]
#[CoversClass(ReleaseController::class)]
#[CoversClass(NotFoundController::class)]
#[CoversClass(HomeController::class)]
#[CoversClass(ImprintController::class)]
#[CoversClass(PrivacyController::class)]
#[CoversClass(ReleasesController::class)]
final class ResponseTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $serverBackup;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;

        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->tempFiles = [];
    }

    private function request(string $path, bool $ajax = false): Request
    {
        $_SERVER = ['REQUEST_URI' => $path];
        if ($ajax) {
            $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        }
        return Request::fromGlobals();
    }

    private function render(ViewResponse $response, Request $request): string
    {
        ob_start();
        $response->send($request);
        return (string) ob_get_clean();
    }

    private static function peek(object $object, string $property): mixed
    {
        return new ReflectionProperty($object::class, $property)->getValue($object);
    }

    // ───────────────────────────── ViewResponse ─────────────────────────────

    public function testAFullPageRequestGetsTheWholeDocument(): void
    {
        $html = $this->render(new ViewResponse(new HomeView()), $this->request('/'));

        self::assertStringStartsWith('<!DOCTYPE html>', $html);
        self::assertStringContainsString('<html lang="en">', $html);
        self::assertStringContainsString('site-footer', $html);
    }

    /** Navigation swaps this straight into #content, so a full document here would nest one. */
    public function testAnAjaxRequestGetsAFragmentWithNoDocumentShell(): void
    {
        $html = $this->render(new ViewResponse(new HomeView()), $this->request('/', ajax: true));

        self::assertStringNotContainsString('<html', $html);
        self::assertStringNotContainsString('<!DOCTYPE', $html);
        self::assertStringNotContainsString('site-footer', $html);
        self::assertStringContainsString('home-hero', $html);
    }

    public function testTheAjaxFragmentLeadsWithTheTitleNavJsLooksFor(): void
    {
        $html = $this->render(new ViewResponse(new HomeView()), $this->request('/', ajax: true));

        self::assertStringStartsWith('<title>', $html);
        self::assertSame(1, preg_match('/^<title>(.*?)<\/title>/', $html, $m));
        self::assertSame('neuro.SYS', $m[1]);
    }

    /**
     * Navigation HTML-decodes this before assigning document.title. The two have to agree:
     * the fragment escapes, the client decodes.
     */
    public function testTheAjaxTitleIsEscapedSoTheClientCanDecodeIt(): void
    {
        $view = new class () extends \NeuroSYS\View\View {
            public function pageTitle(): string
            {
                return 'rock & roll';
            }

            public function content(): Node
            {
                return new Element(HtmlTag::P)->containing('x');
            }
        };

        $html = $this->render(new ViewResponse($view), $this->request('/', ajax: true));

        preg_match('/^<title>(.*?)<\/title>/', $html, $m);

        self::assertSame('rock &amp; roll', $m[1]);
        self::assertSame('rock & roll', html_entity_decode($m[1], ENT_QUOTES));
    }

    public function testTheDefaultStatusIsOk(): void
    {
        self::assertSame(HttpStatusCode::Ok, self::peek(new ViewResponse(new HomeView()), 'status'));
    }

    /**
     * Extra headers reach the wire, in the order given.
     *
     * `header()` is a no-op under CLI, so what is asserted here is that the loop runs at all — the
     * headers themselves are checked over real HTTP by the verify script, and the one caller that
     * passes any is pinned in {@link AdminTest}. Worth having as a unit test regardless: an
     * unexecuted loop is how a `Cache-Control` that nothing sends still reads as sent.
     */
    public function testExtraHeadersAreSentAlongsideTheBody(): void
    {
        $response = new ViewResponse(new HomeView(), HttpStatusCode::Ok, [
            new Header(ResponseHeader::CacheControl, 'no-store, private'),
        ]);

        self::assertSame(
            ['Cache-Control: no-store, private'],
            array_map(static fn(Header $h): string => $h->line(), self::peek($response, 'headers')),
        );
        self::assertStringContainsString('<main', $this->render($response, $this->request('/')));
    }

    // ───────────────────────────── controllers ─────────────────────────────

    public function testAnUnknownSlugProducesA404(): void
    {
        $response = new ReleaseController('no-such-release')->handle($this->request('/releases/no-such-release'));

        self::assertInstanceOf(ViewResponse::class, $response);
        self::assertSame(HttpStatusCode::NotFound, self::peek($response, 'status'));
    }

    public function testAKnownSlugProducesAnOkPage(): void
    {
        $response = new ReleaseController('hello-world')->handle($this->request('/releases/hello-world'));

        self::assertSame(HttpStatusCode::Ok, self::peek($response, 'status'));
    }

    public function testTheNotFoundControllerReportsTheRequestedPath(): void
    {
        $response = new NotFoundController('/gone')->handle($this->request('/gone'));

        self::assertInstanceOf(NotFoundView::class, self::peek($response, 'view'));
        self::assertSame(HttpStatusCode::NotFound, self::peek($response, 'status'));
    }

    public static function downloadProvider(): iterable
    {
        yield 'known release and format' => ['hello-world', 'flac', RedirectResponse::class];
        yield 'unknown release'          => ['nope', 'flac', ViewResponse::class];
        yield 'unknown format'           => ['hello-world', 'wma', ViewResponse::class];
        yield 'path traversal attempt'   => ['hello-world', '../../data/admin.php', ViewResponse::class];
    }

    #[DataProvider('downloadProvider')]
    public function testDownloadRoutesResolveToTheRightResponseKind(
        string $slug,
        string $format,
        string $expected,
    ): void {
        $response = new DownloadController($slug, $format)->handle($this->request("/releases/$slug/$format"));

        self::assertInstanceOf($expected, $response);
    }

    public function testADownloadRedirectsToTheFileHostWithSeeOther(): void
    {
        $response = new DownloadController('hello-world', 'flac')
            ->handle($this->request('/releases/hello-world/flac'));

        self::assertSame(HttpStatusCode::SeeOther, self::peek($response, 'status'));
        self::assertStringStartsWith(
            'https://my.hidrive.com/api/sharelink/download?id=',
            self::peek($response, 'url'),
        );
    }

    /**
     * A staged release: the format is declared but its file isn't uploaded yet. The card
     * renders, and clicking it must say "not yet" rather than 404 or redirect nowhere.
     */
    public function testAFormatWithNoLinkYetReturnsServiceUnavailable(): void
    {
        $response = new DownloadController('staged', 'flac', $this->stagedCatalogue())
            ->handle($this->request('/releases/staged/flac'));

        self::assertInstanceOf(PlainTextResponse::class, $response);
        self::assertSame(HttpStatusCode::ServiceUnavailable, self::peek($response, 'status'));
        self::assertStringContainsString("isn't available yet", self::peek($response, 'body'));
    }

    public function testAStagedReleaseStillRendersItsPage(): void
    {
        $response = new ReleaseController('staged', $this->stagedCatalogue())
            ->handle($this->request('/releases/staged'));

        self::assertSame(HttpStatusCode::Ok, self::peek($response, 'status'));
    }

    /** A catalogue holding one release whose only format has no link yet. */
    private function stagedCatalogue(): ReleaseRepository
    {
        $file = tempnam(sys_get_temp_dir(), 'neurosys-staged') . '.php';
        file_put_contents($file, <<<'PHP'
            <?php
            use NeuroSYS\Model\{Format, Genre, MusicalKey, Release, ReleaseFormat};
            use NeuroSYS\Support\Collection;
            return ['staged' => new Release(
                'staged.', 140, MusicalKey::CMajor, Genre::Dubstep, 'unreleased', null,
                new Collection(Format::class)->with(new Format(ReleaseFormat::FLAC)),
            )];
            PHP);

        $this->tempFiles[] = $file;

        return new ReleaseRepository($file);
    }

    // ───────────────────────── the pages with no parameters ─────────────────────────

    /** Each of these is one line, and the line is which view the route means. */
    #[DataProvider('staticRouteProvider')]
    public function testAStaticRouteRendersItsOwnView(string $controller, string $path, string $view): void
    {
        $response = new $controller()->handle($this->request($path));

        self::assertInstanceOf(ViewResponse::class, $response);
        self::assertInstanceOf($view, self::peek($response, 'view'));
        self::assertSame(HttpStatusCode::Ok, self::peek($response, 'status'));
    }

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
     */
    public function testThePrivacyControllerReadsTheRealPolicyDocument(): void
    {
        $response = new PrivacyController()->handle($this->request('/privacy'));
        $html     = self::peek($response, 'view')->content()->render();

        $lines = array_values(array_filter(
            array_map(trim(...), explode("\n", (string) file_get_contents(Config::dataPath('privacy.html')))),
        ));

        // First and last line rather than the whole document: RawHtml is emitted verbatim but the
        // renderer indents each of its lines, so equality would fail on the whitespace instead of
        // on what this is about — that the file was read, whole, rather than defaulted to ''.
        self::assertNotSame([], $lines);
        self::assertStringContainsString($lines[0], $html);
        self::assertStringContainsString($lines[array_key_last($lines)], $html);
        self::assertStringContainsString('HiDrive', $html);
    }

    /** The catalogue is injectable so a test does not depend on what is released today. */
    public function testTheCatalogueControllerListsTheReleasesItWasGiven(): void
    {
        $response = new ReleasesController($this->stagedCatalogue())->handle($this->request('/releases'));

        $html = self::peek($response, 'view')->content()->render();

        self::assertStringContainsString('staged', $html);
        self::assertStringNotContainsString('hello-world', $html);
    }

    /** With none given it reads the real one, which is what the route actually does. */
    public function testTheCatalogueControllerFallsBackToTheRealCatalogue(): void
    {
        $response = new ReleasesController()->handle($this->request('/releases'));

        self::assertStringContainsString(
            'hello-world',
            self::peek($response, 'view')->content()->render(),
        );
    }

    // ───────────────────────────── status codes ─────────────────────────────

    #[DataProvider('statusProvider')]
    public function testTheStatusCodesTheAppUsesHaveTheRightValues(HttpStatusCode $case, int $value): void
    {
        self::assertSame($value, $case->value);
    }

    public static function statusProvider(): iterable
    {
        yield [HttpStatusCode::Ok, 200];
        yield [HttpStatusCode::SeeOther, 303];
        yield [HttpStatusCode::Unauthorized, 401];
        yield [HttpStatusCode::NotFound, 404];
        yield [HttpStatusCode::ServiceUnavailable, 503];
    }

    // ───────────────────────────── MimeType ─────────────────────────────

    /**
     * The two the site sends, pinned to the byte. test/basic_test.sh greps the live headers for
     * these exact strings; this is the same assertion one layer down, where it can say why it
     * failed rather than that a curl did not match.
     */
    public function testTheTwoTypesTheSiteSendsRenderExactly(): void
    {
        self::assertSame('text/html; charset=utf-8', MimeType::html()->render());
        self::assertSame('text/plain; charset=utf-8', MimeType::plainText()->render());
    }

    /** The parameter is optional because most types have no encoding to declare. */
    public function testANullCharsetRendersTheTypeAlone(): void
    {
        self::assertSame(
            'image/png',
            new MimeType(TopLevelType::Image, 'png', charset: null)->render(),
        );
    }

    /** Every body this site sends is text, so the parameter is there unless it is refused. */
    public function testTheCharsetIsPresentByDefault(): void
    {
        self::assertSame(Charset::Utf8, new MimeType(TopLevelType::Text, 'css')->charset);
    }

    public static function validSubtypeProvider(): iterable
    {
        yield 'plain'        => ['html'];
        yield 'plus suffix'  => ['svg+xml'];
        yield 'vendor tree'  => ['vnd.api+json'];
        yield 'x- prefix'    => ['x-www-form-urlencoded'];
        yield 'digits'       => ['mp4'];
        yield 'leading digit' => ['3gpp'];
        yield 'at the cap'   => [str_repeat('a', 127)];
    }

    #[DataProvider('validSubtypeProvider')]
    public function testAcceptsEveryShapeARegisteredSubtypeTakes(string $subtype): void
    {
        self::assertSame($subtype, new MimeType(TopLevelType::Application, $subtype)->subtype);
    }

    public static function invalidSubtypeProvider(): iterable
    {
        yield 'empty'          => [''];
        yield 'space'          => ['ht ml'];
        yield 'a whole type'   => ['text/html'];
        yield 'with parameter' => ['html; q=1'];
        yield 'leading dash'   => ['-html'];
        yield 'leading dot'    => ['.html'];
        yield 'a token char no subtype uses' => ['ht!ml'];
        yield 'past the cap'   => [str_repeat('a', 128)];
    }

    /** Mirrors CspHost: a bad paste has to fail where it is written, not on the wire. */
    #[DataProvider('invalidSubtypeProvider')]
    public function testRejectsAnythingThatIsNotABareSubtype(string $subtype): void
    {
        $this->expectException(MimeTypeException::class);
        new MimeType(TopLevelType::Text, $subtype);
    }

    // ───────────────────────────── Charset ─────────────────────────────

    /**
     * Both forms, pinned to the literals the three readers carried before this enum existed: the
     * header parameter, the charset meta tag in Layout, and htmlspecialchars in Text.
     */
    public function testTheEncodingHasAHeaderFormAndACanonicalOne(): void
    {
        self::assertSame('utf-8', Charset::Utf8->value);
        self::assertSame('UTF-8', Charset::Utf8->canonical());
    }
}
