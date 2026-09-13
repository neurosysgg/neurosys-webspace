<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\DataFile;
use NeuroSYS\Site;
use Phpanta\Model\Health\Area;
use Phpanta\Model\Health\Finding;
use Phpanta\Service\Health\DataFileRequirement;
use Phpanta\Service\Health\LogDirectoryRequirement;
use Phpanta\Service\Health\WebrootRequirement;
use Phpanta\Support\File;
use Phpanta\Support\RequirementInitialization;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `/api/health/v1/*` as this site declares it: its deployment requirements, over its own data files.
 *
 * The framework's floor, its parity with `composer.json` and the service that checks it are the
 * framework's suite's, run under its own test app. What is left here is what only this site can
 * answer: which of its data files are tracked, and that a tracked one is really there in a clone.
 *
 * The requirement classes a declaration is built from are named below as well as its subjects, for
 * the `#[CoversClass]` reason docs/testing.md gives.
 */
#[CoversClass(RequirementInitialization::class)]
#[CoversClass(WebrootRequirement::class)]
#[CoversClass(DataFileRequirement::class)]
#[CoversClass(LogDirectoryRequirement::class)]
#[CoversClass(Finding::class)]
final class HealthTest extends TestCase
{
    /**
     * The deployment is `DOCUMENT_ROOT`, the tracked files and the log directory — no more. An
     * untracked file absent is state, not a fault, and is `capability`'s to report.
     *
     * @return void
     */
    public function testTheDeploymentDeclaresExactlyTheTrackedFilesAndTheLogDirectory(): void
    {
        $expected = ['DOCUMENT_ROOT', 'PHPANTA_ENVIRONMENT'];
        foreach (Site::current()->dataFiles() as $file) {
            if ($file->isTracked()) {
                $expected[] = $file->value;
            }
        }
        $expected[] = 'logs/';

        $declared = [];
        foreach (RequirementInitialization::requirements(Site::current()) as $requirement) {
            if ($requirement->area() === Area::Deployment) {
                $declared[] = $requirement->name();
            }
        }

        self::assertSame($expected, $declared);
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
}
