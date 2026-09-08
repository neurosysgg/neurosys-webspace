<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Controller\UnroutedController;
use NeuroSYS\Controller\UpdateController;
use NeuroSYS\Exception\UpdateException;
use NeuroSYS\Http\HttpMethod;
use NeuroSYS\Http\HttpStatusCode;
use NeuroSYS\Http\PlainTextResponse;
use NeuroSYS\Http\Request;
use NeuroSYS\Model\Update\Deployment;
use NeuroSYS\Model\Update\UpdateFile;
use NeuroSYS\Model\Update\UpdateManifest;
use NeuroSYS\Model\Update\UpdatePayload;
use NeuroSYS\Model\Update\UpdateReport;
use NeuroSYS\Model\Update\UpdateRoot;
use NeuroSYS\Router;
use NeuroSYS\Service\UpdateApplier;
use NeuroSYS\Service\UpdateGate;
use NeuroSYS\Support\Directory;
use NeuroSYS\Support\File;
use NeuroSYS\Support\MethodPolicy;
use NeuroSYS\Support\PublicKey;
use NeuroSYS\Support\Route;
use NeuroSYS\Support\RouteInitialization;
use NeuroSYS\Support\SitePath;
use NeuroSYS\Support\TarArchive;
use NeuroSYS\Support\TarEntry;
use NeuroSYS\Support\TarMemberType;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The update endpoint: what it refuses, how quietly it refuses it, and what it does when it does not.
 *
 * The shape of this file follows the shape of the feature. {@link UpdateGate} refuses in silence, so
 * those tests assert a *response*, not a message — the whole point being that a caller cannot tell
 * one refusal from another or from a path that was never there. {@link UpdateApplier} refuses out
 * loud, so those tests assert the sentence, because past the signature the sentence is the only
 * account of the run that exists.
 *
 * The archive fixtures are built by hand rather than by `TarWriter`, which lives under `tools/` and
 * — deliberately — cannot write a symlink, a device node or a name with `..` in it. A test that
 * could only produce well-formed archives could not test the refusals that matter.
 */
#[CoversClass(UpdateController::class)]
#[CoversClass(UnroutedController::class)]
#[CoversClass(UpdateGate::class)]
#[CoversClass(UpdateApplier::class)]
#[CoversClass(UpdateFile::class)]
#[CoversClass(UpdateManifest::class)]
#[CoversClass(UpdatePayload::class)]
#[CoversClass(UpdateReport::class)]
#[CoversClass(UpdateRoot::class)]
#[CoversClass(Deployment::class)]
#[CoversClass(PublicKey::class)]
#[CoversClass(TarArchive::class)]
#[CoversClass(TarEntry::class)]
#[CoversClass(TarMemberType::class)]
final class UpdateTest extends TestCase
{
    private const int BLOCK = 512;

    private string $sandbox = '';
    private OpenSSLAsymmetricKey $privateKey;
    private File $keyFile;
    private File $serialFile;

