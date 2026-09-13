<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Layout;
use NeuroSYS\Service\ReleaseRepository;
use NeuroSYS\View\NotFoundView;
use NeuroSYS\View\ReleaseView;
use Phpanta\Http\Answer;
use Phpanta\Http\Header;
use Phpanta\Http\HttpMethod;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\Request;
use Phpanta\Http\ResponseHeader;
use Phpanta\Http\SecurityHeader;
use Phpanta\Http\SecurityHeaders;
use Phpanta\Router;
use Phpanta\Test\TestRequest;
use Phpanta\Text\Language;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The site's security, end to end: its routes behind the method gate, its answers behind the
 * security headers, the hosts its policy names, and views that never need the policy loosened.
 *
 * The gate itself, the router's refusal and the default policy are the framework's, and asserted in
 * its own suite under its test app; what is here is that this site, with its routes and its hosts,
 * is still held to them.
 */
#[CoversClass(SecurityHeaders::class)]
#[CoversClass(Answer::class)]
#[CoversClass(Router::class)]
#[CoversClass(Request::class)]
#[CoversClass(HttpMethod::class)]
#[CoversClass(Header::class)]
#[CoversClass(ResponseHeader::class)]
final class SecurityTest extends TestCase
{
    /**
     * The rendered Content-Security-Policy, as it is actually sent.
     *
     * @return string
     */
    private static function policy(): string
    {
        return SecurityHeaders::headers()[SecurityHeader::ContentSecurityPolicy->value];
    }

    /**
     * @param Answer $answer
     * @return list<string>
     */
    private static function lines(Answer $answer): array
    {
        return $answer->headers()->map(static fn(Header $header): string => $header->line())->toValues();
    }

    // ───────────────────────── the method gate, over the site's routes ─────────────────────────

    /**
     * Before the gate, `POST /releases/ill/flac` 303'd to the file host exactly like a GET.
     *
     * @param string $method
     * @param string $path
     * @return void
     */
    #[DataProvider('writeMethodProvider')]
    public function testAWriteMethodIsRefusedOnEveryRoute(string $method, string $path): void
    {
        $answer = TestRequest::to($method, $path)->answer();

        self::assertSame(HttpStatusCode::MethodNotAllowed, $answer->status());
        self::assertSame('GET, HEAD', $answer->header(ResponseHeader::Allow)?->value->render());
    }

    /**
     * @return iterable<array{string, string}>
     */
    public static function writeMethodProvider(): iterable
    {
        yield ['POST', '/releases/hello-world/flac'];
        yield ['DELETE', '/releases/hello-world/flac'];
        yield ['PUT', '/'];
        yield ['POST', '/no-such-page'];

        // Where the admin used to be. Nothing is there any more, so a write to it is a write to an
        // address that does not exist — no alias, and nothing to say it ever was.
        yield ['POST', '/api/update/v1/patch'];
        yield ['PUT', '/api/update/v1/patch'];
    }

    /**
     * Every answer leads with the security headers — the refusals and the redirect as much as the
     * page. They used to be sent before anything else so that a 401 or a 405 ending the process
     * still carried them; now every answer carries them, first, and this asserts it in-process
     * rather than only over curl.
     *
     * @param string         $method
     * @param string         $path
     * @param HttpStatusCode $status
     * @return void
     */
    #[DataProvider('answerProvider')]
    public function testEveryAnswerLeadsWithTheSecurityHeaders(
        string $method,
        string $path,
        HttpStatusCode $status,
    ): void {
        $answer   = TestRequest::to($method, $path)->answer();
        $expected = SecurityHeaders::all()->map(static fn(Header $header): string => $header->line())->toValues();

        self::assertSame($status, $answer->status());
        self::assertSame($expected, array_slice(self::lines($answer), 0, count($expected)));
    }

    /**
     * @return iterable<string, array{string, string, HttpStatusCode}>
     */
    public static function answerProvider(): iterable
    {
        yield 'a page'                => ['GET', '/', HttpStatusCode::Ok];
        yield 'a page that is not'    => ['GET', '/no-such-page', HttpStatusCode::NotFound];
        yield 'a redirect'            => ['GET', '/language/de', HttpStatusCode::SeeOther];
        yield 'the admin entrance'    => ['GET', '/admin', HttpStatusCode::Ok];
        yield 'the admin, unsigned'   => ['POST', '/admin/update/v1/patch', HttpStatusCode::SeeOther];
        yield 'a write refused'       => ['POST', '/', HttpStatusCode::MethodNotAllowed];
        yield 'a verb nobody knows'   => ['BREW', '/', HttpStatusCode::MethodNotAllowed];
        yield 'the old API'           => ['POST', '/api/update/v1/patch', HttpStatusCode::MethodNotAllowed];
    }

