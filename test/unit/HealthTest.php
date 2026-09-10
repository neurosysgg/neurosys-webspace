<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\DataFile;
use NeuroSYS\Http\Api\HealthAction;
use NeuroSYS\Http\HttpMethod;
use NeuroSYS\Http\HttpStatusCode;
use NeuroSYS\Http\PlainTextResponse;
use NeuroSYS\Model\Api\ApiEnvelope;
use NeuroSYS\Model\Api\VerifiedRequest;
use NeuroSYS\Model\Health\HealthFact;
use NeuroSYS\Model\Health\HealthSection;
use NeuroSYS\Model\Health\PhpExtension;
use NeuroSYS\Model\Health\PhpSetting;
use NeuroSYS\Service\Api\HealthReport;
use NeuroSYS\Support\Collection;
use NeuroSYS\Support\Directory;
use NeuroSYS\Support\File;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `/api/health/v1/report`: what this deployment says about itself.
 *
 * The report exists because every fact in it was asserted somewhere and checked nowhere, so most
 * of what is worth asserting here is **shape rather than value**. What the live host's
 * `memory_limit` is, is the report's business and not this file's; that the report has a line for
 * it, under the name the enum spells, is exactly what a test can pin — and it is what fails when
 * somebody adds a case to a vocabulary and forgets the one place that shows it.
 *
 * Three properties, and every test here is one of them:
 *
 * - **Completeness.** Every {@link PhpSetting}, every {@link PhpExtension} and every
 *   {@link DataFile} appears, because each of those sets is iterated rather than listed — so a
 *   case added to any of them lands in the report on its own, and this is what says so.
 * - **Parity with what already claimed these facts.** {@link PhpExtension} is the third statement
 *   of the extension list, after `composer.json` and `test/basic_test.sh`, and the first that can
 *   be compared to one of the others in code.
 * - **The branches the live host will actually take.** Its `error_log` is empty and its
 *   `DOCUMENT_ROOT` resolves; a developer's machine is the opposite on both counts. Both sides of
 *   both are driven here, because the interesting one is never the one running the test.
 *
 * {@link HealthFact} and {@link HealthSection} are named below although the sections they render
 * are this file's subject rather than its target — the `#[CoversClass]` trap docs/testing.md
 * describes, which has bitten this suite more than once and is cheaper to avoid than to diagnose.
 */
#[CoversClass(HealthAction::class)]
#[CoversClass(HealthReport::class)]
#[CoversClass(HealthFact::class)]
#[CoversClass(HealthSection::class)]
#[CoversClass(PhpExtension::class)]
#[CoversClass(PhpSetting::class)]
final class HealthTest extends TestCase
{
    /** The caption of the section that is present only when there is a log to quote. */
    private const string LOG = 'error log';

    private string $sandbox = '';
    private string $errorLog = '';
    private string $documentRoot = '';

    /**
     * Remembers the two pieces of global state these tests turn, so tearDown can put them back.
     *
     * Both are deliberate rather than incidental: `error_log` and `DOCUMENT_ROOT` are precisely
     * the inputs the report reads and a test cannot otherwise reach, which is why
     * {@link HealthReport} needs no constructor seam.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->errorLog     = (string) ini_get('error_log');
        $this->documentRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
        $this->sandbox      = sys_get_temp_dir() . '/neurosys-health-' . bin2hex(random_bytes(6));

        new Directory($this->sandbox)->create();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        ini_set('error_log', $this->errorLog);

        if ($this->documentRoot === '') {
            unset($_SERVER['DOCUMENT_ROOT']);
        } else {
            $_SERVER['DOCUMENT_ROOT'] = $this->documentRoot;
        }

        if ($this->sandbox !== '') {
            UpdateFixture::removeTree($this->sandbox);
        }
    }

    // ───────────────────────────── the action ─────────────────────────────

    /**
     * The action answers on GET and hands back the report.
     *
     * @return void
     */
    public function testTheReportActionIsAReadAnsweringOnGet(): void
    {
        $manifest = (string) json_encode([
            'serial' => time(),
            'method' => HttpMethod::Get->value,
            'path'   => '/api/health/v1/report',
            'digest' => hash('sha256', ''),
            'size'   => 0,
        ], JSON_THROW_ON_ERROR);

        $handler = HealthAction::Report->handler(
            new VerifiedRequest(ApiEnvelope::parse($manifest), $manifest, ''),
        );

        self::assertSame(HttpMethod::Get, HealthAction::Report->method());
        self::assertInstanceOf(HealthReport::class, $handler);
        self::assertFalse($handler->isWrite(), 'a report that changes nothing must not spend a serial');
    }

