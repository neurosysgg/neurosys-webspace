<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Exception\SecurityPolicyException;
use NeuroSYS\Http\Security\ContentSecurityPolicy;
use NeuroSYS\Http\Security\CspDirective;
use NeuroSYS\Http\Security\CspHost;
use NeuroSYS\Http\Security\CspKeyword;
use NeuroSYS\Http\Security\CspScheme;
use NeuroSYS\Http\Security\CspSource;
use NeuroSYS\Http\Security\PermissionsPolicy;
use NeuroSYS\Http\Security\PermissionsPolicyFeature;
use NeuroSYS\Http\SecurityHeader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContentSecurityPolicy::class)]
#[CoversClass(CspHost::class)]
#[CoversClass(CspKeyword::class)]
#[CoversClass(CspScheme::class)]
#[CoversClass(CspDirective::class)]
#[CoversClass(PermissionsPolicy::class)]
#[CoversClass(PermissionsPolicyFeature::class)]
#[CoversClass(SecurityHeader::class)]
final class SecurityPolicyTest extends TestCase
{
    // ───────────────────────── CspHost ─────────────────────────

    public function testAcceptsABareOrigin(): void
    {
        self::assertSame('https://my.hidrive.com', new CspHost('https://my.hidrive.com')->source());
    }

    public static function validOriginProvider(): iterable
    {
        yield 'https'             => ['https://example.com'];
        yield 'http'              => ['http://example.com'];
        yield 'subdomain'         => ['https://w.soundcloud.com'];
        yield 'wildcard'          => ['https://*.example.com'];
        yield 'port'              => ['https://example.com:8443'];
        yield 'hyphenated'        => ['https://my-cdn.example.com'];
        yield 'deep subdomain'    => ['https://a.b.c.example.com'];
    }

    #[DataProvider('validOriginProvider')]
    public function testAcceptsEveryWellFormedOrigin(string $origin): void
    {
        self::assertSame($origin, new CspHost($origin)->source());
    }

    public static function invalidOriginProvider(): iterable
    {
        yield 'trailing slash'  => ['https://example.com/'];
        yield 'with a path'     => ['https://my.hidrive.com/api/sharelink/download'];
        yield 'with a query'    => ['https://example.com?a=b'];
        yield 'no scheme'       => ['example.com'];
        yield 'scheme only'     => ['https://'];
        yield 'no dot'          => ['https://localhost'];
        yield 'empty'           => [''];
        yield 'a keyword'       => ["'self'"];
        yield 'space'           => ['https://exa mple.com'];
        yield 'javascript'      => ['javascript:alert(1)'];
    }

    /** Mirrors HiDriveLink: a bad paste has to fail where it is written, not on the wire. */
    #[DataProvider('invalidOriginProvider')]
    public function testRejectsAnythingThatIsNotABareOrigin(string $origin): void
    {
        $this->expectException(SecurityPolicyException::class);
        new CspHost($origin);
    }

    // ───────────────────────── sources ─────────────────────────

    public function testKeywordsCarryTheirQuotes(): void
    {
        self::assertSame("'self'", CspKeyword::SelfOrigin->source());
        self::assertSame("'none'", CspKeyword::None->source());
        self::assertSame("'unsafe-inline'", CspKeyword::UnsafeInline->source());
    }

    public function testSchemesCarryTheirColon(): void
    {
        self::assertSame('data:', CspScheme::Data->source());
        self::assertSame('https:', CspScheme::Https->source());
    }

    /** Keyword, scheme and host are interchangeable wherever a source is wanted. */
    public function testAllThreeSourceKindsShareTheInterface(): void
    {
        $sources = [CspKeyword::SelfOrigin, CspScheme::Data, new CspHost('https://example.com')];

        foreach ($sources as $source) {
            self::assertInstanceOf(CspSource::class, $source);
            self::assertNotSame('', $source->source());
        }
    }

    // ───────────────────────── ContentSecurityPolicy ─────────────────────────

    public function testRendersDirectivesInInsertionOrder(): void
    {
        $policy = new ContentSecurityPolicy()
            ->allow(CspDirective::DefaultSrc, CspKeyword::SelfOrigin)
            ->allow(CspDirective::ObjectSrc, CspKeyword::None);

        self::assertSame("default-src 'self'; object-src 'none'", $policy->render());
    }

