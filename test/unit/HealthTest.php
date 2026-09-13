<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\DataFile;
use NeuroSYS\Site;
use Phpanta\Http\Api\HealthAction;
use Phpanta\Http\HttpMethod;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\PlainTextResponse;
use Phpanta\Model\Api\ApiEnvelope;
use Phpanta\Model\Api\VerifiedRequest;
use Phpanta\Model\Health\Area;
use Phpanta\Model\Health\ByteFloor;
use Phpanta\Model\Health\ExtensionRequirement;
use Phpanta\Model\Health\Finding;
use Phpanta\Model\Health\HealthFact;
use Phpanta\Model\Health\HealthResult;
use Phpanta\Model\Health\HealthSection;
use Phpanta\Model\Health\Level;
use Phpanta\Model\Health\Outcome;
use Phpanta\Model\Health\PhpExtension;
use Phpanta\Model\Health\PhpSetting;
use Phpanta\Model\Health\Requirement;
use Phpanta\Model\Health\SecondsFloor;
use Phpanta\Model\Health\SettingRequirement;
use Phpanta\Model\Health\Toggle;
use Phpanta\Model\Health\Verdict;
use Phpanta\Model\Health\VersionRequirement;
use Phpanta\Service\Api\HealthCheck;
use Phpanta\Service\ApiGate;
use Phpanta\Service\Health\DataFileRequirement;
use Phpanta\Service\Health\LogDirectoryRequirement;
use Phpanta\Service\Health\WebrootRequirement;
use Phpanta\Support\Collection;
use Phpanta\Support\Directory;
use Phpanta\Support\File;
use Phpanta\Support\RequirementInitialization;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `/api/health/v1/*`: whether this host meets what this site declares it needs.
 *
 * The core — what a requirement is and how a set of them reads — is {@link RequirementTest}'s. This
 * file is about **this site's declarations** and the service that checks them, and most of it is
 * **parity**: the floors are stated elsewhere too — `composer.json`, {@link ApiGate::MAX_BODY} —
 * and a declaration that drifted from what it restates would be a health check reassuring somebody
 * about a site that no longer exists.
 *
 * The deployment requirements are driven both ways here, because the branch the live host takes —
 * a `DOCUMENT_ROOT` that resolves — is never the one a CLI run takes on its own.
 *
 * The core classes a check runs through are named below as well as its subjects, for the
 * `#[CoversClass]` reason docs/testing.md gives.
 */
#[CoversClass(HealthAction::class)]
#[CoversClass(HealthCheck::class)]
#[CoversClass(RequirementInitialization::class)]
#[CoversClass(WebrootRequirement::class)]
#[CoversClass(DataFileRequirement::class)]
#[CoversClass(LogDirectoryRequirement::class)]
#[CoversClass(PhpExtension::class)]
#[CoversClass(HealthResult::class)]
#[CoversClass(Outcome::class)]
#[CoversClass(Verdict::class)]
#[CoversClass(Finding::class)]
#[CoversClass(HealthFact::class)]
#[CoversClass(HealthSection::class)]
#[CoversClass(VersionRequirement::class)]
#[CoversClass(ExtensionRequirement::class)]
#[CoversClass(SettingRequirement::class)]
#[CoversClass(ByteFloor::class)]
#[CoversClass(SecondsFloor::class)]
#[CoversClass(Toggle::class)]
final class HealthTest extends TestCase
{
    private string $documentRoot = '';

    /**
     * Remembers `DOCUMENT_ROOT`, the one piece of global state these tests turn.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->documentRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        if ($this->documentRoot === '') {
            unset($_SERVER['DOCUMENT_ROOT']);
        } else {
            $_SERVER['DOCUMENT_ROOT'] = $this->documentRoot;
        }
    }

    // ───────────────────────────── the actions ─────────────────────────────

    /**
     * Every action is a read on GET, answered by a health check.
     *
     * @return void
     */
    public function testEveryActionIsAReadAnsweringOnGet(): void
    {
        foreach (HealthAction::cases() as $action) {
            $handler = $action->handler(self::verified('/api/health/v1/' . $action->value));

            self::assertSame(HttpMethod::Get, $action->method());
            self::assertInstanceOf(HealthCheck::class, $handler);
            self::assertFalse($handler->isWrite(), 'a check that changes nothing must not spend a serial');
        }
    }

    /**
     * Every area has exactly one address, and `report` is the one that names none.
     *
     * {@link Area} is the core's vocabulary and {@link HealthAction} the API's, and this is what
     * keeps them one list: an area added without an address fails here rather than being checkable
     * only as part of the whole report.
     *
     * @return void
     */
    public function testEveryAreaHasExactlyOneAddress(): void
    {
        foreach (Area::cases() as $area) {
            $naming = 0;

            foreach (HealthAction::cases() as $action) {
                $naming += $action->area() === $area ? 1 : 0;
            }

            self::assertSame(1, $naming, $area->value . ' has ' . $naming . ' addresses');
        }

        self::assertNull(HealthAction::Report->area());
    }

