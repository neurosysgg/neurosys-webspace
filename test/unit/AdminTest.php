<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Config;
use NeuroSYS\Controller\StatsController;
use NeuroSYS\Http\Header;
use NeuroSYS\Http\Request;
use NeuroSYS\Service\Auth;
use NeuroSYS\View\StatsView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * The admin path: the gate, and the log it protects.
 *
 * The gate had never been exercised. `data/admin.php` ships with an empty `pass_hash`, so
 * `Auth::accepts()` short-circuits on its first operand and neither `hash_equals()` nor
 * `password_verify()` runs — which means `test/basic_test.sh`'s two `/admin/stats → 401` checks
 * prove the route is gated without ever comparing a credential. These tests supply a real bcrypt
 * hash, so the comparison itself is what is under test.
 */
#[CoversClass(Auth::class)]
#[CoversClass(StatsController::class)]
final class AdminTest extends TestCase
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

    private function temp(string $contents, string $extension): string
    {
        $file = sys_get_temp_dir() . '/neurosys-test-' . bin2hex(random_bytes(6)) . $extension;
        file_put_contents($file, $contents);
        $this->tempFiles[] = $file;

        return $file;
    }

    /**
     * A credentials file of the shape both gates read.
     *
     * Cost 4 is bcrypt's minimum and keeps the suite fast; `password_verify()` reads the cost out
     * of the hash, so this exercises exactly the same code path a production hash does.
     */
    private function credentials(string $user, string $password): string
    {
        $hash = $password === '' ? '' : password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]);

        return $this->temp(
            '<?php return ' . var_export(['user' => $user, 'pass_hash' => $hash], true) . ';',
            '.php',
        );
    }

    private function request(string $user, string $password): Request
    {
        $_SERVER = ['REQUEST_URI' => '/admin/stats', 'PHP_AUTH_USER' => $user, 'PHP_AUTH_PW' => $password];

        return Request::fromGlobals();
    }

    // ───────────────────────────── the gate ─────────────────────────────

    public function testTheRightUserAndPasswordAreAccepted(): void
    {
        self::assertTrue(
            Auth::accepts($this->request('admin', 'hunter2'), $this->credentials('admin', 'hunter2')),
        );
    }

    /** The comparison neither suite had ever run. */
    #[DataProvider('wrongCredentialProvider')]
    public function testAnythingOtherThanTheRightPairIsRejected(string $user, string $password): void
    {
        self::assertFalse(
            Auth::accepts($this->request($user, $password), $this->credentials('admin', 'hunter2')),
        );
    }

    public static function wrongCredentialProvider(): iterable
    {
        yield 'wrong password'        => ['admin', 'hunter3'];
        yield 'wrong user'            => ['root', 'hunter2'];
        yield 'both wrong'            => ['root', 'hunter3'];
        yield 'no credentials'        => ['', ''];
        yield 'empty password'        => ['admin', ''];
        yield 'empty user'            => ['', 'hunter2'];
        yield 'password prefix'       => ['admin', 'hunter'];
        yield 'password with suffix'  => ['admin', 'hunter22'];
        yield 'user prefix'           => ['adm', 'hunter2'];
        yield 'case-changed user'     => ['Admin', 'hunter2'];
        yield 'case-changed password' => ['admin', 'Hunter2'];
        yield 'the hash as password'  => ['admin', '$2y$04$'];
    }

    /**
     * The password comparison runs even when the user name is wrong.
     *
     * `hash_equals() && password_verify()` leaked, as a pair, what neither leaks alone: bcrypt is
     * deliberately slow, so a wrong user name came back in microseconds while a right one paid the
     * full cost. That difference is measurable across a network, and it tells an attacker which
     * half of the credential they already hold — the half a brute-force attempt gets no other
     * feedback on.
     *
     * Measured rather than read off the source, because the property is behavioural. Cost 10 puts a
     * verify in the tens of milliseconds; the floor asserted here is a small fraction of that, and
     * a short-circuit would return in microseconds — so the gap is three orders of magnitude and
     * load can only push the measurement the safe way.
     */
    public function testAWrongUserNameStillPaysForThePasswordCheck(): void
    {
        $file = $this->temp(
            '<?php return ' . var_export([
                'user'      => 'admin',
                'pass_hash' => password_hash('hunter2', PASSWORD_BCRYPT, ['cost' => 10]),
            ], true) . ';',
            '.php',
        );

        $start = hrtime(true);
        self::assertFalse(Auth::accepts($this->request('wrong-user-entirely', 'hunter2'), $file));
        $elapsed = (hrtime(true) - $start) / 1_000_000;

        self::assertGreaterThan(
            1.0,
            $elapsed,
            'A wrong user name returned before bcrypt could have run — the comparisons are '
            . 'short-circuiting again, which makes the user name enumerable by timing.',
        );
    }

    /**
     * An unconfigured gate is closed, not open. This is the state the repository actually ships:
     * `data/admin.php` is a placeholder whose `pass_hash` is empty, because the live credentials
     * are uploaded by hand and `deploy.sh` excludes the file.
     */
    public function testAnEmptyHashAcceptsNobodyIncludingAnEmptyPassword(): void
    {
        $file = $this->credentials('admin', '');

        self::assertFalse(Auth::accepts($this->request('admin', ''), $file));
        self::assertFalse(Auth::accepts($this->request('admin', 'hunter2'), $file));
    }

    /** The placeholder in the repository, checked as the file it is rather than as a fixture. */
    public function testTheShippedAdminPlaceholderAcceptsNobody(): void
    {
        $file = Config::dataPath('admin.php');

        self::assertFalse(Auth::accepts($this->request('admin', ''), $file));
        self::assertFalse(Auth::accepts($this->request('admin', 'admin'), $file));
    }

    /**
     * Absent is how pre-launch auth is switched off, and `data/site_auth.php` is gitignored so
     * the repository copy cannot switch it on.
     */
    public function testTheSiteGateDoesNothingWhenThereIsNoCredentialsFile(): void
    {
        Auth::requireSiteAuth($this->request('', ''), '/nonexistent/site_auth.php');

        // Reaching this line is the assertion: the gate ends the request when it refuses, so a
        // wrong outcome here would take the whole suite down with it rather than fail one test.
        self::assertTrue(true);
    }

    #[DataProvider('gateProvider')]
    public function testAGateLetsTheRightCredentialsThrough(string $method): void
    {
        Auth::{$method}($this->request('preview', 'hunter2'), $this->credentials('preview', 'hunter2'));

        self::assertTrue(true);
    }

    public static function gateProvider(): iterable
    {
        yield 'site'  => ['requireSiteAuth'];
        yield 'admin' => ['requireAdminAuth'];
    }

    /**
     * The one page on the site reached by handing over a password, and the only one told not to be
     * kept. `no-store` keeps it out of the disk cache a shared or borrowed machine would leave it
     * in, and `private` says the same to anything in between.
     *
     * Reached through reflection for the same reason parseLog() is: handle() calls
     * requireAdminAuth() against `data/admin.php`, whose shipped pass_hash is empty, so nothing in
     * this repository can get past the gate to the response behind it. The header is the part worth
     * asserting, and it does not need the gate opened to be asserted.
     */
    public function testTheStatsPageTellsTheBrowserNotToKeepIt(): void
    {
        $response = new ReflectionMethod(StatsController::class, 'response')
            ->invoke(null, new StatsView(0, [], [], false));

        $headers = new ReflectionProperty($response, 'headers')->getValue($response);

        self::assertSame(
            ['Cache-Control: no-store, private'],
            array_map(static fn(Header $h): string => $h->line(), $headers),
        );
    }

    // ───────────────────────── the log the gate protects ─────────────────────────

    /**
     * @param string $log The log file's contents.
     *
     * @return array{int, array<string, int>, array<string, int>}
     */
    private function parse(string $log): array
    {
        /** @var array{int, array<string, int>, array<string, int>} $stats */
        $stats = new ReflectionMethod(StatsController::class, 'parseLog')
            ->invoke(new StatsController($this->temp($log, '.log')));

        return $stats;
    }

    private static function entry(string $time, string $slug, string $format): string
    {
        return json_encode(compact('time', 'slug', 'format') + ['referrer' => ''], JSON_THROW_ON_ERROR);
    }

    public function testAMissingLogParsesAsNoDownloadsRatherThanFailing(): void
    {
        self::assertSame([0, [], []], new ReflectionMethod(StatsController::class, 'parseLog')
            ->invoke(new StatsController('/nonexistent/downloads.log')));
    }

    public function testAnEmptyLogParsesAsNoDownloads(): void
    {
        self::assertSame([0, [], []], $this->parse(''));
    }

    public function testOneEntryIsCountedOnceUnderItsSlugFormatAndDay(): void
    {
        self::assertSame(
            [1, ['ill/flac' => 1], ['2026-06-17' => 1]],
            $this->parse(self::entry('2026-06-17T09:00:00+00:00', 'ill', 'flac') . "\n"),
        );
    }

    public function testEntriesAggregateByFormatAndByDayIndependently(): void
    {
        $log = implode("\n", [
            self::entry('2026-06-17T09:00:00+00:00', 'ill', 'flac'),
            self::entry('2026-06-17T10:00:00+00:00', 'ill', 'flac'),
            self::entry('2026-06-17T11:00:00+00:00', 'ill', 'mp3'),
            self::entry('2026-06-18T09:00:00+00:00', 'hello-world', 'flac'),
        ]);

        self::assertSame(
            [
                4,
                ['ill/flac' => 2, 'ill/mp3' => 1, 'hello-world/flac' => 1],
                ['2026-06-17' => 3, '2026-06-18' => 1],
            ],
            $this->parse($log),
        );
    }

    /**
     * A log is an append-only file a crash can truncate mid-line, so one bad line must cost that
     * line and nothing else — the page it feeds is the only way anyone would find out.
     */
    #[DataProvider('badLineProvider')]
    public function testABadLineIsSkippedAndTheGoodOnesStillCount(string $bad): void
    {
        $good = self::entry('2026-06-17T09:00:00+00:00', 'ill', 'flac');

        self::assertSame([1, ['ill/flac' => 1], ['2026-06-17' => 1]], $this->parse("$bad\n$good\n"));
    }

    public static function badLineProvider(): iterable
    {
        yield 'blank'     => [''];
        yield 'whitespace' => ['   '];
        yield 'not json'  => ['this is not json'];
        yield 'truncated' => ['{"slug":"ill","format":"fl'];
        yield 'scalar'    => ['42'];
        yield 'json null' => ['null'];
    }

    /** substr('', 0, 10) is '', which is falsy — so an entry with no time is filed under '?'. */
    public function testAnEntryWithNoTimeIsFiledUnderAnUnknownDay(): void
    {
        self::assertSame(
            [1, ['ill/flac' => 1], ['?' => 1]],
            $this->parse('{"slug":"ill","format":"flac"}' . "\n"),
        );
    }
}
