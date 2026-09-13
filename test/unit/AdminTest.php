<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use JsonException;
use NeuroSYS\Controller\StatsController;
use NeuroSYS\Service\DownloadStats;
use NeuroSYS\Site;
use NeuroSYS\View\StatsView;
use Phpanta\CredentialFile;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\Request;
use Phpanta\Http\ResponseHeader;
use Phpanta\Service\Auth;
use Phpanta\Support\Directory;
use Phpanta\Support\File;
use Phpanta\Test\TestRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\RandomException;

/**
 * The admin path: the shipped gate, the page behind it, and the log it protects.
 *
 * The comparison itself — the right pair, every wrong one, the timing rule, an empty hash — is the
 * framework's `AuthTest`, against credentials files it writes. What stays here is what only this
 * repository makes true: that the `data/admin.php` it ships opens for nobody, that `/admin/stats`
 * is behind it, and what the stats page does with the log.
 */
#[CoversClass(Auth::class)]
#[CoversClass(StatsController::class)]
#[CoversClass(DownloadStats::class)]
final class AdminTest extends TestCase
{
    private Directory $fixtures;

    /** Names one fixture from the next inside the one directory. */
    private int $written = 0;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->fixtures = Directory::temporary('neurosys-admin-');
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->fixtures->remove();
    }

    /**
     * @param string $contents
     * @param string $extension
     * @return File
     * @throws RandomException
     */
    private function temp(string $contents, string $extension): File
    {
        $file = $this->fixtures->file(++$this->written . $extension);

        $file->write($contents);

        return $file;
    }

    /**
     * @param string $user
     * @param string $password
     * @return Request
     */
    private static function request(string $user, string $password): Request
    {
        return TestRequest::get('/admin/stats')->withCredentials($user, $password)->request();
    }

    // ───────────────────────────── the gate ─────────────────────────────

    /**
     * The placeholder in the repository, checked as the file it is rather than as a fixture. An
     * unconfigured gate is closed, not open: `pass_hash` is empty because the live credentials are
     * uploaded by hand and `deploy.sh` excludes the file.
     *
     * @return void
     */
    public function testTheShippedAdminPlaceholderAcceptsNobody(): void
    {
        $file = Site::current()->dataFile(CredentialFile::Admin);

        self::assertFalse(Auth::accepts(self::request('admin', ''), $file));
        self::assertFalse(Auth::accepts(self::request('admin', 'admin'), $file));
    }

    /**
     * The stats page, asked for without a password, answers the challenge — end to end, through the
     * router and the controller, in-process. The shipped `data/admin.php` has an empty hash, so no
     * credential opens it here; the refusal is the whole of what can be asserted, and it is enough.
     *
     * @return void
     */
    public function testTheStatsPageAnswersAChallengeWithoutThePassword(): void
    {
        $answer = TestRequest::get('/admin/stats')->withCredentials('admin', 'admin')->answer();

        self::assertSame(HttpStatusCode::Unauthorized, $answer->status());
        self::assertNotNull($answer->header(ResponseHeader::WwwAuthenticate));
        self::assertStringNotContainsString('<main', $answer->body());
    }

    /**
     * The one page on the site reached by handing over a password, and the only one told not to be
     * kept. `no-store` keeps it out of the disk cache a shared or borrowed machine would leave it
     * in, and `private` says the same to anything in between — and a page told how it may be kept
     * carries no `ETag`, so there is no validator to hand it back on.
     *
     * The controller is asked directly: the gate is its route's — see
     * {@link RoutingTest::testTheStatsPageIsTheOneRouteBehindTheAdminGate()} — so the page behind it
     * can be answered without a password the repository does not hold.
     *
     * @return void
     */
    public function testTheStatsPageTellsTheBrowserNotToKeepIt(): void
    {
        $request = self::request('admin', 'admin');
        $answer  = new StatsController(new File('/nonexistent/downloads.log'))->handle($request)->answer($request);

        self::assertSame(HttpStatusCode::Ok, $answer->status());
        self::assertSame('no-store, private', $answer->header(ResponseHeader::CacheControl)?->value->render());
        self::assertNull($answer->header(ResponseHeader::ETag));
    }

    // ───────────────────────── the log the gate protects ─────────────────────────

    /**
     * A log file's contents, read the way the controller reads them and added up.
     *
     * Flattened back to a tuple on the way out, so the table of expectations below stays one line
     * per case — {@link DownloadStats} is what stopped that tuple being the *interface*.
     *
     * @param string $log The log file's contents.
     *
     * @return array{int, array<string, int>, array<string, int>}
     * @throws RandomException
     */
    private function parse(string $log): array
    {
        return self::tally(DownloadStats::fromLines($this->temp($log, '.log')->lines()));
    }

    /**
     * @param DownloadStats $stats
     * @return array{int, array<string, int>, array<string, int>}
     */
    private static function tally(DownloadStats $stats): array
    {
        return [$stats->total, $stats->byFormat->toArray(), $stats->byDay->toArray()];
    }

    /**
     * @param string $time
     * @param string $slug
     * @param string $format
     * @return string
     * @throws JsonException
     */
    private static function entry(string $time, string $slug, string $format): string
    {
        return json_encode(compact('time', 'slug', 'format') + ['referrer' => ''], JSON_THROW_ON_ERROR);
    }

    /**
     * @return void
     */
    public function testAMissingLogParsesAsNoDownloadsRatherThanFailing(): void
    {
        self::assertSame([0, [], []], self::tally(
            DownloadStats::fromLines(new File('/nonexistent/downloads.log')->lines()),
        ));
    }

    /**
     * @return void
     */
    public function testAnEmptyLogParsesAsNoDownloads(): void
    {
        self::assertSame([0, [], []], $this->parse(''));

        // Empty is not the same as absent: this one was read and held nothing, and the page says a
        // different sentence for each. See StatsView.
        self::assertTrue(DownloadStats::fromLines([])->isEmpty());
    }

    /**
     * @return void
     * @throws JsonException
     */
    public function testOneEntryIsCountedOnceUnderItsSlugFormatAndDay(): void
    {
        self::assertSame(
            [1, ['ill/flac' => 1], ['2026-06-17' => 1]],
            $this->parse(self::entry('2026-06-17T09:00:00+00:00', 'ill', 'flac') . "\n"),
        );
    }

    /**
     * @return void
     * @throws JsonException
     */
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
     *
     * @param string $bad
     * @return void
     */
    #[DataProvider('badLineProvider')]
    public function testABadLineIsSkippedAndTheGoodOnesStillCount(string $bad): void
    {
        $good = self::entry('2026-06-17T09:00:00+00:00', 'ill', 'flac');

        self::assertSame([1, ['ill/flac' => 1], ['2026-06-17' => 1]], $this->parse("$bad\n$good\n"));
    }

    /**
     * @return iterable
     */
    public static function badLineProvider(): iterable
    {
        yield 'blank'     => [''];
        yield 'whitespace' => ['   '];
        yield 'not json'  => ['this is not json'];
        yield 'truncated' => ['{"slug":"ill","format":"fl'];
        yield 'scalar'    => ['42'];
        yield 'json null' => ['null'];
    }

    /**
     * substr('', 0, 10) is '', which is falsy — so an entry with no time is filed under '?'.
     *
     * @return void
     */
    public function testAnEntryWithNoTimeIsFiledUnderAnUnknownDay(): void
    {
        self::assertSame(
            [1, ['ill/flac' => 1], ['?' => 1]],
            $this->parse('{"slug":"ill","format":"flac"}' . "\n"),
        );
    }
}
