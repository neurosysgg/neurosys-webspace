<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Http\HttpStatusCode;
use NeuroSYS\Http\PlainTextResponse;
use NeuroSYS\Http\Request;
use NeuroSYS\Http\Security\ContentTypeOptions;
use NeuroSYS\Http\Security\PermissionsPolicyFeature;
use NeuroSYS\Http\Security\ReferrerPolicy;
use NeuroSYS\Http\SecurityHeader;
use NeuroSYS\Http\SecurityHeaders;
use NeuroSYS\Model\Embed\SoundCloudEmbed;
use NeuroSYS\Router;
use NeuroSYS\Support\RouteInitialization;
use NeuroSYS\View\NotFoundView;
use NeuroSYS\View\ReleaseView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(SecurityHeaders::class)]
#[CoversClass(Router::class)]
final class SecurityTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $serverBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
    }

    private function request(string $method, string $path = '/'): Request
    {
        $_SERVER = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path];
        return Request::fromGlobals();
    }

    /** The rendered Content-Security-Policy, as it is actually sent. */
    private static function policy(): string
    {
        return SecurityHeaders::headers()[SecurityHeader::ContentSecurityPolicy->value];
    }

    private static function header(SecurityHeader $header): string
    {
        return SecurityHeaders::headers()[$header->value];
    }

    // ───────────────────────── read-only method gate ─────────────────────────

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

    #[DataProvider('readOnlyProvider')]
    public function testOnlyReadMethodsAreTreatedAsReadOnly(string $method, bool $expected): void
    {
        self::assertSame($expected, $this->request($method)->isReadOnly());
    }

    public function testTheMethodIsUpperCased(): void
    {
        self::assertSame('GET', $this->request('get')->method());
    }

    public function testAMissingRequestMethodDefaultsToGet(): void
    {
        $_SERVER = ['REQUEST_URI' => '/'];

        self::assertSame('GET', Request::fromGlobals()->method());
    }

    /** Before this, POST /releases/ill/flac 303'd to HiDrive exactly like a GET. */
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

    public static function writeMethodProvider(): iterable
    {
        yield ['POST', '/releases/hello-world/flac'];
        yield ['DELETE', '/releases/hello-world/flac'];
        yield ['PUT', '/'];
        yield ['POST', '/admin/stats'];
        yield ['POST', '/no-such-page'];
    }

    /** A 405 without an Allow header is a malformed 405. */
    public function testTheRefusalNamesTheAllowedMethods(): void
    {
        $response = new Router(RouteInitialization::routes())
            ->dispatch($this->request('POST', '/'));

        $headers = new ReflectionProperty($response, 'headers')->getValue($response);

        self::assertContains('Allow: GET, HEAD', $headers);
    }

    public function testAGetStillDispatchesNormally(): void
    {
        $response = new Router(RouteInitialization::routes())->dispatch($this->request('GET', '/'));

        self::assertNotInstanceOf(PlainTextResponse::class, $response);
    }

    // ───────────────────────── content security policy ─────────────────────────

    /** The directive that actually stops XSS — no 'unsafe-inline', no 'unsafe-eval'. */
    public function testScriptSrcIsStrict(): void
    {
        self::assertStringContainsString("script-src 'self';", self::policy() . ';');
        self::assertDoesNotMatchRegularExpression(
            "/script-src[^;]*'unsafe-(inline|eval)'/",
            self::policy(),
        );
    }

    public function testThePolicyDeniesEverythingByDefault(): void
    {
        self::assertStringStartsWith("default-src 'self'", self::policy());
    }

    public function testOnlyTheFileHostMayServeImages(): void
    {
        self::assertMatchesRegularExpression(
            "#img-src 'self' data: https://my\.hidrive\.com#",
            self::policy(),
        );
    }

    public function testOnlySoundCloudMayBeFramed(): void
    {
        self::assertStringContainsString('frame-src https://w.soundcloud.com', self::policy());
    }

    public function testTheSiteItselfMayNotBeFramed(): void
    {
        self::assertStringContainsString("frame-ancestors 'none'", self::policy());
    }

    /**
     * `style-src` keeps 'unsafe-inline' only because SoundCloudEmbed reproduces SoundCloud's
     * attribution markup verbatim. If our *own* views ever grow an inline style, that
     * justification stops being the whole story — so assert they still have none.
     */
    public function testNoViewEmitsAnInlineStyleOrEventHandler(): void
    {
        $release = new \NeuroSYS\Service\ReleaseRepository()->find('ill');
        $html = new ReleaseView($release, 'ill')->content()
            . new NotFoundView('/x')->content()
            . \NeuroSYS\Layout::wrap(new NotFoundView('/x'));

        self::assertDoesNotMatchRegularExpression('/\sstyle="/', $html);
        self::assertDoesNotMatchRegularExpression('/\son(error|click|load|mouse\w+)=/', $html);
    }

    public function testTheCoverFallbackIsAnAttributeNotAnInlineHandler(): void
    {
        $release = new \NeuroSYS\Service\ReleaseRepository()->find('ill');
        $html = new ReleaseView($release, 'ill')->content();

        self::assertStringContainsString('fallback="/assets/img/cover-placeholder.svg"', $html);
        self::assertStringNotContainsString('onerror', $html);
    }

    public function testTheConsentGateCarriesItsHeightAsAnAttribute(): void
    {
        $release = new \NeuroSYS\Service\ReleaseRepository()->find('ill');
        $html = new ReleaseView($release, 'ill')->content();

        self::assertStringContainsString('height="300"', $html);
    }

    // ───────────────────────── the other headers ─────────────────────────

    // header() is a no-op under CLI, so that the headers are actually *sent* is asserted
    // over real HTTP in test/basic_test.sh. What's testable here is the policy they carry.

    /** A cheap guard against a CDN sneaking into the policy in a future edit. */
    public function testThePolicyNamesNoUnexpectedHost(): void
    {
        self::assertSame(
            ['https://my.hidrive.com', 'https://w.soundcloud.com'],
            SecurityHeaders::contentSecurityPolicy()->hosts(),
        );
    }

    // ───────────────────────── the other headers ─────────────────────────

    public function testEveryHeaderIsSentAndNamedByTheEnum(): void
    {
        self::assertSame(
            array_map(static fn(SecurityHeader $h): string => $h->value, SecurityHeader::cases()),
            array_keys(SecurityHeaders::headers()),
        );
    }

    public function testNoHeaderIsSentEmpty(): void
    {
        foreach (SecurityHeaders::headers() as $name => $value) {
            self::assertNotSame('', $value, "$name is sent with an empty value");
        }
    }

    public function testReferrerPolicyKeepsThePathOffCrossOriginRequests(): void
    {
        self::assertSame(
            ReferrerPolicy::StrictOriginWhenCrossOrigin->value,
            self::header(SecurityHeader::ReferrerPolicy),
        );
    }

    public function testContentTypeOptionsIsNosniff(): void
    {
        self::assertSame(
            ContentTypeOptions::NoSniff->value,
            self::header(SecurityHeader::ContentTypeOptions),
        );
    }

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
}