    /**
     * A fresh keypair and a sandbox deployment per test.
     *
     * The key is generated rather than checked in, so the suite proves the whole chain end to end —
     * generate, sign, verify — rather than only the verifying half against a fixture whose origin
     * nothing states.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key, 'this host cannot generate an EC key, so nothing below is meaningful');
        $this->privateKey = $key;

        $this->sandbox = sys_get_temp_dir() . '/neurosys-update-' . bin2hex(random_bytes(6));
        new Directory($this->sandbox)->create();

        $this->keyFile    = new File($this->sandbox . '/update.pub');
        $this->serialFile = new File($this->sandbox . '/.update-serial');

        $details = openssl_pkey_get_details($this->privateKey);
        self::assertIsArray($details);
        self::assertTrue($this->keyFile->write((string) $details['key']));
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        if ($this->sandbox !== '') {
            self::removeTree($this->sandbox);
        }
    }

    // ───────────────────────────── the decoy ─────────────────────────────

    /**
     * An unsigned request is answered exactly as a path no route claims.
     *
     * **This is the property the whole endpoint is arranged around**, and it is asserted against the
     * real router rather than against a remembered expectation: whatever `/no-such-page` answers,
     * `/update` answers, for every method. A 401 or a 403 or a 405 naming POST would each announce
     * that the address is real, and the announcement is the thing being prevented.
     *
     * @param string $method
     * @return void
     */
    #[DataProvider('everyMethodProvider')]
    public function testAnUnsignedRequestIsAnsweredExactlyLikeAnUnroutedPath(string $method): void
    {
        $router = new Router(RouteInitialization::routes());

        $update   = $router->dispatch(self::request($method, SitePath::Update->value));
        $unrouted = $router->dispatch(self::request($method, '/no-such-page'));

        self::assertSame($unrouted::class, $update::class, $method . ' answers with a different kind of response');
        self::assertSame(
            self::statusOf($unrouted),
            self::statusOf($update),
            $method . ' answers with a different status',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function everyMethodProvider(): iterable
    {
        foreach (HttpMethod::cases() as $method) {
            yield $method->value => [$method->value];
        }
    }

    /**
     * The 405 on `/update` names GET and HEAD, never POST.
     *
     * The route does accept POST — that is how a signed push gets in — so the honest `Allow` for it
     * would be `GET, HEAD, POST`. Sending that would tell an unsigned caller exactly what it is not
     * allowed to know, so {@link UnroutedController} sends the read-only set instead, which is what
     * every other unrouted path sends.
     *
     * @return void
     */
    public function testTheRefusalNeverNamesPost(): void
    {
        $response = new Router(RouteInitialization::routes())
            ->dispatch(self::request('PUT', SitePath::Update->value));

        self::assertInstanceOf(PlainTextResponse::class, $response);
        self::assertSame(
            ['Allow: GET, HEAD'],
            new ReflectionProperty($response, 'headers')->getValue($response)
                ->map(static fn(object $header): string => $header->line())->toValues(),
        );
    }

    // ───────────────────────────── the per-route gate ─────────────────────────────

    /**
     * `/update` is the one route that accepts a write method, and the only one.
     *
     * @return void
     */
    public function testOnlyTheUpdateRouteAcceptsAWriteMethod(): void
    {
        $accepting = [];
        $delegated = [];

        foreach (RouteInitialization::routes() as $route) {
            $pattern = new ReflectionProperty(Route::class, 'pattern')->getValue($route);

            if ($route->accepts(HttpMethod::Post)) {
                $accepting[] = $pattern;
            }

            if (new ReflectionProperty(Route::class, 'methods')->getValue($route) === MethodPolicy::Delegated) {
                $delegated[] = $pattern;
            }
        }

        self::assertSame([SitePath::Update], $accepting);
        self::assertSame([SitePath::Update], $delegated, 'a second route stopped being method-gated');
    }

    /**
     * A route given no method set answers on the read-only ones, which is nine routes out of ten.
     *
     * @return void
     */
    public function testARouteWithNoDeclaredMethodsIsReadOnly(): void
    {
        $route = new Route(SitePath::Home, static fn(): never => self::fail('not reached'));

        self::assertTrue($route->accepts(HttpMethod::Get));
        self::assertTrue($route->accepts(HttpMethod::Head));
        self::assertFalse($route->accepts(HttpMethod::Post));
        self::assertFalse($route->accepts(null));
    }

    /**
     * A delegated route accepts everything, an unrecognised verb included.
     *
     * That last part is the one worth pinning. `Request::method()` is null for a verb this site
     * does not know, and if the router refused a null here it would answer `BREW /update`
     * differently from `BREW /no-such-page` — which is the whole property gone, over a method
     * nobody sends on purpose.
     *
     * @return void
     */
    public function testADelegatedRouteAcceptsEvenAnUnknownMethod(): void
    {
        $route = new Route(
            SitePath::Update,
            static fn(): never => self::fail('not reached'),
            MethodPolicy::Delegated,
        );

        self::assertTrue($route->accepts(null));

        foreach (HttpMethod::cases() as $method) {
            self::assertTrue($route->accepts($method), $method->value . ' did not reach the controller');
        }
    }

    // ───────────────────────────── the gate ─────────────────────────────

    /**
     * A well-formed, freshly signed push is accepted.
     *
     * @return void
     */
    public function testAValidPushIsAccepted(): void
    {
        self::assertNotNull($this->verdict($this->push($this->archive([
            'src/NeuroSYS/Thing.php' => '<?php // thing',
        ]))));
    }

    /**
     * Everything the gate refuses, it refuses as `null` and says nothing about which.
     *
     * @param string $case
     * @return void
     */
    #[DataProvider('refusedPushProvider')]
    public function testTheGateRefusesInSilence(string $case): void
    {
        $archive = $this->archive(['src/NeuroSYS/Thing.php' => '<?php // thing']);

        $verdict = match ($case) {
            'wrong method'   => $this->verdict($this->push($archive), 'GET'),
            'empty body'     => $this->verdict(''),
            'garbage body'   => $this->verdict('not a payload'),
            'wrong magic'    => $this->verdict('XXXX' . str_repeat("\0", 64)),
            'tampered'       => $this->verdict($this->push($archive, tamper: true)),
            'wrong key'      => $this->verdict($this->push($archive, signWith: self::otherKey())),
            'stale serial'   => $this->verdict($this->push($archive, serial: time() - 4000)),
            'future serial'  => $this->verdict($this->push($archive, serial: time() + 4000)),
            'wrong digest'   => $this->verdict($this->push($archive, digest: str_repeat('a', 64))),
            'wrong size'     => $this->verdict($this->push($archive, size: 1)),
            default          => self::fail('unknown case ' . $case),
        };

        self::assertNull($verdict, $case . ' was not refused');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedPushProvider(): iterable
    {
        $cases = [
            'wrong method', 'empty body', 'garbage body', 'wrong magic', 'tampered',
            'wrong key', 'stale serial', 'future serial', 'wrong digest', 'wrong size',
        ];

        foreach ($cases as $case) {
            yield $case => [$case];
        }
    }

    /**
     * No key file, no endpoint — the off switch, and the opposite polarity to `data/site_auth.php`.
     *
     * @return void
     */
    public function testWithNoKeyFileEveryPushIsRefused(): void
    {
        self::assertTrue($this->keyFile->delete());

        self::assertNull($this->verdict($this->push($this->archive(['src/a.php' => 'x']))));
    }

    /**
     * A key file holding something that is not an EC public key refuses everything too.
     *
     * @return void
     */
    public function testAnUnusableKeyFileRefusesEverything(): void
    {
        self::assertTrue($this->keyFile->write("-----BEGIN PUBLIC KEY-----\nnope\n-----END PUBLIC KEY-----\n"));

        self::assertNull($this->verdict($this->push($this->archive(['src/a.php' => 'x']))));
    }

    /**
     * A serial already accepted cannot be used again.
     *
     * @return void
     */
    public function testASerialIsAcceptedOnlyOnce(): void
    {
        $serial  = time();
        $archive = $this->archive(['src/NeuroSYS/Thing.php' => '<?php // thing']);

        self::assertNotNull($this->verdict($this->push($archive, serial: $serial)));
        self::assertTrue($this->gate()->accept($serial));
        self::assertNull(
            $this->verdict($this->push($archive, serial: $serial)),
            'the same serial was accepted twice, so a captured push can be replayed',
        );
    }

    // ───────────────────────────── the applier ─────────────────────────────

    /**
     * Every member shape this site will not write, refused by name.
     *
     * @param string $name
     * @param string $type
     * @param string $expected A fragment of the sentence the refusal must carry.
     * @return void
     */
    #[DataProvider('refusedMemberProvider')]
    public function testTheApplierRefusesAMemberItWillNotWrite(string $name, string $type, string $expected): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expected, '/') . '/');

        (void) $this->applier()->apply(
            (string) gzencode(self::member($name, $type === TarMemberType::File->value ? 'x' : '', $type)
                . str_repeat("\0", self::BLOCK * 2)),
            self::manifest(),
        );
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function refusedMemberProvider(): iterable
    {
        $file = TarMemberType::File->value;

        yield 'a data/ path'      => ['data/admin.php', $file, 'under none of the roots'];
        yield 'an unknown root'   => ['vendor/autoload.php', $file, 'under none of the roots'];
        yield 'a traversal'       => ['src/../../etc/passwd', $file, 'walks the tree'];
        yield 'an absolute path'  => ['/etc/passwd', $file, 'not a plain relative path'];
        yield 'a backslash path'  => ['public\\..\\evil.php', $file, 'not a plain relative path'];
        yield 'a symlink'         => ['public/evil.php', TarMemberType::Symlink->value, 'a symlink'];
        yield 'a hardlink'        => ['public/evil.php', TarMemberType::Hardlink->value, 'a hardlink'];
        yield 'a character device' => ['public/evil.php', TarMemberType::CharacterDevice->value, 'a character device'];
        yield 'a block device'    => ['public/evil.php', TarMemberType::BlockDevice->value, 'a block device'];
        yield 'a fifo'            => ['public/evil.php', TarMemberType::Fifo->value, 'a fifo'];
        yield 'a long-name record' => ['././@LongLink', TarMemberType::LongName->value, 'a GNU long-name record'];
        yield 'a pax header'      => ['pax_global_header', TarMemberType::PaxGlobal->value, 'a pax global header'];

        // Two shapes where the *name* is fine and the pair of name-and-kind is not. Neither is
        // something `tar` produces and neither is reachable without the private key — they are
        // refused because the alternative is a destination computed from them.
        //
        // A tree root matches its own name as well as anything under it, which is right for the
        // directory entry `tar` writes for `public/` and wrong for a regular file called `public`:
        // Deployment::destination() strips the prefix and one separator, so that resolves to the
        // webroot directory itself. Nothing would be overwritten — rename() refuses a directory —
        // but it would have been reported as a write that failed rather than a payload never legal.
        yield 'a tree root as a file' => ['public', $file, 'a tree this push writes into'];
        yield 'the other tree root'   => ['src', $file, 'a tree this push writes into'];

        // And a regular file whose name is written as a directory, which is what keeps the name
        // check() validates and the name UpdateFile carries the same string.
        yield 'a file named as a dir' => ['public/x/', $file, 'written as a directory'];
    }

    /**
     * The single-file root is *not* caught by the rule above, which is the whole reason that rule
     * asks {@link \NeuroSYS\Model\Update\UpdateRoot::isTree()}.
     *
     * `autoload.php` is a member whose name is exactly its root, and it is the one legitimate case
     * of that — a push that could not carry it would be a push that cannot replace the autoloader.
     *
     * @return void
     */
    public function testTheSingleFileRootIsStillWritableUnderItsOwnName(): void
    {
        $report = $this->applier()->apply(
            (string) gzencode(self::member('autoload.php', '<?php // x')
                . str_repeat("\0", self::BLOCK * 2)),
            self::manifest(),
        );

        self::assertTrue($report->isComplete(), $report->render());
    }

    /**
     * A header whose checksum does not match its own bytes is refused before its name is believed.
     *
     * @return void
     */
    public function testABadChecksumIsRefused(): void
    {
        $bad = substr_replace(self::member('public/x.php', 'x'), 'AAAAAAAA', 148, 8);

        $this->expectException(UpdateException::class);
        $this->expectExceptionMessageMatches('/not an octal number/');

        (void) $this->applier()->apply(
            (string) gzencode($bad . str_repeat("\0", self::BLOCK * 2)),
            self::manifest(),
        );
    }

    /**
     * A body that is not gzip at all.
     *
     * @return void
     */
    public function testAnArchiveThatIsNotGzipIsRefused(): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessageMatches('/not gzip/');

        (void) $this->applier()->apply('X', self::manifest());
    }

