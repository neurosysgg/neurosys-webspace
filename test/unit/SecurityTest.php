<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Http\HttpStatusCode;
use NeuroSYS\Http\PlainTextResponse;
use NeuroSYS\Http\Request;
use NeuroSYS\Http\ResponseHeader;
use NeuroSYS\Http\Security\ContentTypeOptions;
use NeuroSYS\Http\Security\PermissionsPolicyFeature;
use NeuroSYS\Http\Security\ReferrerPolicy;
use NeuroSYS\Http\Allow;
use NeuroSYS\Http\Header;
use NeuroSYS\Http\HttpMethod;
use NeuroSYS\Http\SecurityHeader;
use NeuroSYS\Http\SecurityHeaders;
use NeuroSYS\Http\ViewResponse;
use NeuroSYS\Layout;
use NeuroSYS\Router;
use NeuroSYS\Service\ReleaseRepository;
use NeuroSYS\Support\RouteInitialization;
use NeuroSYS\View\NotFoundView;
use NeuroSYS\View\ReleaseView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(SecurityHeaders::class)]
#[CoversClass(Router::class)]
#[CoversClass(Request::class)]
#[CoversClass(HttpMethod::class)]
#[CoversClass(Header::class)]
#[CoversClass(ResponseHeader::class)]
final class SecurityTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $serverBackup;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
    }

    /**
     * @param string $method
     * @param string $path
     * @return Request
     */
    private function request(string $method, string $path = '/'): Request
    {
        $_SERVER = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path];
        return Request::fromGlobals();
    }

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
     * @param SecurityHeader $header
     * @return string
     */
    private static function header(SecurityHeader $header): string
    {
        return SecurityHeaders::headers()[$header->value];
    }

    // ───────────────────────── read-only method gate ─────────────────────────

    /**
     * @return iterable
     */
    public static function readOnlyProvider(): iterable
    {
        yield ['GET', true];
        yield ['HEAD', true];
        yield ['get', true];  // normalised to upper case
        yield ['POST', false];
        yield ['PUT', false];
        yield ['DELETE', false];
        yield ['PATCH', false];
        yield ['TRACE', false];
    }

    /**
     * @param string $method
     * @param bool $expected
     * @return void
     */
    #[DataProvider('readOnlyProvider')]
    public function testOnlyReadMethodsAreTreatedAsReadOnly(string $method, bool $expected): void
    {
        self::assertSame($expected, $this->request($method)->isReadOnly());
    }

    /**
     * @return void
     */
    public function testTheMethodIsUpperCased(): void
    {
        self::assertSame(HttpMethod::Get, $this->request('get')->method());
    }

    /**
     * An unrecognised method is null rather than a guess, and null is not read-only.
     *
     * @return void
     */
    public function testAnUnknownMethodIsNotAMethod(): void
    {
        self::assertNull($this->request('WHATEVER')->method());
        self::assertFalse($this->request('WHATEVER')->isReadOnly());
    }

    /**
     * The Allow header is derived from the gate, so the two cannot say different things.
     *
     * @return void
     */
    public function testTheAllowedMethodsAreExactlyTheReadOnlyOnes(): void
    {
        $readOnly = array_values(array_filter(
            HttpMethod::cases(),
            static fn(HttpMethod $m): bool => $m->isReadOnly(),
        ));

        self::assertSame([HttpMethod::Get, HttpMethod::Head], $readOnly);
        self::assertSame('GET, HEAD', Allow::readOnly()->render());
    }

    /**
     * @return void
     */
    public function testAMissingRequestMethodDefaultsToGet(): void
    {
        $_SERVER = ['REQUEST_URI' => '/'];

        self::assertSame(HttpMethod::Get, Request::fromGlobals()->method());
    }

    /**
     * Before this, POST /releases/ill/flac 303'd to HiDrive exactly like a GET.
     *
     * @param string $method
     * @param string $path
     * @return void
     */
    #[DataProvider('writeMethodProvider')]
    public function testAWriteMethodIsRefusedOnEveryRoute(string $method, string $path): void
    {
        $response = new Router(RouteInitialization::routes())->dispatch($this->request($method, $path));

        self::assertInstanceOf(PlainTextResponse::class, $response);
        self::assertSame(
            HttpStatusCode::MethodNotAllowed,
            new ReflectionProperty($response, 'status')->getValue($response),
        );
    }

    /**
     * @return iterable
     */
    public static function writeMethodProvider(): iterable
    {
        yield ['POST', '/releases/hello-world/flac'];
        yield ['DELETE', '/releases/hello-world/flac'];
        yield ['PUT', '/'];
        yield ['POST', '/admin/stats'];
        yield ['POST', '/no-such-page'];
    }

    /**
     * A 405 without an Allow header is a malformed 405.
     *
     * @return void
     */
    public function testTheRefusalNamesTheAllowedMethods(): void
    {
        $response = new Router(RouteInitialization::routes())
            ->dispatch($this->request('POST'));

        $headers = new ReflectionProperty($response, 'headers')->getValue($response);

        self::assertSame(
            ['Allow: GET, HEAD'],
            array_map(static fn(Header $h): string => $h->line(), $headers),
        );
    }

    /**
     * @return void
     */
    public function testAGetStillDispatchesNormally(): void
    {
        $response = new Router(RouteInitialization::routes())->dispatch($this->request('GET'));

        self::assertNotInstanceOf(PlainTextResponse::class, $response);
    }

    // ───────────────────────── content security policy ─────────────────────────

    /**
     * The directive that actually stops XSS — no 'unsafe-inline', no 'unsafe-eval'.
     *
     * @return void
     */
    public function testScriptSrcIsStrict(): void
    {
        self::assertStringContainsString("script-src 'self';", self::policy() . ';');
        self::assertDoesNotMatchRegularExpression(
            "/script-src[^;]*'unsafe-(inline|eval)'/",
            self::policy(),
        );
    }

    /**
     * @return void
     */
    public function testThePolicyDeniesEverythingByDefault(): void
    {
        self::assertStringStartsWith("default-src 'self'", self::policy());
    }

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
     * The allowance that covered nothing.
     *
     * `data:` sat in `img-src` on the strength of a comment saying the cover placeholder needed
     * it. The placeholder references nothing, and no page or stylesheet emits a `data:` image, so
     * the directive was wider than the site for no benefit. Asserted as an absence because that
     * is the whole claim — and because a scheme source is exactly the kind of thing that gets
     * pasted back in by anyone debugging an image that will not load.
     *
     * @return void
     */
    public function testImagesMayNotBeInlinedAsDataUris(): void
    {
        self::assertStringNotContainsString('data:', self::policy());
    }

    /**
     * @return void
     */
    public function testOnlySoundCloudMayBeFramed(): void
    {
        self::assertStringContainsString('frame-src https://w.soundcloud.com', self::policy());
    }

    /**
     * @return void
     */
    public function testTheSiteItselfMayNotBeFramed(): void
    {
        self::assertStringContainsString("frame-ancestors 'none'", self::policy());
    }

    /**
     * The allowance is gone, and this is what keeps it gone. Reintroducing an inline style
     * anywhere would fail the test below rather than quietly get a directive loosened for it.
     *
     * @return void
     */
    public function testStyleSrcIsStrict(): void
    {
        self::assertStringContainsString("style-src 'self'", self::policy());
        self::assertStringNotContainsString("'unsafe-inline'", self::policy());
    }

    /**
     * `style-src` carried 'unsafe-inline' until SoundCloud's attribution block moved into
     * <soundcloud-player>, which sets the same properties through the CSSOM instead. Nothing may
     * put the allowance back by needing it, so assert the views still emit no inline style.
     *
     * @return void
     */
    public function testNoViewEmitsAnInlineStyleOrEventHandler(): void
    {
        $release = new ReleaseRepository()->find('ill');
        $html = new ReleaseView($release, 'ill')->content()->render()
            . new NotFoundView('/x')->content()->render()
            . Layout::wrap(new NotFoundView('/x'))->render();

        self::assertDoesNotMatchRegularExpression('/\sstyle="/', $html);
        self::assertDoesNotMatchRegularExpression('/\son(error|click|load|mouse\w+)=/', $html);
    }

    /**
     * @return void
     */
    public function testTheCoverFallbackIsAnAttributeNotAnInlineHandler(): void
    {
        $release = new ReleaseRepository()->find('ill');
        $html = new ReleaseView($release, 'ill')->content()->render();

        self::assertStringContainsString('fallback="/assets/img/cover-placeholder.svg"', $html);
        self::assertStringNotContainsString('onerror', $html);
    }

    /**
     * @return void
     */
    public function testTheConsentGateCarriesItsHeightAsAnAttribute(): void
    {
        $release = new ReleaseRepository()->find('ill');
        $html = new ReleaseView($release, 'ill')->content()->render();

        self::assertStringContainsString('height="300"', $html);
    }

    // ───────────────────────── the other headers ─────────────────────────

    // header() is a no-op under CLI, so that the headers are actually *sent* is asserted
    // over real HTTP in test/basic_test.sh. What's testable here is the policy they carry.

    /**
     * A cheap guard against a CDN sneaking into the policy in a future edit.
     *
     * @return void
     */
    public function testThePolicyNamesNoUnexpectedHost(): void
    {
        self::assertSame(
            ['https://my.hidrive.com', 'https://w.soundcloud.com'],
            SecurityHeaders::contentSecurityPolicy()->hosts(),
        );
    }

    // ───────────────────────── the other headers ─────────────────────────

    /**
     * @return void
     */
    public function testEveryHeaderIsSentAndNamedByTheEnum(): void
    {
        self::assertSame(
            array_map(static fn(SecurityHeader $h): string => $h->value, SecurityHeader::cases()),
            array_keys(SecurityHeaders::headers()),
        );
    }

    /**
     * @return void
     */
    public function testNoHeaderIsSentEmpty(): void
    {
        foreach (SecurityHeaders::headers() as $name => $value) {
            self::assertNotSame('', $value, "$name is sent with an empty value");
        }
    }

    /**
     * @return void
     */
    public function testReferrerPolicyKeepsThePathOffCrossOriginRequests(): void
    {
        self::assertSame(
            ReferrerPolicy::StrictOriginWhenCrossOrigin->value,
            self::header(SecurityHeader::ReferrerPolicy),
        );
    }

    /**
     * @return void
     */
    public function testContentTypeOptionsIsNosniff(): void
    {
        self::assertSame(
            ContentTypeOptions::NoSniff->value,
            self::header(SecurityHeader::ContentTypeOptions),
        );
    }

    /**
     * @return void
     */
    public function testEveryKnownFeatureIsDenied(): void
    {
        $policy = self::header(SecurityHeader::PermissionsPolicy);

        foreach (PermissionsPolicyFeature::cases() as $feature) {
            self::assertStringContainsString($feature->denied(), $policy);
        }
    }

    /**
     * Permissions-Policy applies to framed documents too, and the player's iframe asks for
     * `autoplay; encrypted-media`. Denying either -- which adding a case to
     * PermissionsPolicyFeature would do, since the policy denies every case -- switches the
     * player off with no error anywhere. Tie the two together so that can't happen quietly.
     */
    /*
     * The Permissions-Policy is built with denyAll(), so adding a case to PermissionsPolicyFeature
     * would deny that feature everywhere — including inside the SoundCloud iframe, which asks for
     * autoplay and encrypted-media, and would switch the player off with no error anywhere.
     *
     * That iframe is built by <soundcloud-player> now, so the assertion lives in
     * test/js/soundcloud-player.test.mjs: it reads the real allow= off the real element and checks
     * it against the header this class sends. It did not go away, it moved to what it guards.
     */

    // ───────────────────────── the unmatched path ─────────────────────────

    /**
     * The fall-through after every route has been tried. A router that returned null here would
     * hand a null to Response::send(); a 404 is the only answer that is still a response.
     *
     * @return void
     */
    public function testAPathNoRouteMatchesFallsThroughToTheNotFoundPage(): void
    {
        $response = new Router(RouteInitialization::routes())
            ->dispatch($this->request('GET', '/no-such-page'));

        self::assertInstanceOf(ViewResponse::class, $response);
        self::assertSame(
            HttpStatusCode::NotFound,
            new ReflectionProperty($response, 'status')->getValue($response),
        );
    }

    /**
     * The 404 reports the path that was asked for, and it is the normalised one.
     *
     * @return void
     */
    public function testTheNotFoundPageNamesThePathThatWasAskedFor(): void
    {
        $response = new Router(RouteInitialization::routes())
            ->dispatch($this->request('GET', '/no-such-page/'));

        $view = new ReflectionProperty($response, 'view')->getValue($response);

        self::assertInstanceOf(NotFoundView::class, $view);
        self::assertStringContainsString('/no-such-page', $view->content()->render());
    }

    /**
     * Every header a response sends is formatted in one place rather than at each header() call.
     *
     * @return void
     */
    public function testAHeaderFormatsItselfAsNameColonValue(): void
    {
        self::assertSame(
            'Allow: GET, HEAD',
            new Header(ResponseHeader::Allow, Allow::readOnly())->line(),
        );
    }

    /**
     * Each name goes on the wire as its backing value; there is no second spelling anywhere.
     *
     * @return void
     */
    public function testEveryResponseHeaderIsNamedAsItGoesOnTheWire(): void
    {
        foreach (ResponseHeader::cases() as $header) {
            self::assertSame($header->value, $header->headerName());
        }
    }
}