    // ───────────────────────────── parity ─────────────────────────────

    /**
     * The extensions {@link PhpExtension} names are exactly the ones `composer.json` requires —
     * and exactly the ones declared, every one of them required.
     *
     * **This is the point of the whole service, turned into an assertion.** `composer.json` never
     * runs on the server and `test/basic_test.sh` runs a developer's PHP; the declaration is what
     * the live host is actually asked, and a declaration that drifted from composer's list would
     * reassure somebody about extensions the site does not depend on.
     *
     * `require-dev`'s `ext-curl` is deliberately not here: the site makes no outbound request at
     * all, and the one class that does is tooling `deploy.sh` never uploads.
     *
     * @return void
     */
    public function testTheExtensionsDeclaredAreExactlyTheOnesComposerRequires(): void
    {
        $required = [];
        foreach (self::composer()['require'] as $package => $constraint) {
            if (str_starts_with($package, 'ext-')) {
                $required[] = substr($package, 4);
            }
        }

        $named = [];
        foreach (PhpExtension::cases() as $extension) {
            $named[] = $extension->value;
        }

        $declared = [];
        foreach (self::declared(Area::Extensions) as $requirement) {
            self::assertSame(Level::Required, $requirement->level(), $requirement->name() . ' is not required');
            $declared[] = $requirement->name();
        }

        sort($required);
        sort($named);
        sort($declared);

        self::assertSame($required, $named);
        self::assertSame($required, $declared);
    }

    /**
     * The PHP floor is `composer.json`'s.
     *
     * @return void
     */
    public function testThePhpFloorIsComposers(): void
    {
        $version = self::declared(Area::Runtime)->first(
            static fn(Requirement $requirement): bool => $requirement instanceof VersionRequirement,
        );

        self::assertInstanceOf(VersionRequirement::class, $version);
        self::assertSame(self::composer()['require']['php'], '^' . $version->minimum);
    }

    /**
     * The two size floors are derived from the body cap rather than written out — `post_max_size`
     * is the cap itself, and `memory_limit` holds the three copies of it a push has at its peak.
     *
     * @return void
     */
    public function testTheSizeFloorsAreDerivedFromTheBodyCap(): void
    {
        self::assertSame(ApiGate::MAX_BODY, self::floor(PhpSetting::PostMaxSize)->bytes);
        self::assertGreaterThanOrEqual(3 * ApiGate::MAX_BODY, self::floor(PhpSetting::MemoryLimit)->bytes);
    }

    /**
     * Every declared extension proves itself here, by being used.
     *
     * If this fails on a developer's machine it is the same failure it would be on the live host,
     * which is the only reason the check is worth anything.
     *
     * @return void
     */
    public function testEveryDeclaredExtensionProvesItself(): void
    {
        $response = new HealthCheck(RequirementInitialization::requirements(), Area::Extensions)->handle();

        self::assertSame(HttpStatusCode::Ok, UpdateFixture::statusOf($response));
        self::assertStringNotContainsString(Verdict::Fail->label(), UpdateFixture::bodyOf($response));
    }

    // ───────────────────────────── the deployment ─────────────────────────────

    /**
     * The deployment is `DOCUMENT_ROOT`, the tracked files and the log directory — no more. An
     * untracked file absent is state, not a fault, and is `capability`'s to report.
     *
     * @return void
     */
    public function testTheDeploymentDeclaresExactlyTheTrackedFilesAndTheLogDirectory(): void
    {
        $expected = ['DOCUMENT_ROOT'];
        foreach (Site::current()->dataFiles() as $file) {
            if ($file->isTracked()) {
                $expected[] = $file->value;
            }
        }
        $expected[] = 'logs/';

        $declared = [];
        foreach (self::declared(Area::Deployment) as $requirement) {
            $declared[] = $requirement->name();
        }

        self::assertSame($expected, $declared);
    }

    /**
     * A webroot that resolves is met, naming the directory.
     *
     * @return void
     */
    public function testAResolvableWebrootIsMet(): void
    {
        $_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 2) . '/public';

