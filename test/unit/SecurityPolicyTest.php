<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Exception\SecurityPolicyException;
use NeuroSYS\Http\Allow;
use NeuroSYS\Http\BasicChallenge;
use NeuroSYS\Http\CacheControl;
use NeuroSYS\Http\CacheDirective;
use NeuroSYS\Http\ETag;
use NeuroSYS\Http\Header;
use NeuroSYS\Http\HeaderValue;
use NeuroSYS\Http\HttpMethod;
use NeuroSYS\Http\Location;
use NeuroSYS\Http\MimeType;
use NeuroSYS\Http\RequestHeader;
use NeuroSYS\Http\ResponseHeader;
use NeuroSYS\Http\SecurityHeader;
use NeuroSYS\Http\Security\ContentSecurityPolicy;
use NeuroSYS\Http\Security\ContentTypeOptions;
use NeuroSYS\Http\Security\CspDirective;
use NeuroSYS\Http\Security\CspHost;
use NeuroSYS\Http\Security\CspKeyword;
use NeuroSYS\Http\Security\CspScheme;
use NeuroSYS\Http\Security\CspSource;
use NeuroSYS\Http\Security\PermissionsPolicy;
use NeuroSYS\Http\Security\PermissionsPolicyFeature;
use NeuroSYS\Http\Security\ReferrerPolicy;
use NeuroSYS\Http\Security\StrictTransportSecurity;
use NeuroSYS\Http\Vary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

#[CoversClass(ContentSecurityPolicy::class)]
#[CoversClass(CspHost::class)]
#[CoversClass(CspKeyword::class)]
#[CoversClass(CspScheme::class)]
#[CoversClass(CspDirective::class)]
#[CoversClass(PermissionsPolicy::class)]
#[CoversClass(PermissionsPolicyFeature::class)]
#[CoversClass(SecurityHeader::class)]
#[CoversClass(StrictTransportSecurity::class)]
final class SecurityPolicyTest extends TestCase
{
    // ───────────────────────── StrictTransportSecurity ─────────────────────────

    public function testTheTransportPolicyRendersItsMaxAgeAndSubdomains(): void
    {
        self::assertSame(
            'max-age=31536000; includeSubDomains',
            new StrictTransportSecurity()->render(),
        );
    }

    public function testSubdomainsCanBeLeftOutForAnEstateThatNeedsIt(): void
    {
        self::assertSame(
            'max-age=86400',
            new StrictTransportSecurity(StrictTransportSecurity::ONE_DAY, includeSubDomains: false)->render(),
        );
    }

    /** Zero is the documented way to switch the policy off, so it is a value and not an error. */
    public function testAZeroMaxAgeIsAllowed(): void
    {
        self::assertSame('max-age=0; includeSubDomains', new StrictTransportSecurity(0)->render());
    }

    /**
     * A negative max-age is a header the browser discards, which is worse than no header: it reads
     * as protection that is present when there is none.
     */
    public function testANegativeMaxAgeIsRefused(): void
    {
        $this->expectException(SecurityPolicyException::class);

        new StrictTransportSecurity(-1);
    }

    /**
     * What the site actually sends, rather than what the class can express.
     *
     * A year is the value that makes the policy worth having; anything shorter leaves a window
     * where a visitor who has not been back lately still sends the Basic Auth header in the clear.
     * Asserted as a floor, so shipping the ONE_DAY ramp value by accident fails here.
     */
    public function testTheSiteSendsAtLeastAYearAndCoversSubdomains(): void
    {
        $sent = \NeuroSYS\Http\SecurityHeaders::headers()[SecurityHeader::StrictTransportSecurity->value];

        self::assertMatchesRegularExpression('/^max-age=(\d+); includeSubDomains$/', $sent);
        self::assertGreaterThanOrEqual(
            StrictTransportSecurity::ONE_YEAR,
            (int) preg_replace('/\D/', '', explode(';', $sent)[0] ?? ''),
        );
    }

