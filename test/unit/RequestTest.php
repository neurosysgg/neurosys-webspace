<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Http\Request;
use NeuroSYS\Http\RequestHeader;
use NeuroSYS\Http\RequestedWith;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Request::class)]
#[CoversClass(RequestHeader::class)]
#[CoversClass(RequestedWith::class)]
final class RequestTest extends TestCase
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

    /** @param array<string, string> $server */
    private function request(array $server): Request
    {
        $_SERVER = $server;
        return Request::fromGlobals();
    }

    public static function pathProvider(): iterable
    {
        yield 'root'                  => ['/', '/'];
        yield 'trailing slash dropped' => ['/releases/', '/releases'];
        yield 'nested trailing slash'  => ['/releases/ill/', '/releases/ill'];
        yield 'query string stripped'  => ['/releases?sort=new', '/releases'];
        yield 'fragment stripped'      => ['/releases#top', '/releases'];
        yield 'bare slash stays root'  => ['/', '/'];
        yield 'deep path'              => ['/releases/ill/flac', '/releases/ill/flac'];
    }

    #[DataProvider('pathProvider')]
    public function testNormalisesPath(string $uri, string $expected): void
    {
        self::assertSame($expected, $this->request(['REQUEST_URI' => $uri])->path());
    }

    public function testDefaultsToRootWhenRequestUriIsAbsent(): void
    {
        self::assertSame('/', $this->request([])->path());
    }

    public function testDetectsAjaxRequestCaseInsensitively(): void
    {
        self::assertTrue($this->request([
            'REQUEST_URI'          => '/',
            'HTTP_X_REQUESTED_WITH' => 'XmlHttpRequest',
        ])->isAjax());
    }

    public function testIsNotAjaxWithoutTheHeader(): void
    {
        self::assertFalse($this->request(['REQUEST_URI' => '/'])->isAjax());
    }

    public function testIsNotAjaxForSomeOtherRequestedWithValue(): void
    {
        self::assertFalse($this->request([
            'REQUEST_URI'           => '/',
            'HTTP_X_REQUESTED_WITH' => 'fetch',
        ])->isAjax());
    }

    public function testReadsPhpAuthVariablesWhenPresent(): void
    {
        $request = $this->request([
            'REQUEST_URI'   => '/admin/stats',
            'PHP_AUTH_USER' => 'admin',
            'PHP_AUTH_PW'   => 'hunter2',
        ]);

        self::assertSame('admin', $request->authUser());
        self::assertSame('hunter2', $request->authPassword());
    }

    /**
     * Strato strips PHP_AUTH_* before it reaches PHP, so .htaccess forwards the raw
     * Authorization header instead. Without this fallback /admin/stats is unreachable
     * in production while working fine locally.
     */
    public function testFallsBackToTheAuthorizationHeader(): void
    {
        $request = $this->request([
            'REQUEST_URI'        => '/admin/stats',
            'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('admin:hunter2'),
        ]);

        self::assertSame('admin', $request->authUser());
        self::assertSame('hunter2', $request->authPassword());
    }

    public function testAuthorizationHeaderPasswordMayContainColons(): void
    {
        $request = $this->request([
            'REQUEST_URI'        => '/admin/stats',
            'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('admin:a:b:c'),
        ]);

        self::assertSame('admin', $request->authUser());
        self::assertSame('a:b:c', $request->authPassword());
    }

    public function testAuthorizationHeaderWithoutAColonYieldsAnEmptyPassword(): void
    {
        $request = $this->request([
            'REQUEST_URI'        => '/admin/stats',
            'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('adminonly'),
        ]);

        self::assertSame('adminonly', $request->authUser());
        self::assertSame('', $request->authPassword());
    }

    public function testIgnoresNonBasicAuthorizationSchemes(): void
    {
        $request = $this->request([
            'REQUEST_URI'        => '/admin/stats',
            'HTTP_AUTHORIZATION' => 'Bearer some-token',
        ]);

        self::assertSame('', $request->authUser());
        self::assertSame('', $request->authPassword());
    }

    public function testGarbageInTheAuthorizationHeaderDoesNotCrash(): void
    {
        $request = $this->request([
            'REQUEST_URI'        => '/admin/stats',
            'HTTP_AUTHORIZATION' => 'Basic !!!not-base64!!!',
        ]);

        self::assertSame('', $request->authPassword());
    }

    public function testPhpAuthUserWinsOverTheHeaderFallback(): void
    {
        $request = $this->request([
            'REQUEST_URI'        => '/admin/stats',
            'PHP_AUTH_USER'      => 'real',
            'PHP_AUTH_PW'        => 'realpass',
            'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('spoofed:spoofedpass'),
        ]);

        self::assertSame('real', $request->authUser());
        self::assertSame('realpass', $request->authPassword());
    }

    public function testCredentialsAreEmptyWhenNoneAreSupplied(): void
    {
        $request = $this->request(['REQUEST_URI' => '/']);

        self::assertSame('', $request->authUser());
        self::assertSame('', $request->authPassword());
    }

    // ───────────────────── the header that asks for a fragment ─────────────────────

    /**
     * The worst name on the site to get wrong. Drift on either side and the server answers a SPA
     * fetch with a whole document, which Navigation then writes into <main> — a page broken in a
     * way nothing reports. `assets/ts/model/RequestHeader.ts` mirrors this and the parity test
     * compares them; what belongs here is that the wire name is the value.
     */
    public function testTheRequestedWithHeaderIsNamedAsItGoesOnTheWire(): void
    {
        self::assertSame('X-Requested-With', RequestHeader::RequestedWith->headerName());
        self::assertSame(RequestHeader::RequestedWith->value, RequestHeader::RequestedWith->headerName());
    }

    /**
     * fromGlobals() derives the $_SERVER key from the case rather than retyping it, because that
     * transform is PHP's rather than ours. This is the derivation, spelled out once.
     */
    public function testTheServerKeyIsDerivedFromTheHeaderName(): void
    {
        self::assertSame(
            'HTTP_X_REQUESTED_WITH',
            'HTTP_' . str_replace('-', '_', strtoupper(RequestHeader::RequestedWith->headerName())),
        );
    }

    /**
     * The header is conventional rather than standard and libraries disagree on its casing, so
     * the rule lives on the enum instead of as a strtolower() at the one call site that
     * remembers it.
     */
    #[DataProvider('requestedWithProvider')]
    public function testTheRequestedWithValueIsMatchedWhateverCaseItArrivesIn(
        string $header,
        bool $expected,
    ): void {
        self::assertSame($expected, RequestedWith::XmlHttpRequest->matches($header));
    }

    public static function requestedWithProvider(): iterable
    {
        yield 'as sent'    => ['XMLHttpRequest', true];
        yield 'lower'      => ['xmlhttprequest', true];
        yield 'mixed'      => ['XmlHttpRequest', true];
        yield 'upper'      => ['XMLHTTPREQUEST', true];
        yield 'fetch'      => ['fetch', false];
        yield 'empty'      => ['', false];
        yield 'substring'  => ['not-XMLHttpRequest', false];
    }
}
