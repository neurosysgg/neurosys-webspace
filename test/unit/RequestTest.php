<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Request::class)]
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
}