    /**
     * A write is answered with a 405 naming the methods that would have worked, in plain text, and
     * a write to where the admin used to be is answered identically — nothing is there any more.
     *
     * @return void
     */
    public function testAWriteIsRefusedIdenticallyWhereverItIsSent(): void
    {
        $page = TestRequest::to(HttpMethod::Post, '/')->answer();
        $api  = TestRequest::to(HttpMethod::Post, '/api/update/v1/patch')->answer();

        self::assertSame('GET, HEAD', $page->header(ResponseHeader::Allow)?->value->render());
        self::assertSame('text/plain; charset=utf-8', $page->header(ResponseHeader::ContentType)?->value->render());
        self::assertSame(self::lines($page), self::lines($api));
        self::assertSame($page->body(), $api->body());
    }

    // ───────────────────────── the unmatched path ─────────────────────────

    /**
     * An address no route claims is the site's own 404 page, and the page reports the path that
     * was asked for — the normalised one.
     *
     * @return void
     */
    public function testAnUnknownPathIsTheNotFoundPageNamingThePathAskedFor(): void
    {
        $answer = TestRequest::get('/no-such-page/')->answer();

        self::assertSame(HttpStatusCode::NotFound, $answer->status());
        self::assertSame('text/html; charset=utf-8', $answer->header(ResponseHeader::ContentType)?->value->render());
        self::assertStringContainsString('/no-such-page', $answer->body());
    }

    // ───────────────────────── the site's hosts ─────────────────────────

    /**
     * @return void
     */
    public function testOnlyTheFileHostMayServeImages(): void
    {
        self::assertMatchesRegularExpression(
            "#img-src 'self' https://my\.hidrive\.com;#",
            self::policy(),
        );
    }

    /**
     * @return void
     */
    public function testOnlySoundCloudMayBeFramed(): void
    {
        self::assertStringContainsString('frame-src https://w.soundcloud.com', self::policy());
    }

    /**
     * A cheap guard against a CDN sneaking into the policy in a future edit — and against what the
     * site names for a directive being anything but a host. The framework keeps its own policy
     * strict whatever hosts an app names; a keyword or a scheme among them would be this site's
     * doing, so this site's policy is asserted to carry none.
     *
     * @return void
     */
    public function testThePolicyNamesNoUnexpectedHostAndNothingButHosts(): void
    {
        self::assertSame(
            ['https://my.hidrive.com', 'https://w.soundcloud.com'],
            SecurityHeaders::contentSecurityPolicy()->hosts(),
        );
        self::assertStringNotContainsString("'unsafe-", self::policy());
        self::assertStringNotContainsString('data:', self::policy());
    }

    // ───────────────────────── views the policy never has to loosen for ─────────────────────────

    /**
     * `style-src` carries no 'unsafe-inline': SoundCloud's attribution is styled by
     * <soundcloud-player> through the CSSOM. Nothing may put the allowance back by needing it, so
     * assert the views emit no inline style.
     *
     * @return void
     */
    public function testNoViewEmitsAnInlineStyleOrEventHandler(): void
    {
        $release = new ReleaseRepository()->find('ill');
        $html = new ReleaseView($release, 'ill')->content()->render(0, Language::English)
            . new NotFoundView('/x')->content()->render(0, Language::English)
            . Layout::wrap(new NotFoundView('/x'), Language::English)->render();

        self::assertDoesNotMatchRegularExpression('/\sstyle="/', $html);
        self::assertDoesNotMatchRegularExpression('/\son(error|click|load|mouse\w+)=/', $html);
    }

    /**
     * @return void
     */
    public function testTheCoverFallbackIsAnAttributeNotAnInlineHandler(): void
    {
        $release = new ReleaseRepository()->find('ill');
        $html = new ReleaseView($release, 'ill')->content()->render(0, Language::English);

        self::assertStringContainsString('fallback="/assets/img/cover-placeholder.svg"', $html);
        self::assertStringNotContainsString('onerror', $html);
    }

    /**
     * @return void
     */
    public function testTheConsentGateCarriesItsHeightAsAnAttribute(): void
    {
        $release = new ReleaseRepository()->find('ill');
        $html = new ReleaseView($release, 'ill')->content()->render(0, Language::English);

        self::assertStringContainsString('height="300"', $html);
    }

    /*
     * The Permissions-Policy is built with denyAll(), so adding a case to PermissionsPolicyFeature
     * would deny that feature everywhere — including inside the SoundCloud iframe, which asks for
     * autoplay and encrypted-media, and would switch the player off with no error anywhere.
     *
     * That iframe is built by <soundcloud-player>, so the assertion lives with what it guards, in
     * test/js/soundcloud-player.test.mjs: it reads the real allow= off the real element and checks
     * it against the header this class sends.
     */
}