    public function testRendersMultipleSourcesSpaceSeparated(): void
    {
        $policy = new ContentSecurityPolicy()->allow(
            CspDirective::ImgSrc,
            CspKeyword::SelfOrigin,
            CspScheme::Data,
            new CspHost('https://my.hidrive.com'),
        );

        self::assertSame("img-src 'self' data: https://my.hidrive.com", $policy->render());
    }

    public function testAnEmptyPolicyRendersEmpty(): void
    {
        self::assertSame('', new ContentSecurityPolicy()->render());
    }

    public function testAllowIsImmutable(): void
    {
        $base = new ContentSecurityPolicy()->allow(CspDirective::DefaultSrc, CspKeyword::SelfOrigin);
        $extended = $base->allow(CspDirective::ObjectSrc, CspKeyword::None);

        self::assertSame("default-src 'self'", $base->render());
        self::assertNotSame($base, $extended);
        self::assertStringContainsString('object-src', $extended->render());
    }

    public function testADirectiveNeedsAtLeastOneSource(): void
    {
        $this->expectException(SecurityPolicyException::class);
        $this->expectExceptionMessage('CspKeyword::None');

        new ContentSecurityPolicy()->allow(CspDirective::ScriptSrc);
    }

    /** A browser honours the first occurrence, so a second one would silently do nothing. */
    public function testADirectiveCannotBeSetTwice(): void
    {
        $this->expectException(SecurityPolicyException::class);
        $this->expectExceptionMessage('already set');

        new ContentSecurityPolicy()
            ->allow(CspDirective::ScriptSrc, CspKeyword::SelfOrigin)
            ->allow(CspDirective::ScriptSrc, CspKeyword::UnsafeInline);
    }

    public function testHostsReportsOnlyHostSourcesAndDeduplicates(): void
    {
        $policy = new ContentSecurityPolicy()
            ->allow(CspDirective::ImgSrc, CspKeyword::SelfOrigin, new CspHost('https://a.example.com'))
            ->allow(CspDirective::FrameSrc, new CspHost('https://a.example.com'))
            ->allow(CspDirective::ScriptSrc, new CspHost('https://b.example.com'));

        self::assertSame(['https://a.example.com', 'https://b.example.com'], $policy->hosts());
    }

    public function testHostsIsEmptyForASelfOnlyPolicy(): void
    {
        $policy = new ContentSecurityPolicy()->allow(CspDirective::DefaultSrc, CspKeyword::SelfOrigin);

        self::assertSame([], $policy->hosts());
    }

    // ───────────────────────── PermissionsPolicy ─────────────────────────

    public function testDenyRendersEachFeatureAsDeniedToEveryone(): void
    {
        $policy = PermissionsPolicy::deny(
            PermissionsPolicyFeature::Geolocation,
            PermissionsPolicyFeature::Camera,
        );

        self::assertSame('geolocation=(), camera=()', $policy->render());
    }

    public function testDenyAllCoversEveryCase(): void
    {
        $rendered = PermissionsPolicy::denyAll()->render();

        foreach (PermissionsPolicyFeature::cases() as $feature) {
            self::assertStringContainsString($feature->denied(), $rendered);
        }
    }

    public function testDenyingNothingIsAnError(): void
    {
        $this->expectException(SecurityPolicyException::class);
        PermissionsPolicy::deny();
    }

    public function testAFeatureRendersAsAnEmptyAllowList(): void
    {
        self::assertSame('geolocation=()', PermissionsPolicyFeature::Geolocation->denied());
    }

    // ───────────────────────── SecurityHeader ─────────────────────────

    public function testTheHeaderEnumFormatsAHeaderLine(): void
    {
        self::assertSame(
            'X-Content-Type-Options: nosniff',
            SecurityHeader::ContentTypeOptions->line('nosniff'),
        );
    }

    public function testEveryHeaderNameLooksLikeAHeaderName(): void
    {
        foreach (SecurityHeader::cases() as $header) {
            self::assertMatchesRegularExpression('/^[A-Za-z][A-Za-z0-9-]*$/', $header->value);
        }
    }
}
