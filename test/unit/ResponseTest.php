<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Controller\DownloadController;
use NeuroSYS\Controller\NotFoundController;
use NeuroSYS\Controller\ReleaseController;
use NeuroSYS\Http\HttpStatusCode;
use NeuroSYS\Http\PlainTextResponse;
use NeuroSYS\Http\RedirectResponse;
use NeuroSYS\Http\Request;
use NeuroSYS\Http\ViewResponse;
use NeuroSYS\Service\ReleaseRepository;
use NeuroSYS\View\HomeView;
use NeuroSYS\View\NotFoundView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(ViewResponse::class)]
#[CoversClass(RedirectResponse::class)]
#[CoversClass(PlainTextResponse::class)]
#[CoversClass(HttpStatusCode::class)]
#[CoversClass(DownloadController::class)]
#[CoversClass(ReleaseController::class)]
#[CoversClass(NotFoundController::class)]
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

            public function content(): string
            {
                return '<p>x</p>';
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
                new Collection(Format::class)->add(new Format(ReleaseFormat::FLAC)),
            )];
            PHP);

        $this->tempFiles[] = $file;

        return new ReleaseRepository($file);
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
}