    /**
     * An archive that declares more bytes than it carries.
     *
     * @return void
     */
    public function testATruncatedArchiveIsRefused(): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessageMatches('/truncated/');

        TarArchive::parse(self::member('public/x.php', str_repeat('x', 4096), sizeOverride: 999_999));
    }

    /**
     * A member of a type no tar defines at all.
     *
     * @return void
     */
    public function testAnUndefinedMemberTypeIsRefused(): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessageMatches('/a type no tar defines/');

        TarArchive::parse(self::member('public/x.php', '', 'Z') . str_repeat("\0", self::BLOCK * 2));
    }

    // ───────────────────────────── the manifest ─────────────────────────────

    /**
     * Every field is required and typed; there are no defaults.
     *
     * A missing `mirror` defaulting to false is an update that quietly stops deleting; a missing
     * `apply` defaulting to true is a dry run that was not one. Both are silent, so neither is
     * allowed to happen.
     *
     * @param string $json
     * @return void
     */
    #[DataProvider('badManifestProvider')]
    public function testAMalformedManifestIsRefused(string $json): void
    {
        $this->expectException(UpdateException::class);

        UpdateManifest::parse($json);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badManifestProvider(): iterable
    {
        yield 'not JSON'          => ['{'];
        yield 'not an object'     => ['"a string"'];
        yield 'no serial'         => ['{"digest":"' . str_repeat('a', 64) . '","size":1,"apply":true,"mirror":true}'];
        yield 'no digest'         => ['{"serial":1,"size":1,"apply":true,"mirror":true}'];
        yield 'no apply'          => ['{"serial":1,"digest":"' . str_repeat('a', 64) . '","size":1,"mirror":true}'];
        yield 'no mirror'         => ['{"serial":1,"digest":"' . str_repeat('a', 64) . '","size":1,"apply":true}'];
        yield 'serial as string'  => ['{"serial":"1","digest":"' . str_repeat('a', 64)
            . '","size":1,"apply":true,"mirror":true}'];
        yield 'apply as int'      => ['{"serial":1,"digest":"' . str_repeat('a', 64)
            . '","size":1,"apply":1,"mirror":true}'];
        yield 'digest not a hash' => ['{"serial":1,"digest":"nope","size":1,"apply":true,"mirror":true}'];
        yield 'digest uppercase'  => ['{"serial":1,"digest":"' . str_repeat('A', 64)
            . '","size":1,"apply":true,"mirror":true}'];
        yield 'negative size'     => ['{"serial":1,"digest":"' . str_repeat('a', 64)
            . '","size":-1,"apply":true,"mirror":true}'];
    }

    /**
     * A length prefix that runs past the end of the body.
     *
     * @return void
     */
    public function testAPayloadWhoseSegmentRunsPastTheEndIsRefused(): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessageMatches('/only \d+ bytes follow/');

        UpdatePayload::parse(UpdatePayload::MAGIC . pack('N', 4096) . 'short');
    }

    /**
     * A manifest length beyond what a manifest can be.
     *
     * @return void
     */
    public function testAnOversizedSegmentIsRefused(): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessageMatches('/over the \d+-byte limit/');

        UpdatePayload::parse(UpdatePayload::MAGIC . pack('N', 1_000_000) . 'x');
    }

    // ───────────────────────────── roots ─────────────────────────────

    /**
     * `data` is not a root, which is the single rule keeping the credentials and the demos safe.
     *
     * @return void
     */
    public function testDataIsNotARoot(): void
    {
        self::assertNull(UpdateRoot::of('data/admin.php'));
        self::assertNull(UpdateRoot::of('data/demos.php'));
        self::assertNull(UpdateRoot::of('data'));
        self::assertSame(
            ['public', 'src', 'autoload.php'],
            array_column(UpdateRoot::cases(), 'value'),
        );
    }

    /**
     * A tree root claims its own directory entry as well as what is under it.
     *
     * An archive names `public/` before it names `public/index.php`, and a version of this that
     * matched only the prefix refused every well-formed payload at its first member.
     *
     * @return void
     */
    public function testATreeRootClaimsItsOwnDirectoryEntry(): void
    {
        self::assertSame(UpdateRoot::Public, UpdateRoot::of('public'));
        self::assertSame(UpdateRoot::Public, UpdateRoot::of('public/index.php'));
        self::assertSame(UpdateRoot::Source, UpdateRoot::of('src'));
        self::assertSame(UpdateRoot::Autoload, UpdateRoot::of('autoload.php'));
        self::assertFalse(UpdateRoot::Autoload->isTree(), 'a single-file root has no tree to mirror');
        self::assertTrue(UpdateRoot::Public->isTree());
        self::assertTrue(UpdateRoot::Source->isTree());
    }

    /**
     * A name that merely starts with a root's letters is not under that root.
     *
     * @return void
     */
    public function testAPrefixIsNotARoot(): void
    {
        self::assertNull(UpdateRoot::of('publicity/x.php'));
        self::assertNull(UpdateRoot::of('srcs/x.php'));
        self::assertNull(UpdateRoot::of('autoload.php.bak'));
    }

    // ───────────────────────────── the key ─────────────────────────────

    /**
     * A key that is not a key, and a key of the wrong kind, are both refused where they are read.
     *
     * @return void
     */
    public function testOnlyAnEcPublicKeyIsAccepted(): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessageMatches('/not a readable PEM public key/');

        PublicKey::fromPem('not a key');
    }

    /**
     * An RSA key parses and is still refused: it would answer a different question.
     *
     * @return void
     */
    public function testAnRsaKeyIsRefused(): void
    {
        $rsa = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertNotFalse($rsa);
        $details = openssl_pkey_get_details($rsa);
        self::assertIsArray($details);

        $this->expectException(UpdateException::class);
        $this->expectExceptionMessageMatches('/not an EC key/');

        PublicKey::fromPem((string) $details['key']);
    }

    /**
     * A good signature verifies and a tampered one does not.
     *
     * @return void
     */
    public function testTheKeyVerifiesOnlyWhatItSigned(): void
    {
        $details = openssl_pkey_get_details($this->privateKey);
        self::assertIsArray($details);
        $key = PublicKey::fromPem((string) $details['key']);

        $signature = null;
        self::assertTrue(openssl_sign('payload', $signature, $this->privateKey, OPENSSL_ALGO_SHA256));

        self::assertTrue($key->verifies('payload', (string) $signature));
        self::assertFalse($key->verifies('payloaX', (string) $signature));
        self::assertFalse($key->verifies('payload', 'not a signature'));
    }

    // ───────────────────────────── the report ─────────────────────────────

    /**
     * The report copies rather than accumulating, and says which run it describes.
     *
     * @return void
     */
    public function testTheReportCopies(): void
    {
        $empty = new UpdateReport();
        $one   = $empty->wrote('public/a.js');

        self::assertTrue($empty->isComplete());
        self::assertStringNotContainsString('public/a.js', $empty->render());
        self::assertStringContainsString('+ public/a.js', $one->render());
        self::assertStringContainsString('applied', $one->render());
        self::assertStringContainsString('dry run', $one->dryRun()->render());
        self::assertFalse($one->failed('public/b.js', 'nope')->isComplete());
    }

    // ───────────────────────────── the round trip ─────────────────────────────

    /**
     * A push writes what it carries, removes what it omits, and touches nothing else.
     *
     * @return void
     */
    public function testAPushMirrorsTheTree(): void
    {
        $webroot = new Directory($this->sandbox . '/public');
        self::assertTrue($webroot->directory('assets')->create());
        self::assertTrue($webroot->file('assets/stale.js')->write('stale'));
        self::assertTrue($webroot->file('keep.txt')->write('old'));

        $report = $this->applier()->apply(
            $this->archive(['public/keep.txt' => 'new']),
            self::manifest(mirror: true),
        );

        self::assertTrue($report->isComplete(), $report->render());
        self::assertSame('new', $webroot->file('keep.txt')->read());
        self::assertFalse($webroot->file('assets/stale.js')->exists(), 'the mirror left a stale file behind');
    }

    /**
     * The mirror never deletes *through* a symlink.
     *
     * A push cannot introduce one — {@link TarArchive} refuses the member type — so a symlink under
     * a root was placed by something outside this endpoint. Were the walk to follow it, a link to a
     * tree outside the roots would have that tree read as surplus and unlinked a file at a time. The
     * walk treats a link as a leaf instead: the link is what is weighed against the payload, and the
     * target it points at is never entered, listed, or removed. This is the one mirror hazard a test
     * can only reach by planting on disk what an archive is forbidden to carry.
     *
     * @return void
     */
    public function testTheMirrorDoesNotDeleteThroughASymlink(): void
    {
        $webroot = new Directory($this->sandbox . '/public');
        self::assertTrue($webroot->create());

        // A directory outside every root, holding a file a push must never be able to reach.
        $outside = new Directory($this->sandbox . '/outside');
        self::assertTrue($outside->create());
        self::assertTrue($outside->file('secret.txt')->write('untouchable'));

        // …reached from inside the webroot only through a symlink someone would have had to plant.
        $link = $this->sandbox . '/public/link';
        self::assertTrue(symlink($outside->path, $link), 'this platform cannot make a symlink');

        try {
            $report = $this->applier()->apply(
                $this->archive(['public/keep.txt' => 'new']),
                self::manifest(mirror: true),
            );

            self::assertTrue(
                $outside->file('secret.txt')->exists(),
                'the mirror followed the symlink and deleted a file outside the roots',
            );
            self::assertSame('untouchable', $outside->file('secret.txt')->read());
            self::assertSame('new', $webroot->file('keep.txt')->read(), 'the payload file was disturbed');
        } finally {
            @unlink($link);
        }
    }

    /**
     * A dry run reports the same plan and writes none of it.
     *
     * @return void
     */
    public function testADryRunWritesNothing(): void
    {
        $webroot = new Directory($this->sandbox . '/public');
        self::assertTrue($webroot->create());
        self::assertTrue($webroot->file('keep.txt')->write('old'));

        $report = $this->applier()->apply(
            $this->archive(['public/keep.txt' => 'new']),
            self::manifest(apply: false),
        );

        self::assertStringContainsString('dry run', $report->render());
        self::assertStringContainsString('+ public/keep.txt', $report->render());
        self::assertSame('old', $webroot->file('keep.txt')->read(), 'a dry run wrote to disk');
    }

    /**
     * A file the payload does not change is not rewritten, and the dry run says so first.
     *
     * **The assertion that matters is the mtime**, not the count. Rewriting an identical file is
     * harmless on every filesystem but the one this deploys to: Strato serves off NFS, and
     * {@link File::write()} renames its temp file onto the target, so rewriting `public/index.php`
     * while the request executes out of it silly-renames the open inode aside as `.nfsXXXXXXXX`.
     * The mirror then meets that stray in the same request and cannot delete it — one undeletable
     * file in the webroot and one spurious failure, per push. The first real push to production did
     * exactly that, which is why this asserts the file was left strictly alone rather than merely
     * that a counter said so.
     *
     * @return void
     */
    public function testAnUnchangedFileIsNotRewritten(): void
    {
        $webroot = new Directory($this->sandbox . '/public');
        self::assertTrue($webroot->create());
        self::assertTrue($webroot->file('same.txt')->write('identical'));
        self::assertTrue($webroot->file('other.txt')->write('old'));

        $stamp = filemtime($webroot->file('same.txt')->path);
        self::assertIsInt($stamp);
        touch($webroot->file('same.txt')->path, $stamp - 60);
        clearstatcache();

        $payload = ['public/same.txt' => 'identical', 'public/other.txt' => 'new'];

        $planned = $this->applier()->apply($this->archive($payload), self::manifest(apply: false));
        self::assertStringContainsString('unchanged 1', $planned->render());
        self::assertStringContainsString('+ public/other.txt', $planned->render());
        self::assertStringNotContainsString('+ public/same.txt', $planned->render());

        $report = $this->applier()->apply($this->archive($payload), self::manifest());

        self::assertTrue($report->isComplete(), $report->render());
        self::assertStringContainsString('written 1  unchanged 1', $report->render());
        self::assertSame('new', $webroot->file('other.txt')->read());
        self::assertSame('identical', $webroot->file('same.txt')->read());

        clearstatcache();
        self::assertSame(
            $stamp - 60,
            filemtime($webroot->file('same.txt')->path),
            'an unchanged file was rewritten, which on NFS strands the old inode as a .nfs file',
        );
    }

    // ───────────────────────────── the last refusals ─────────────────────────────

    /**
     * A payload that stops inside a length field is refused rather than read past.
     *
     * The two segments are length-prefixed and read in sequence, so the bound has to be checked
     * before the prefix is unpacked as well as before the segment is taken. `unpack()` on a short
     * string does not fail usefully — it warns and hands back something — which is why the guard is
     * an explicit comparison rather than a check of its result.
     *
     * @param int $keep
     * @param string $expected
     * @return void
     */
    #[DataProvider('truncatedPayloadProvider')]
    public function testAPayloadTruncatedInsideALengthIsRefused(int $keep, string $expected): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage($expected);

        UpdatePayload::parse(substr($this->push($this->archive(['public/a.txt' => 'x'])), 0, $keep));
    }

    /**
     * @return iterable
     */
    public static function truncatedPayloadProvider(): iterable
    {
        // Magic, then nothing — the manifest's own length field is not there.
        yield 'before the manifest length'   => [4, 'ends before its manifest length'];
        yield 'inside the manifest length'   => [6, 'ends before its manifest length'];
    }

    /**
     * A header whose stored checksum does not match its bytes is refused, and says why.
     *
     * The checksum is the only thing saying a 512-byte block *is* a header rather than the middle
     * of somebody's file. Without it a corrupt archive is not an error but an archive that appears
     * to hold different members, which is the difference between refusing bytes and writing the
     * wrong ones.
     *
     * @return void
     */
    public function testAHeaderThatDoesNotMatchItsChecksumIsRefused(): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('does not match its own checksum');

        // The name is changed after the checksum was computed over the original, so the block is
        // well formed in every other way — which is the case worth refusing.
        $member = self::member('public/a.txt', 'x');

        TarArchive::parse(substr_replace($member, 'X', 0, 1) . str_repeat("\0", self::BLOCK * 2));
    }

    /**
     * A member name that is empty or longer than ustar allows is refused before anything is written.
     *
     * @param string $name
     * @param string $prefix
     * @return void
     */
    #[DataProvider('unwritableNameProvider')]
    public function testAMemberNameThatIsEmptyOrTooLongIsRefused(string $name, string $prefix): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('empty or over 255 bytes');

        // Built by hand rather than through archive(): a name this shape is one TarWriter would
        // never produce, which is exactly why the reader has to have an opinion about it. The
        // discard is deliberate and says so — apply() carries #[NoDiscard] because its report is
        // the endpoint's whole response, and what is being demonstrated here is that it throws.
        (void) $this->applier()->apply(
            (string) gzencode(
                self::member($name, 'x', prefix: $prefix) . str_repeat("\0", self::BLOCK * 2),
            ),
            self::manifest(),
        );
    }

    /**
     * @return iterable
     */
    public static function unwritableNameProvider(): iterable
    {
        // A name of only slashes rtrims to nothing, which is the shape a directory member takes
        // when everything before the slash has already been stripped.
        yield 'empty'      => ['', ''];
        yield 'only slash' => ['/', ''];

        // 155 + '/' + 100 is 256, one past the bound — and it takes both ustar name fields to say
        // it, which is why the reader joining them is worth a test of its own.
        yield 'too long'   => [str_repeat('a', 100), str_repeat('p', 155)];
    }

    /**
     * A dry run says what the mirror would remove, and removes none of it.
     *
     * The mirror is the half of a push that deletes, so predicting it is the half of `--dry-run`
     * worth having. A run that reported only what it would write would be silent about the one
     * operation that cannot be undone.
     *
     * @return void
     */
    public function testADryRunReportsWhatTheMirrorWouldRemove(): void
    {
        $webroot = new Directory($this->sandbox . '/public');
        self::assertTrue($webroot->directory('assets')->create());
        self::assertTrue($webroot->file('assets/stale.js')->write('stale'));

        $report = $this->applier()->apply(
            $this->archive(['public/keep.txt' => 'new']),
            self::manifest(apply: false, mirror: true),
        );

        self::assertStringContainsString('dry run', $report->render());
        self::assertStringContainsString('- public/assets/stale.js', $report->render());
        self::assertStringContainsString('deleted 1', $report->render());
        self::assertTrue($webroot->file('assets/stale.js')->exists(), 'a dry run deleted a file');
        self::assertFalse($webroot->file('keep.txt')->exists(), 'a dry run wrote a file');
    }

    /**
     * A surplus file that cannot be removed is named rather than passed over.
     *
     * Mirroring is the operation with no second chance — the point of naming a failure here is that
     * the file is still on the server, still being served, and the report is the only place that
     * will ever say so.
     *
     * @return void
     */
    public function testASurplusFileThatCannotBeRemovedIsNamed(): void
    {
        $webroot = new Directory($this->sandbox . '/public');
        self::assertTrue($webroot->directory('locked')->create());
        self::assertTrue($webroot->file('locked/stale.js')->write('stale'));

        // unlink() needs write permission on the *directory*, not on the file.
        self::assertTrue(chmod($webroot->directory('locked')->path, 0o555));

        try {
            $report = $this->applier()->apply(
                $this->archive(['public/keep.txt' => 'new']),
                self::manifest(mirror: true),
            );

            self::assertFalse($report->isComplete());
            self::assertStringContainsString('! public/locked/stale.js', $report->render());
            self::assertStringContainsString('could not be removed', $report->render());
        } finally {
            chmod($webroot->directory('locked')->path, 0o755);
        }
    }

    // ───────────────────────────── the deployment ─────────────────────────────

    /**
     * The deployment a real request runs in comes from `Config`, and nowhere else.
     *
     * Asserted here rather than left to production because {@link Deployment::current()} is the one
     * constructor a test must never reach by accident — it is what resolves the *live* tree, and
     * the reason every applier in this file is handed a sandbox instead.
     *
     * @return void
     */
    public function testTheCurrentDeploymentIsResolvedFromConfig(): void
    {
        $previous = $_SERVER['DOCUMENT_ROOT'] ?? null;
        $_SERVER['DOCUMENT_ROOT'] = NEUROSYS_ROOT . '/public';

        try {
            $deployment = Deployment::current();

            self::assertSame(
                NEUROSYS_ROOT . '/public',
                $deployment->directory(UpdateRoot::Public)?->path,
            );
            self::assertSame(
                NEUROSYS_ROOT . '/src',
                $deployment->directory(UpdateRoot::Source)?->path,
            );
        } finally {
            if ($previous === null) {
                unset($_SERVER['DOCUMENT_ROOT']);
            } else {
                $_SERVER['DOCUMENT_ROOT'] = $previous;
            }
        }
    }

    /**
     * The one root that is a file rather than a tree maps both ways without a directory.
     *
     * `autoload.php` has no tree under it, so it has nothing that can go stale and
     * {@link Deployment::directory()} answers null for it. Both halves of the name mapping have to
     * cope with that null, and they are written next to each other so the two cannot drift — which
     * is the whole reason `nameOf()` lives beside `destination()` rather than in the mirror.
     *
     * @return void
     */
    public function testTheAutoloadRootIsASingleFileInBothDirections(): void
    {
        $deployment = new Deployment(
            new Directory($this->sandbox),
            new Directory($this->sandbox . '/public'),
        );

        self::assertNull($deployment->directory(UpdateRoot::Autoload));

        self::assertSame(
            $this->sandbox . '/autoload.php',
            $deployment->destination(UpdateRoot::Autoload, 'autoload.php')->path,
        );

        self::assertSame(
            'autoload.php',
            $deployment->nameOf(UpdateRoot::Autoload, $this->sandbox . '/autoload.php'),
        );
    }

    // ───────────────────────────── the controller, past the gate ─────────────────────────────

    /**
     * A push that verifies is applied, reported, and answered 200.
     *
     * Everything above this point tests the gate refusing; this is the other half, and it is the
     * half no local rig had exercised before the endpoint went live. The report *is* the response
     * body — `display_errors` is off on the live host and `error_log` is empty, so anything this
     * does not say is written down nowhere at all.
     *
     * @return void
     */
    public function testAVerifiedPushIsAppliedAndReported(): void
    {
        $webroot = new Directory($this->sandbox . '/public');
        self::assertTrue($webroot->create());

        $response = $this->respond($this->push($this->archive(['public/new.txt' => 'hello'])));

        self::assertSame(HttpStatusCode::Ok, self::statusOf($response));
        self::assertStringContainsString('applied', self::bodyOf($response));
        self::assertStringContainsString('written 1', self::bodyOf($response));
        self::assertStringContainsString('+ public/new.txt', self::bodyOf($response));
        self::assertSame('hello', $webroot->file('new.txt')->read());
    }

    /**
     * A verified push advances the serial, so the very same bytes cannot be sent twice.
     *
     * @return void
     */
    public function testAVerifiedPushAdvancesTheSerial(): void
    {
        self::assertTrue(new Directory($this->sandbox . '/public')->create());

        $body = $this->push($this->archive(['public/new.txt' => 'hello']));

        self::assertSame(HttpStatusCode::Ok, self::statusOf($this->respond($body)));
        self::assertNotNull($this->serialFile->read());

        // The identical bytes again: the gate now refuses, so the controller never runs and the
        // caller gets the answer an address that does not exist gives.
        $replay = $this->respond($body);
        self::assertSame(HttpStatusCode::MethodNotAllowed, self::statusOf($replay));
        self::assertSame(UnroutedController::REFUSAL, self::bodyOf($replay));
    }

    /**
     * A dry run leaves the serial alone, so the same payload can then be sent for real.
     *
     * This is the property that makes `--dry-run` worth having rather than merely safe: a captured
     * dry run replays to nothing, *and* the operator does not have to rebuild to send it properly.
     *
     * @return void
     */
    public function testADryRunThroughTheControllerLeavesTheSerialAlone(): void
    {
        $webroot = new Directory($this->sandbox . '/public');
        self::assertTrue($webroot->create());

        $body     = $this->push($this->archive(['public/new.txt' => 'hello']), apply: false);
        $response = $this->respond($body);

        self::assertSame(HttpStatusCode::Ok, self::statusOf($response));
        self::assertStringContainsString('dry run', self::bodyOf($response));
        self::assertFalse($webroot->file('new.txt')->exists(), 'a dry run wrote to disk');
        self::assertNull($this->serialFile->read(), 'a dry run advanced the serial');

        // And now the same bytes for real.
        self::assertSame(HttpStatusCode::Ok, self::statusOf($this->respond($body)));
    }

    /**
     * An archive that verifies but will not expand is a 422 with a sentence, not the decoy.
     *
     * The signature is over the *manifest*, and the manifest vouches for the archive by digest —
     * so bytes that are not gzip at all can still be perfectly signed. Past that point the caller
     * has proved it holds the private key, and there is nothing left to hide from it: the decoy
     * would only be a worse error message.
     *
     * @return void
     */
    public function testAnArchiveThatWillNotExpandIsAnswered422(): void
    {
        $response = $this->respond($this->push('this is not gzip at all'));

        self::assertSame(HttpStatusCode::UnprocessableContent, self::statusOf($response));
        self::assertStringContainsString('refused:', self::bodyOf($response));
        self::assertStringContainsString('not gzip', self::bodyOf($response));
        self::assertNull($this->serialFile->read(), 'a refused payload advanced the serial');
    }

    /**
     * A push that could not write everything is a 500, and names what it could not write.
     *
     * The failure is manufactured the way it would actually happen — something is already in the
     * way — rather than by mocking a write. `public/blocked` is a regular file here, so the member
     * `public/blocked/deep.txt` needs a directory that cannot be made.
     *
     * @return void
     */
    public function testAPushThatCouldNotWriteEverythingIsAnswered500(): void
    {
        $webroot = new Directory($this->sandbox . '/public');
        self::assertTrue($webroot->create());
        self::assertTrue($webroot->file('blocked')->write('in the way'));

        $response = $this->respond($this->push($this->archive([
            'public/fine.txt'         => 'written',
            'public/blocked/deep.txt' => 'cannot be',
        ])));

        self::assertSame(HttpStatusCode::InternalServerError, self::statusOf($response));
        self::assertStringContainsString('! public/blocked/deep.txt', self::bodyOf($response));
        self::assertStringContainsString('directory could not be created', self::bodyOf($response));

        // The rest still landed. A partial push is reported as one rather than rolled back: there
        // is nothing to roll back to, and the report names exactly what is missing.
        self::assertSame('written', $webroot->file('fine.txt')->read());
    }

    /**
     * A member that cannot be written over is named, and the push is a 500.
     *
     * @return void
     */
    public function testAMemberThatCannotBeWrittenIsNamed(): void
    {
        $webroot = new Directory($this->sandbox . '/public');
        self::assertTrue($webroot->create());

        // A directory where the payload wants a file: File::write() renames its temp file onto the
        // target, and a rename over a non-empty directory cannot succeed.
        self::assertTrue($webroot->directory('occupied.txt')->create());
        self::assertTrue($webroot->file('occupied.txt/inside')->write('x'));

        $response = $this->respond($this->push($this->archive(['public/occupied.txt' => 'nope'])));

        self::assertSame(HttpStatusCode::InternalServerError, self::statusOf($response));
        self::assertStringContainsString('! public/occupied.txt', self::bodyOf($response));
        self::assertStringContainsString('could not be written', self::bodyOf($response));
    }

    /**
     * A serial that could not be recorded is reported, because replay protection is then off.
     *
     * The quietest possible failure on this endpoint: everything applied, the response is cheerful,
     * and the next identical payload is accepted again because nothing remembered this one. So the
     * write is checked and its failure is a reported failure like any other — which also makes the
     * response a 500, and a 500 on a push that wrote everything is exactly the alarm wanted here.
     *
     * @return void
     */
    public function testASerialThatCannotBeRecordedIsReported(): void
    {
        self::assertTrue(new Directory($this->sandbox . '/public')->create());

        // File::write() fails on a path whose directory is missing, and does so deliberately.
        $unwritable = new File($this->sandbox . '/no-such-directory/.update-serial');

        $response = $this->respond(
            $this->push($this->archive(['public/new.txt' => 'hello'])),
            serial: $unwritable,
        );

        self::assertSame(HttpStatusCode::InternalServerError, self::statusOf($response));
        self::assertStringContainsString('! the update serial', self::bodyOf($response));
        self::assertStringContainsString('can be replayed', self::bodyOf($response));
        self::assertStringContainsString('+ public/new.txt', self::bodyOf($response), 'the push itself failed');
    }

    // ───────────────────────────── helpers ─────────────────────────────

    /**
     * An applier that can only reach the sandbox.
     *
     * **The important word is *only*.** This used to be `new UpdateApplier()`, with the sandbox
     * supplied through `$_SERVER['DOCUMENT_ROOT']` — which reached `Config::webroot()`, which
     * grafted the sandbox's *basename* onto the real deployment directory and handed back this
     * repository's `public/`. `src/` was never redirected at all. The mirror then deleted both.
     * Injecting the whole {@link Deployment} is what makes that unreachable rather than unlikely.
     *
     * @return UpdateApplier
     */
    private function applier(): UpdateApplier
    {
        return new UpdateApplier(new Deployment(
            new Directory($this->sandbox),
            new Directory($this->sandbox . '/public'),
        ));
    }

    /**
     * @return UpdateGate
     */
    private function gate(): UpdateGate
    {
        return new UpdateGate($this->keyFile, $this->serialFile);
    }

    /**
     * A signed push, with every knob a test might want to turn wrong.
     *
     * @param string $archive
     * @param bool $tamper
     * @param OpenSSLAsymmetricKey|null $signWith
     * @param int|null $serial
     * @param string|null $digest
     * @param int|null $size
     * @param bool $apply
     * @param bool $mirror
     * @return string
     */
    private function push(
        string $archive,
        bool $tamper = false,
        ?OpenSSLAsymmetricKey $signWith = null,
        ?int $serial = null,
        ?string $digest = null,
        ?int $size = null,
        bool $apply = true,
        bool $mirror = false,
    ): string {
        $manifest = json_encode([
            'serial' => $serial ?? time(),
            'digest' => $digest ?? hash('sha256', $archive),
            'size'   => $size ?? strlen($archive),
            'apply'  => $apply,
            'mirror' => $mirror,
        ], JSON_THROW_ON_ERROR);

        $signature = null;
        openssl_sign($manifest, $signature, $signWith ?? $this->privateKey, OPENSSL_ALGO_SHA256);

        $body = UpdatePayload::MAGIC
            . pack('N', strlen($manifest)) . $manifest
            . pack('N', strlen((string) $signature)) . (string) $signature
            . $archive;

        if ($tamper) {
            $body[strlen($body) - 1] = $body[strlen($body) - 1] === 'A' ? 'B' : 'A';
        }

        return $body;
    }

    /**
     * What the gate makes of $body, arriving as a POST to `/update`.
     *
     * @param string $body
     * @param string $method
     * @return array{UpdateManifest, string}|null
     */
    private function verdict(string $body, string $method = 'POST'): ?array
    {
        $request = self::request($method, SitePath::Update->value);

        return PhpInputStream::around($body, fn(): ?array => $this->gate()->accepts($request));
    }

    /**
     * A gzipped ustar archive holding $files, keyed by member name.
     *
     * @param array<string, string> $files
     * @return string
     */
    private function archive(array $files): string
    {
        $tar = '';

        foreach ($files as $name => $contents) {
            $tar .= self::member($name, $contents);
        }

        return (string) gzencode($tar . str_repeat("\0", self::BLOCK * 2));
    }

    /**
     * One raw ustar member — built here rather than by `TarWriter`, which cannot write the shapes
     * these tests need to prove are refused.
     *
     * @param string $name
     * @param string $contents
     * @param string $type
     * @param int|null $sizeOverride A size the member does not actually carry, for truncation.
     * @param string $prefix The second of ustar's two name fields, and the only way a member name
     *                        exceeds the 100-byte `name` field at all — so also the only way to
     *                        reach the reader's own 255-byte bound, since prefix + '/' + name tops
     *                        out at 256.
     * @return string
     */
    private static function member(
        string $name,
        string $contents,
        string $type = '0',
        ?int $sizeOverride = null,
        string $prefix = '',
    ): string {
        $size   = $sizeOverride ?? ($type === '0' ? strlen($contents) : 0);
        $header = pack(
            'a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12',
            $name,
            "0000644\0",
            "0000000\0",
            "0000000\0",
            sprintf('%011o', $size) . "\0",
            sprintf('%011o', 0) . "\0",
            '        ',
            $type,
            '',
            'ustar',
            '00',
            '',
            '',
            "0000000\0",
            "0000000\0",
            $prefix,
            '',
        );

        $sum = 0;
        for ($i = 0; $i < self::BLOCK; $i++) {
            $sum += ord($header[$i]);
        }
        $header = substr_replace($header, sprintf('%06o', $sum) . "\0 ", 148, 8);

        if ($type !== '0' || $contents === '') {
            return $header;
        }

        $pad = strlen($contents) % self::BLOCK;

        return $header . $contents . ($pad === 0 ? '' : str_repeat("\0", self::BLOCK - $pad));
    }

    /**
     * @param bool $apply
     * @param bool $mirror
     * @return UpdateManifest
     */
    private static function manifest(bool $apply = true, bool $mirror = false): UpdateManifest
    {
        return UpdateManifest::parse(json_encode([
            'serial' => time(),
            'digest' => str_repeat('a', 64),
            'size'   => 0,
            'apply'  => $apply,
            'mirror' => $mirror,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * A request for $path, arrived by $method.
     *
     * @param string $method
     * @param string $path
     * @return Request
     */
    private static function request(string $method, string $path): Request
    {
        $_SERVER = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $path];

        return Request::fromGlobals();
    }

    /**
     * The whole endpoint, end to end: a real request carrying $body, a real gate over the
     * sandbox's key and serial, and an applier that can reach nothing but the sandbox.
     *
     * @param string $body
     * @param UpdateApplier|null $applier
     * @param File|null $serial
     * @return PlainTextResponse
     */
    private function respond(
        string $body,
        ?UpdateApplier $applier = null,
        ?File $serial = null,
    ): PlainTextResponse {
        $controller = new UpdateController(
            new UpdateGate($this->keyFile, $serial ?? $this->serialFile),
            $applier ?? $this->applier(),
        );

        $request  = self::request('POST', SitePath::Update->value);
        $response = PhpInputStream::around($body, static fn(): object => $controller->handle($request));

        self::assertInstanceOf(PlainTextResponse::class, $response);

        return $response;
    }

    /**
     * @param object $response
     * @return HttpStatusCode
     */
    private static function statusOf(object $response): HttpStatusCode
    {
        return new ReflectionProperty($response, 'status')->getValue($response);
    }

    /**
     * @param object $response
     * @return string
     */
    private static function bodyOf(object $response): string
    {
        return new ReflectionProperty($response, 'body')->getValue($response);
    }

    /**
     * @return OpenSSLAsymmetricKey
     */
    private static function otherKey(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);

        return $key;
    }

    /**
     * @param string $path
     * @return void
     */
    private static function removeTree(string $path): void
    {
        foreach ((array) glob($path . '/{,.}*', GLOB_BRACE) as $entry) {
            $entry = (string) $entry;
            $name  = basename($entry);

            if ($name === '.' || $name === '..') {
                continue;
            }

            is_dir($entry) ? self::removeTree($entry) : @unlink($entry);
        }

        @rmdir($path);
    }
}