        self::assertEquals(new Finding(dirname(__DIR__, 2) . '/public', true), new WebrootRequirement()->check());
    }

    /**
     * One that does not resolve is unmet, and the finding is the refusal — never a throw, which
     * would reach the controller's catch and turn the report into a 422.
     *
     * @return void
     */
    public function testAWebrootThatWillNotResolveIsUnmetRatherThanThrown(): void
    {
        unset($_SERVER['DOCUMENT_ROOT']);

        $finding = new WebrootRequirement()->check();

        self::assertFalse($finding->met);
        self::assertStringContainsString('DOCUMENT_ROOT is not set', $finding->found);
    }

    /**
     * A file that is there is met, with its size; one that is not is unmet.
     *
     * The absent side uses the download log, which nothing writes while logging is off and whose
     * directory no clone has — the one data file guaranteed absent everywhere this runs.
     *
     * @return void
     */
    public function testADataFileIsMetOnlyWhereItIsThere(): void
    {
        $releases = new File(dirname(__DIR__, 2) . '/data/' . DataFile::Releases->value);

        self::assertEquals(
            new Finding($releases->size() . ' bytes', true),
            new DataFileRequirement(DataFile::Releases)->check(),
        );
        self::assertEquals(
            new Finding('no file there', false),
            new DataFileRequirement(DataFile::DownloadLog)->check(),
        );
    }

    /**
     * The log directory is met where PHP can write into it, and says which of the two ways it is
     * not — the fix for each differs. Optional either way: without it the site is only harder to
     * diagnose.
     *
     * @return void
     */
    public function testTheLogDirectoryIsMetOnlyWhereItCanBeWrittenInto(): void
    {
        $sandbox = Directory::temporary('neurosys-health-');

        try {
            self::assertSame(Level::Optional, new LogDirectoryRequirement($sandbox)->level());
            self::assertEquals(new Finding('writable', true), new LogDirectoryRequirement($sandbox)->check());
            self::assertEquals(
                new Finding('no directory there', false),
                new LogDirectoryRequirement($sandbox->directory('logs'))->check(),
            );

            chmod($sandbox->path, 0o500);

            if (is_writable($sandbox->path)) {
                self::markTestSkipped('this process can write to a read-only directory');
            }

            self::assertEquals(
                new Finding('not writable by this process', false),
                new LogDirectoryRequirement($sandbox)->check(),
            );
        } finally {
            chmod($sandbox->path, 0o700);
            $sandbox->remove();
        }
    }

    /**
     * One area's check is that area's status: the deployment fails without a webroot and passes
     * with one, and says which line decided it.
     *
     * @return void
     */
    public function testAnUnmetRequirementIsA503CarryingTheReport(): void
    {
        unset($_SERVER['DOCUMENT_ROOT']);
        $failing = new HealthCheck(RequirementInitialization::requirements(), Area::Deployment)->handle();

        $_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 2) . '/public';
        $passing = new HealthCheck(RequirementInitialization::requirements(), Area::Deployment)->handle();

        self::assertSame(HttpStatusCode::ServiceUnavailable, UpdateFixture::statusOf($failing));
        self::assertMatchesRegularExpression('/^  DOCUMENT_ROOT +FAIL /m', UpdateFixture::bodyOf($failing));
        self::assertStringEndsWith(" 1 fail\n", UpdateFixture::bodyOf($failing));
        self::assertSame(HttpStatusCode::Ok, UpdateFixture::statusOf($passing));
    }

    /**
     * The whole report is one plain-text response, with a section for every area.
     *
     * @return void
     */
    public function testTheReportChecksEveryArea(): void
    {
        $response = new HealthCheck(RequirementInitialization::requirements())->handle();
        $body     = UpdateFixture::bodyOf($response);

        self::assertInstanceOf(PlainTextResponse::class, $response);
        self::assertStringEndsWith(" fail\n", $body);

        foreach (Area::cases() as $area) {
            self::assertStringContainsString($area->value . "\n", $body);
        }
    }

    // ───────────────────────────── fixtures ─────────────────────────────

    /**
     * The declared requirements in one area.
     *
     * @param Area $area
     * @return Collection<Requirement>
     */
    private static function declared(Area $area): Collection
    {
        return RequirementInitialization::requirements()
            ->where(static fn(Requirement $requirement): bool => $requirement->area() === $area);
    }

    /**
     * The byte floor declared for $setting.
     *
     * @param PhpSetting $setting
     * @return ByteFloor
     */
    private static function floor(PhpSetting $setting): ByteFloor
    {
        $requirement = self::declared(Area::Settings)->first(
            static fn(Requirement $requirement): bool => $requirement->name() === $setting->value,
        );

        self::assertInstanceOf(SettingRequirement::class, $requirement);
        self::assertInstanceOf(ByteFloor::class, $requirement->constraint);

        return $requirement->constraint;
    }

    /**
     * `composer.json`, decoded.
     *
     * @return array{require: array<string, string>}
     */
    private static function composer(): array
    {
        $composer = json_decode(
            (string) new File(dirname(__DIR__, 2) . '/composer.json')->read(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($composer);
        self::assertIsArray($composer['require']);

        return $composer;
    }

    /**
     * What the gate would hand an action for a GET of $path.
     *
     * @param string $path
     * @return VerifiedRequest
     */
    private static function verified(string $path): VerifiedRequest
    {
        $manifest = (string) json_encode([
            'serial' => time(),
            'method' => HttpMethod::Get->value,
            'path'   => $path,
            'digest' => hash('sha256', ''),
            'size'   => 0,
        ], JSON_THROW_ON_ERROR);

        return new VerifiedRequest(ApiEnvelope::parse($manifest), $manifest, '');
    }
}
