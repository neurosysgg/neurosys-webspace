<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Http\AcceptedLanguages;
use NeuroSYS\Http\AuthScheme;
use NeuroSYS\Http\BasicChallenge;
use NeuroSYS\Http\Request;
use NeuroSYS\Http\RequestedWith;
use NeuroSYS\Http\RequestHeader;
use NeuroSYS\Http\ServerVariable;
use NeuroSYS\View\Html\Language;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Request::class)]
#[CoversClass(AcceptedLanguages::class)]
#[CoversClass(AuthScheme::class)]
#[CoversClass(RequestHeader::class)]
#[CoversClass(RequestedWith::class)]
#[CoversClass(ServerVariable::class)]
final class RequestTest extends TestCase
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
     * @param array<string, string> $server
     * @return Request
     */
    private function request(array $server): Request
    {
        $_SERVER = $server;
        return Request::fromGlobals();
    }

    /**
     * @return iterable
     */
    public static function pathProvider(): iterable
    {
        yield 'root'                  => ['/', '/'];
        yield 'trailing slash dropped' => ['/releases/', '/releases'];
        yield 'nested trailing slash'  => ['/releases/ill/', '/releases/ill'];
        yield 'query string stripped'  => ['/releases?sort=new', '/releases'];
        yield 'fragment stripped'      => ['/releases#top', '/releases'];
        yield 'bare slash stays root'  => ['/', '/'];
        yield 'deep path'              => ['/releases/ill/flac', '/releases/ill/flac'];

        // parse_url() signals failure with false, not null, so `?? '/'` read as a guard and was not
        // one: the false reached rtrim() as an uncaught TypeError under strict_types, and every one
        // of these was a 500 rather than a page. They are ordinary request targets — the first is
        // three slashes — and they died in fromGlobals(), ahead of the read-only gate.
        //
        // Note the two different answers. `///` is the root written wastefully, and comes back as
        // the root. The second is a target we could not read, and comes back verbatim so that it
        // matches no route and 404s — answering it with the home page would be a quieter wrong.
        yield 'only slashes'              => ['///', '/'];
        yield 'unparseable authority'     => ['//host:notaport/x', '//host:notaport/x'];
        yield 'unparseable, trailing slash' => ['//host:notaport/x/', '//host:notaport/x'];
        yield 'empty'                     => ['', '/'];
    }

    /**
     * @param string $uri
     * @param string $expected
     * @return void
     */
    #[DataProvider('pathProvider')]
    public function testNormalisesPath(string $uri, string $expected): void
    {
        self::assertSame($expected, $this->request(['REQUEST_URI' => $uri])->path());
    }

    /**
     * @return void
     */
    public function testDefaultsToRootWhenRequestUriIsAbsent(): void
    {
        self::assertSame('/', $this->request([])->path());
    }

    /**
     * @return void
     */
    public function testDetectsAjaxRequestCaseInsensitively(): void
    {
        self::assertTrue($this->request([
            'REQUEST_URI'          => '/',
            'HTTP_X_REQUESTED_WITH' => 'XmlHttpRequest',
        ])->isAjax());
    }

    /**
     * @return void
     */
    public function testIsNotAjaxWithoutTheHeader(): void
    {
        self::assertFalse($this->request(['REQUEST_URI' => '/'])->isAjax());
    }

    /**
     * @return void
     */
    public function testIsNotAjaxForSomeOtherRequestedWithValue(): void
    {
        self::assertFalse($this->request([
            'REQUEST_URI'           => '/',
            'HTTP_X_REQUESTED_WITH' => 'fetch',
        ])->isAjax());
    }

    /**
     * @return void
     */
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
     *
     * @return void
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

    /**
     * @return void
     */
    public function testAuthorizationHeaderPasswordMayContainColons(): void
    {
        $request = $this->request([
            'REQUEST_URI'        => '/admin/stats',
            'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('admin:a:b:c'),
        ]);

        self::assertSame('admin', $request->authUser());
        self::assertSame('a:b:c', $request->authPassword());
    }

    /**
     * @return void
     */
    public function testAuthorizationHeaderWithoutAColonYieldsAnEmptyPassword(): void
    {
        $request = $this->request([
            'REQUEST_URI'        => '/admin/stats',
            'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('adminonly'),
        ]);

        self::assertSame('adminonly', $request->authUser());
        self::assertSame('', $request->authPassword());
    }

    /**
     * @return void
     */
    public function testIgnoresNonBasicAuthorizationSchemes(): void
    {
        $request = $this->request([
            'REQUEST_URI'        => '/admin/stats',
            'HTTP_AUTHORIZATION' => 'Bearer some-token',
        ]);

        self::assertSame('', $request->authUser());
        self::assertSame('', $request->authPassword());
    }

    /**
     * @return void
     */
    public function testGarbageInTheAuthorizationHeaderDoesNotCrash(): void
    {
        $request = $this->request([
            'REQUEST_URI'        => '/admin/stats',
            'HTTP_AUTHORIZATION' => 'Basic !!!not-base64!!!',
        ]);

        self::assertSame('', $request->authPassword());
    }

    /**
     * @return void
     */
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

    /**
     * @return void
     */
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
     *
     * @return void
     */
    public function testTheRequestedWithHeaderIsNamedAsItGoesOnTheWire(): void
    {
        self::assertSame('X-Requested-With', RequestHeader::RequestedWith->headerName());
        self::assertSame(RequestHeader::RequestedWith->value, RequestHeader::RequestedWith->headerName());
    }

    /**
     * fromGlobals() derives the $_SERVER key from the case rather than retyping it, because that
     * transform is PHP's rather than ours. This is the derivation, spelled out once.
     *
     * @return void
     */
    public function testTheServerKeyIsDerivedFromTheHeaderName(): void
    {
        self::assertSame(
            'HTTP_X_REQUESTED_WITH',
            'HTTP_' . RequestHeader::RequestedWith->headerName()
                |> strtoupper(...)
                |> (fn($x) => str_replace('-', '_', $x)),
        );
    }

    /**
     * The header is conventional rather than standard and libraries disagree on its casing, so
     * the rule lives on the enum instead of as a strtolower() at the one call site that
     * remembers it.
     *
     * @param string $header
     * @param bool $expected
     * @return void
     */
    #[DataProvider('requestedWithProvider')]
    public function testTheRequestedWithValueIsMatchedWhateverCaseItArrivesIn(
        string $header,
        bool $expected,
    ): void {
        self::assertSame($expected, RequestedWith::XmlHttpRequest->matches($header));
    }

    /**
     * @return iterable
     */
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

    // ───────────────────────────── ServerVariable ─────────────────────────────

    /**
     * The keys, spelled out once, against the names Apache and PHP actually use.
     *
     * This is the whole reason the enum exists, so it is asserted the same way {@link RequestHeader}
     * asserts its wire names: the value is the environment's spelling, and a case that drifts from
     * it reads at runtime as a request that simply did not carry the value.
     *
     * {@link ServerVariable::Referer} is the one worth looking at twice — one `r`, because HTTP
     * lost it in 1996 and never got it back, while the property it fills spells it correctly.
     *
     * @return void
     */
    public function testTheServerVariablesAreNamedAsTheEnvironmentSpellsThem(): void
    {
        self::assertSame([
            'REQUEST_METHOD',
            'REQUEST_URI',
            'PHP_AUTH_USER',
            'PHP_AUTH_PW',
            'HTTP_AUTHORIZATION',
            'REDIRECT_HTTP_AUTHORIZATION',
            'HTTP_REFERER',
        ], array_column(ServerVariable::cases(), 'value'));
    }

    /**
     * A key that is there comes back; one that is not comes back null rather than ''.
     *
     * Null and not the empty string, because the callers want different defaults out of the same
     * absence — `GET`, `/` and `''` — and a reader that picked one for them would have made
     * {@link Request::fromGlobals()} say `?: 'GET'`, which is a different question.
     *
     * @return void
     */
    public function testAServerVariableReadsItsKeyAndAnswersNullWhenItIsAbsent(): void
    {
        $_SERVER = ['REQUEST_URI' => '/releases'];

        self::assertSame('/releases', ServerVariable::RequestUri->string());
        self::assertNull(ServerVariable::RequestMethod->string());
    }

    /**
     * A key holding something that is not a string is the same as one that is not there.
     *
     * The guard the bare `$_SERVER['HTTP_REFERER'] ?? ''` in `DownloadLogger` never had: under
     * `strict_types=1` a non-string reaching {@link \NeuroSYS\Service\DownloadLogEntry}'s
     * `string`-typed constructor is an uncaught TypeError, which would take a download down over a
     * log line. Unreachable through Apache, and exactly the reasoning
     * {@link \NeuroSYS\Service\DownloadLogEntry::fromJson()} sets out for input nothing here wrote.
     *
     * @return void
     */
    public function testAServerVariableHoldingSomethingOtherThanAStringReadsAsAbsent(): void
    {
        $_SERVER = ['HTTP_REFERER' => ['https://example.invalid/'], 'REQUEST_METHOD' => 405];

        self::assertNull(ServerVariable::Referer->string());
        self::assertNull(ServerVariable::RequestMethod->string());
    }

    /**
     * The two spellings of one header, and why only one of them can be derived.
     *
     * `HTTP_AUTHORIZATION` is what the derivation in {@link Request::header()} would produce for a
     * header named `Authorization`; `REDIRECT_HTTP_AUTHORIZATION` is Apache's own name for the same
     * value seen from the far side of an internal rewrite, and no transform of a header name
     * reaches it. That asymmetry is the rule for what belongs on this enum rather than on
     * {@link RequestHeader}, so it is asserted rather than described.
     *
     * @return void
     */
    public function testOnlyOneOfTheAuthorizationSpellingsIsOneAHeaderNameCanProduce(): void
    {
        $derive = static fn(string $header): string
            => 'HTTP_' . str_replace('-', '_', strtoupper($header));

        self::assertSame(ServerVariable::Authorization->value, $derive('Authorization'));
        self::assertNotSame(ServerVariable::RedirectAuthorization->value, $derive('Authorization'));
    }

    /**
     * Both spellings are read, and the unprefixed one wins when both are set.
     *
     * @param array<string, string> $server
     * @param string $expected
     * @return void
     */
    #[DataProvider('authorizationProvider')]
    public function testTheAuthorizationHeaderIsReadUnderEitherName(array $server, string $expected): void
    {
        $request = $this->request(['REQUEST_URI' => '/'] + $server);

        self::assertSame($expected, $request->authUser());
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function authorizationProvider(): iterable
    {
        $basic = static fn(string $user): string => 'Basic ' . base64_encode($user . ':pw');

        yield 'unprefixed'  => [['HTTP_AUTHORIZATION' => $basic('alice')], 'alice'];
        yield 'redirected'  => [['REDIRECT_HTTP_AUTHORIZATION' => $basic('bob')], 'bob'];
        yield 'both, first wins' => [
            ['HTTP_AUTHORIZATION' => $basic('alice'), 'REDIRECT_HTTP_AUTHORIZATION' => $basic('bob')],
            'alice',
        ];
        yield 'empty falls through' => [
            ['HTTP_AUTHORIZATION' => '', 'REDIRECT_HTTP_AUTHORIZATION' => $basic('bob')],
            'bob',
        ];
        yield 'neither' => [[], ''];
    }

    // ─────────────────────── the scheme both gates speak ───────────────────────

    /**
     * @return void
     */
    public function testTheChallengeAndTheParseUseTheSameToken(): void
    {
        self::assertStringStartsWith(
            AuthScheme::Basic->value . ' ',
            new BasicChallenge('realm')->render() . ' ',
        );
        self::assertTrue(AuthScheme::Basic->carries('Basic ' . base64_encode('a:b')));
    }

    /**
     * The space is part of the token: `Basicxyz` starts with `Basic` and is not a credential.
     *
     * @return void
     */
    public function testAnotherSchemeIsNotCarried(): void
    {
        self::assertFalse(AuthScheme::Basic->carries('Bearer abc123'));
        self::assertFalse(AuthScheme::Basic->carries('Basicxyz'));
        self::assertFalse(AuthScheme::Basic->carries(''));
        self::assertSame(['', ''], AuthScheme::Basic->credentials('Bearer abc123'));
    }

    /**
     * @return void
     */
    public function testCredentialsSplitOnTheFirstColonOnly(): void
    {
        self::assertSame(
            ['admin', 'a:b:c'],
            AuthScheme::Basic->credentials('Basic ' . base64_encode('admin:a:b:c')),
        );
    }

    // ─────────────────────── which language a visitor wants ───────────────────────

    /**
     * @param string   $header
     * @param Language $expected
     * @return void
     */
    #[DataProvider('languageProvider')]
    public function testTheHeaderPicksALanguage(string $header, Language $expected): void
    {
        self::assertSame(
            $expected,
            AcceptedLanguages::from($header)->preferred(Language::English, Language::German),
        );
    }

    /**
     * @return iterable
     */
    public static function languageProvider(): iterable
    {
        yield 'plain German'        => ['de', Language::German];
        yield 'German with region'  => ['de-AT', Language::German];
        yield 'weighted, German up' => ['de-DE,de;q=0.9,en;q=0.8', Language::German];
        yield 'weighted, English up' => ['en-GB,en;q=0.9,de;q=0.8', Language::English];
        yield 'no header at all'    => ['', Language::English];
        yield 'nothing we have'     => ['fr,es;q=0.8', Language::English];
        yield 'a tie goes to the default' => ['en;q=0.5,de;q=0.5', Language::English];
        yield 'German refused'      => ['de;q=0', Language::English];
        yield 'whitespace and case' => ['  DE-at ; q=0.7 , en;q=0.2', Language::German];
        yield 'unparseable weight'  => ['de;q=high', Language::German];
        yield 'empty entries'       => [',,,', Language::English];
        yield 'a range that is not one' => ['12345678901,de', Language::German];
        // A range may carry parameters other than a weight. Rare in the wild, legal in the grammar,
        // and the arm that skips them is otherwise never run.
        yield 'a parameter that is not a weight' => ['en;q=0.9,de;x=1;q=0.95', Language::German];
    }

    /**
     * A wildcard covers whatever is on offer; an explicit `q=0` still refuses.
     *
     * The second assertion is the one worth having: German is the *default* there and the visitor
     * is given English anyway, because refusing a language outright outranks being the fallback.
     *
     * @return void
     */
    public function testTheWildcardIsBeatenByAnExplicitRefusal(): void
    {
        $accepted = AcceptedLanguages::from('*;q=0.5,de;q=0');

        self::assertSame(Language::English, $accepted->preferred(Language::English, Language::German));
        self::assertSame(Language::English, $accepted->preferred(Language::German, Language::English));
    }

    /**
     * But a refusal with nothing else acceptable still gets a page.
     *
     * There is no "406 Not Acceptable" here and there should not be: both halves are sent whatever
     * happens, and the only question this answers is which one comes first.
     *
     * @return void
     */
    public function testRefusingEverythingStillYieldsTheDefault(): void
    {
        self::assertSame(
            Language::German,
            AcceptedLanguages::from('de;q=0')->preferred(Language::German, Language::English),
        );
    }

    /**
     * The better of two ranges sharing a primary subtag is what the client meant by sending both.
     *
     * @return void
     */
    public function testTwoRangesForOneLanguageKeepTheHigherWeight(): void
    {
        self::assertSame(
            Language::German,
            AcceptedLanguages::from('de-AT;q=0.9,de;q=0.1,en;q=0.5')
                ->preferred(Language::English, Language::German),
        );
    }

    /**
     * The header reaches the request, which is the half a unit test of the parser cannot show.
     *
     * @return void
     */
    public function testTheRequestCarriesTheAcceptLanguageHeader(): void
    {
        $request = $this->request([
            'REQUEST_URI'          => '/imprint',
            'HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9',
        ]);

        self::assertSame(
            Language::German,
            $request->acceptedLanguages()->preferred(Language::English, Language::German),
        );
    }
}