    /**
     * Preload is deliberately not offered — see the class docblock. It ships the host inside the
     * browser binary, where nothing this server sends can take it back, so it is a decision to make
     * on purpose rather than a flag to pass on the way past.
     */
    public function testThePolicyDoesNotClaimToBePreloaded(): void
    {
        self::assertStringNotContainsStringIgnoringCase(
            'preload',
            new StrictTransportSecurity()->render(),
        );
    }

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
        yield 'newline'         => ["https://example.com\n"];
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

        (void) new ContentSecurityPolicy()->allow(CspDirective::ScriptSrc);
    }

    /** A browser honours the first occurrence, so a second one would silently do nothing. */
    public function testADirectiveCannotBeSetTwice(): void
    {
        $this->expectException(SecurityPolicyException::class);
        $this->expectExceptionMessage('already set');

        (void) new ContentSecurityPolicy()
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

    // ───────────────────────────── header values ─────────────────────────────

    /**
     * Every header value is an object that knows its own grammar, and `Header` will not take
     * anything else. Pinned in both directions: each of these must implement the interface, and
     * nothing that reaches `Header` may be a bare string.
     */
    #[DataProvider('headerValueProvider')]
    public function testAHeaderValueRendersItsOwnGrammar(string $expected, HeaderValue $value): void
    {
        self::assertSame($expected, $value->render());
    }

    /** @return iterable<string, array{string, HeaderValue}> */
    public static function headerValueProvider(): iterable
    {
        yield 'the revalidating document' => ['no-cache', CacheControl::revalidate()];
        yield 'the gated page'            => ['no-store, private', CacheControl::doNotStore()];
        yield 'one directive'             => ['private', CacheControl::of(CacheDirective::Private)];

        // The quotes are grammar, not decoration: `ETag: abc` is a different header from `ETag: "abc"`.
        yield 'a validator is quoted'     => ['"' . hash('xxh128', 'x') . '"', ETag::forBody('x')];

        yield 'what the body depends on'  => ['X-Requested-With', Vary::on(RequestHeader::RequestedWith)];
        yield 'what the site accepts'     => ['GET, HEAD', Allow::readOnly()];
        yield 'the realm, quoted'         => ['Basic realm="neuro.SYS"', new BasicChallenge('neuro.SYS')];
        yield 'where a download goes'     => ['https://x.example/f?id=1', new Location('https://x.example/f?id=1')];
        yield 'a media type'              => ['text/html; charset=utf-8', MimeType::html()];
        yield 'a single-value enum'       => ['nosniff', ContentTypeOptions::NoSniff];

        // The four that already rendered before the interface existed. They are here as well as in
        // their own tests above, because this table is the one place that answers "what can the
        // site put after a colon?" — and the audit below is what keeps it able to answer.
        yield 'the transport policy'      => [
            'max-age=31536000; includeSubDomains',
            new StrictTransportSecurity(),
        ];
        yield 'a content policy'          => [
            "default-src 'self'",
            new ContentSecurityPolicy()->allow(CspDirective::DefaultSrc, CspKeyword::SelfOrigin),
        ];
        yield 'a referrer policy'         => [
            'strict-origin-when-cross-origin',
            ReferrerPolicy::StrictOriginWhenCrossOrigin,
        ];
        yield 'a denied feature'          => [
            'geolocation=()',
            PermissionsPolicy::deny(PermissionsPolicyFeature::Geolocation),
        ];
    }

    /**
     * The set of header values, pinned in both directions.
     *
     * The same instinct as {@link NoDiscardTest}'s list and {@link HtmlTest}'s URL-attribute audit:
     * a value that reaches {@link Header} without implementing the interface can no longer compile,
     * but a *new* implementer that nobody remembered to cover would be a grammar nothing checks.
     * Adding one means adding it to {@link self::headerValueProvider()} too, and this is what says
     * so.
     */
    public function testExactlyTheseAreHeaderValues(): void
    {
        $found = [];

        foreach (self::classesUnderSrc() as $class) {
            if (is_a($class, HeaderValue::class, allow_string: true)) {
                $found[] = $class;
            }
        }
        sort($found);

        self::assertSame(
            [
                'NeuroSYS\Http\Allow',
                'NeuroSYS\Http\BasicChallenge',
                'NeuroSYS\Http\CacheControl',
                'NeuroSYS\Http\ETag',
                'NeuroSYS\Http\Location',
                'NeuroSYS\Http\MimeType',
                'NeuroSYS\Http\Security\ContentSecurityPolicy',
                'NeuroSYS\Http\Security\ContentTypeOptions',
                'NeuroSYS\Http\Security\PermissionsPolicy',
                'NeuroSYS\Http\Security\ReferrerPolicy',
                'NeuroSYS\Http\Security\StrictTransportSecurity',
                'NeuroSYS\Http\Vary',
            ],
            $found,
        );
    }

    /** And every one of them is exercised above. */
    public function testEveryHeaderValueIsCovered(): void
    {
        $covered = [];

        foreach (self::headerValueProvider() as [, $value]) {
            $covered[] = $value::class;
        }

        $found = [];
        foreach (self::classesUnderSrc() as $class) {
            if (is_a($class, HeaderValue::class, allow_string: true)) {
                $found[] = $class;
            }
        }

        self::assertSame([], array_values(array_diff($found, $covered)));
    }

    /**
     * Every class, interface, enum and trait under `src/`, derived from its path.
     *
     * The same walk {@link NoDiscardTest::classesUnderSrc()} does, and for the same reason: the
     * autoloader maps path to name, so a file this cannot name is one the site could not load.
     *
     * @return list<class-string>
     */
    private static function classesUnderSrc(): array
    {
        $root  = NEUROSYS_ROOT . '/src/NeuroSYS/';
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        $classes = [];

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root), -strlen('.php'));
            $class    = 'NeuroSYS\\' . str_replace('/', '\\', $relative);

            if (class_exists($class) || enum_exists($class)) {
                /** @var class-string $class */
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /** An empty list is a malformed header rather than a permissive one — the same rule PermissionsPolicy has. */
    public function testAnEmptyCacheControlIsRefused(): void
    {
        $this->expectException(SecurityPolicyException::class);
        $this->expectExceptionMessage('at least one directive');

        CacheControl::of();
    }

    public function testAnEmptyVaryIsRefused(): void
    {
        $this->expectException(SecurityPolicyException::class);
        $this->expectExceptionMessage('at least one header');

        Vary::on();
    }

    /**
     * A `Location` is the one address the site emits that nothing used to look at.
     *
     * Narrower than the spec on purpose: every redirect here goes to the file host, absolute and
     * over TLS. The newline case is the one that would matter most — PHP's `header()` refuses one
     * anyway, but a validator that does not mean what it says is worth closing regardless.
     */
    #[DataProvider('badLocationProvider')]
    public function testALocationMustBeAnAbsoluteHttpsUrl(string $url): void
    {
        $this->expectException(SecurityPolicyException::class);

        new Location($url);
    }

    /** @return iterable<string, array{string}> */
    public static function badLocationProvider(): iterable
    {
        yield 'relative'          => ['/releases'];
        yield 'protocol-relative' => ['//evil.example/x'];
        yield 'plaintext'         => ['http://x.example/'];
        yield 'a scheme that runs script' => ['javascript:alert(1)'];
        yield 'trailing newline'  => ["https://x.example/\n"];
        yield 'an embedded space' => ['https://x.example/a b'];
        yield 'empty'             => [''];
    }

    public function testAHeaderFormatsItsOwnLine(): void
    {
        self::assertSame(
            'X-Content-Type-Options: nosniff',
            new Header(SecurityHeader::ContentTypeOptions, ContentTypeOptions::NoSniff)->line(),
        );
    }

    /** Header takes any HeaderName, which is the whole reason the interface exists. */
    public function testAHeaderFormatsAResponseHeaderTheSameWay(): void
    {
        self::assertSame(
            'Allow: GET, HEAD',
            new Header(ResponseHeader::Allow, Allow::readOnly())->line(),
        );
    }

    public function testEveryHeaderNameLooksLikeAHeaderName(): void
    {
        foreach (SecurityHeader::cases() as $header) {
            self::assertMatchesRegularExpression('/^[A-Za-z][A-Za-z0-9-]*$/', $header->value);
        }
    }
}