    /**
     * The whole thing is one 200 of plain text, ending in a newline like every other body here.
     *
     * @return void
     */
    public function testTheReportIsOnePlainTextResponse(): void
    {
        $response = new HealthReport()->handle();

        self::assertInstanceOf(PlainTextResponse::class, $response);
        self::assertSame(HttpStatusCode::Ok, UpdateFixture::statusOf($response));
        self::assertStringEndsWith("\n", UpdateFixture::bodyOf($response));
    }

    // ───────────────────────────── completeness ─────────────────────────────

    /**
     * Every section the report promises is in it.
     *
     * @param string $caption
     * @return void
     */
    #[DataProvider('captionProvider')]
    public function testEverySectionIsPresent(string $caption): void
    {
        self::assertStringContainsString($caption . "\n", $this->report());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function captionProvider(): iterable
    {
        foreach (['php', 'extensions', 'errors', 'host', 'deployment'] as $caption) {
            yield $caption => [$caption];
        }
    }

    /**
     * Every name in every vocabulary the report reads has a line of its own.
     *
     * The three sets are iterated by the report rather than listed by it, so this is the test that
     * fails when a case is added to one of them and the report is *not* the place that has to
     * change — which is the arrangement working, stated from the outside.
     *
     * @param string $name
     * @return void
     */
    #[DataProvider('vocabularyProvider')]
    public function testEveryNameItReadsHasItsOwnLine(string $name): void
    {
        self::assertMatchesRegularExpression(
            '/^  ' . preg_quote($name, '/') . ' +\S/m',
            $this->report(),
            'a name in a vocabulary the report iterates, with no line under it',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function vocabularyProvider(): iterable
    {
        foreach (PhpSetting::cases() as $setting) {
            yield 'setting ' . $setting->value => [$setting->value];
        }

        foreach (PhpExtension::cases() as $extension) {
            yield 'extension ' . $extension->value => [$extension->value];
        }

        foreach (DataFile::cases() as $file) {
            yield 'data file ' . $file->value => [$file->value];
        }
    }

    /**
     * A directive lands in exactly one of the two sections that show directives.
     *
     * {@link PhpSetting::isAboutErrors()} is a partition rather than a flag, and a partition is
     * only worth having if nothing can fall out of it or into both halves. The `php` section is
     * everything up to the `extensions` caption; the `errors` section is what sits under its own.
     *
     * @return void
     */
    public function testEveryDirectiveLandsInExactlyOneSection(): void
    {
        $php    = $this->section('php');
        $errors = $this->section('errors');

        foreach (PhpSetting::cases() as $setting) {
            $inPhp    = str_contains($php, "\n  " . $setting->value . ' ');
            $inErrors = str_contains($errors, "\n  " . $setting->value . ' ');

            self::assertTrue(
                $inPhp !== $inErrors,
                $setting->value . ' is in both sections or in neither',
            );
            self::assertSame($setting->isAboutErrors(), $inErrors, $setting->value . ' is in the wrong one');
        }
    }

    // ───────────────────────────── parity ─────────────────────────────

    /**
     * The extensions this enum names are exactly the ones `composer.json` requires.
     *
     * **This is the point of the whole feature, turned into an assertion.** The list existed in
     * two places before — `composer.json`, which never runs on the server because `vendor/` is not
     * deployed, and `test/basic_test.sh`, which runs a developer's PHP — and neither could speak
     * for the host. {@link PhpExtension} is the third statement of it and the first that can be
     * compared against another in code, so this is what stops the report reassuring somebody about
     * a set of extensions the site no longer depends on.
     *
     * `require-dev`'s `ext-curl` is deliberately not here: the site makes no outbound request at
     * all, and the one class that does is tooling `deploy.sh` never uploads.
     *
     * @return void
     */
    public function testTheExtensionsNamedAreExactlyTheOnesComposerRequires(): void
    {
        $composer = json_decode(
            (string) new File(dirname(__DIR__, 2) . '/composer.json')->read(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($composer);
        self::assertIsArray($composer['require']);

        $required = new Collection('string')->with(...array_keys($composer['require']))
            ->where(static fn(string $package): bool => str_starts_with($package, 'ext-'))
            ->map(static fn(string $package): string => substr($package, 4))
            ->toValues();

        $named = new Collection(PhpExtension::class)->with(...PhpExtension::cases())
            ->map(static fn(PhpExtension $extension): string => $extension->value)
            ->toValues();

        sort($required);
        sort($named);

        self::assertSame($required, $named);
    }

    /**
     * Every extension the site declares is here **and working**, asked by using it.
     *
     * If this ever fails on a developer's machine it is the same failure it would be on the live
     * host, which is the only reason the report is worth anything: `class_exists()` on the class
     * the site actually names is a stronger question than `extension_loaded()` on a string.
     *
     * @return void
     */
    public function testEveryDeclaredExtensionProvesItself(): void
    {
        foreach (PhpExtension::cases() as $extension) {
            self::assertTrue($extension->isPresent(), 'ext/' . $extension->value . ' is not usable here');
        }

        self::assertStringNotContainsString('MISSING', $this->section('extensions'));
    }

    // ───────────────────────────── one line ─────────────────────────────

    /**
     * A fact's value is rendered unless there is genuinely none.
     *
     * **`'0'` is the row that matters and is the bug this pins.** `max_execution_time` is `0` on a
     * runtime with no limit — a real answer, and the most interesting one that directive has —
     * and a falsy test printed it as nothing. The report's first run said `-` where it meant
     * "unlimited".
     *
     * @param string $value
     * @param string $expected
     * @return void
     */
    #[DataProvider('factProvider')]
    public function testOnlyAnEmptyValueRendersAsNothing(string $value, string $expected): void
    {
        self::assertSame($expected, trim(new HealthFact('name', $value)->render()));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function factProvider(): iterable
    {
        yield 'a value'      => ['128M', 'name                 128M'];
        yield 'zero'         => ['0', 'name                 0'];
        yield 'the string 0' => ['0.0', 'name                 0.0'];
        yield 'off'          => ['', 'name                 -'];
    }

    /**
     * A name longer than the column pushes its own value across and leaves the next line alone.
     *
     * @return void
     */
    public function testALongNameOverrunsItsOwnLineOnly(): void
    {
        $section = HealthSection::facts('caption', new Collection(HealthFact::class)->with(
            new HealthFact('a-name-much-longer-than-the-column', 'over'),
            new HealthFact('short', 'back'),
        ));

        self::assertSame(
            "caption\n  a-name-much-longer-than-the-column over\n  short                back",
            $section->render(),
        );
    }

    /**
     * A section of plain lines is indented like a section of facts, and by the same class.
     *
     * @return void
     */
    public function testASectionOfLinesIsIndentedLikeOneOfFacts(): void
    {
        self::assertSame(
            "caption\n  first\n  second",
            HealthSection::lines('caption', 'first', 'second')->render(),
        );
    }

    // ───────────────────────────── the error log ─────────────────────────────

    /**
     * With no destination configured there is no section, which is the live host's own state.
     *
     * The `errors` section has already said the destination is empty, so a section repeating it
     * would be the same fact twice — and `error_log` being empty is not a failure to find a log,
     * it is the answer.
     *
     * @return void
     */
    public function testWithNoDestinationThereIsNoLogSection(): void
    {
        ini_set('error_log', '');

        self::assertStringNotContainsString(self::LOG, $this->report());
    }

    /**
     * A destination naming nothing says so, rather than being absent like the case above.
     *
     * The two are different faults and must read differently: nowhere configured is a decision,
     * and a path that is not there is a log somebody expects to exist. `syslog` is a legal value
     * of this directive and lands here too, which is honest — this cannot quote a syslog either.
     *
     * @return void
     */
    public function testADestinationThatIsNotThereSaysSo(): void
    {
        ini_set('error_log', $this->sandbox . '/nowhere.log');

        self::assertStringContainsString('no file there to read', $this->section(self::LOG));
    }

    /**
     * A readable log is quoted, newest lines last, and never more than the tail.
     *
     * @return void
     */
    public function testAReadableLogIsQuotedToItsTail(): void
    {
        $log   = new File($this->sandbox . '/php.log');
        $lines = [];

        for ($i = 1; $i <= 25; $i++) {
            $lines[] = 'line ' . $i;
        }

        self::assertTrue($log->write(implode("\n", $lines) . "\n"));
        ini_set('error_log', $log->path);

        $section = $this->section(self::LOG);

        self::assertStringContainsString('last 20 of 25 lines', $section);
        self::assertStringContainsString("\n  line 25", $section);
        self::assertStringContainsString("\n  line 6", $section);
        self::assertStringNotContainsString("\n  line 5\n", $section, 'the tail is 20 lines, not 21');
    }

    /**
     * An empty log is reported as empty rather than as twenty lines that are not there.
     *
     * @return void
     */
    public function testAnEmptyLogPromisesNothing(): void
    {
        $log = new File($this->sandbox . '/empty.log');

        self::assertTrue($log->write(''));
        ini_set('error_log', $log->path);

        self::assertStringContainsString('0 bytes, last 0 of 0 lines', $this->section(self::LOG));
    }

    /**
     * A log too large to quote is measured and left closed.
     *
     * The cap is what stops a host quietly logging for a year being pulled through one response.
     * The size still crosses, because the size is the diagnostic in that case.
     *
     * @return void
     */
    public function testALogOverTheCapIsMeasuredAndNotRead(): void
    {
        $log = new File($this->sandbox . '/huge.log');

        self::assertTrue($log->write(str_repeat("padding padding padding padding\n", 9000)));
        ini_set('error_log', $log->path);

        $section = $this->section(self::LOG);

        self::assertStringContainsString('too large to quote here', $section);
        self::assertStringNotContainsString('padding', $section);
    }

    // ───────────────────────────── the deployment ─────────────────────────────

    /**
     * A webroot that resolves is reported as the directory it resolves to.
     *
     * @return void
     */
    public function testAResolvableWebrootIsReported(): void
    {
        $_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 2) . '/public';

        self::assertStringContainsString(dirname(__DIR__, 2) . '/public', $this->section('deployment'));
    }

    /**
     * One that does not resolve is reported as the refusal, not as a 422.
     *
     * **This is the one place the handler catches rather than throwing**, and it is the whole
     * difference between a health check and everything else past the gate:
     * {@link \NeuroSYS\Config::webroot()} refuses with an {@link \NeuroSYS\Exception\UpdateException},
     * which {@link \NeuroSYS\Controller\ApiController} would turn into a 422 — so a deployment
     * with a `DOCUMENT_ROOT` it cannot vouch for would answer the question "what is wrong here"
     * by refusing to say anything at all.
     *
     * @return void
     */
    public function testAWebrootThatWillNotResolveIsReportedRatherThanThrown(): void
    {
        unset($_SERVER['DOCUMENT_ROOT']);

        $deployment = $this->section('deployment');

        self::assertStringContainsString('DOCUMENT_ROOT is not set', $deployment);
        self::assertStringContainsString('releases.php', $deployment, 'the rest of the section still renders');
    }

    /**
     * Whether a file is there and whether the repository carries it are reported side by side.
     *
     * Two facts rather than a verdict, so `absent  (tracked)` reads as the fault it is without the
     * report inventing a severity word — and so that neither half is a branch a real deployment
     * never takes.
     *
     * @return void
     */
    public function testEachDataFileReportsBothItsPresenceAndItsTracking(): void
    {
        $deployment = $this->section('deployment');

        foreach (DataFile::cases() as $file) {
            self::assertMatchesRegularExpression(
                '/^  ' . preg_quote($file->value, '/') . ' +(present  \d+|absent)  \('
                . ($file->isTracked() ? '' : 'un') . 'tracked\)$/m',
                $deployment,
            );
        }
    }

    // ───────────────────────────── fixtures ─────────────────────────────

    /**
     * The whole report, as the endpoint would send it.
     *
     * @return string
     */
    private function report(): string
    {
        return UpdateFixture::bodyOf(new HealthReport()->handle());
    }

    /**
     * One section of it: the caption's line and everything up to the blank line after it.
     *
     * @param string $caption
     * @return string
     */
    private function section(string $caption): string
    {
        // Prefixed with a newline so the first section's caption is found the same way as every
        // other one's, rather than by a special case for "at the very start of the body".
        $report = "\n" . $this->report();
        $start  = strpos($report, "\n" . $caption . "\n");

        self::assertNotFalse($start, 'the report has no ' . $caption . ' section');

        $end = strpos($report, "\n\n", $start + 1);

        return substr($report, $start, $end === false ? null : $end - $start);
    }
}
