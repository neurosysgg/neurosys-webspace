<?php

/**
 * Merges the two suites' coverage into one report.
 *
 * PHPUnit measures `test/unit/`; `tools/coverage-prepend.php` measures the dev server
 * `test/basic_test.sh` drives. They cover deliberately different things — see `docs/testing.md` —
 * so neither number alone says what the site's tests actually reach. This unions them.
 *
 * Usage:
 *   php tools/merge-coverage.php <unit.cov> <e2e-dump-dir> [--clover <file>] [--html <dir>]
 *
 * Normally run through `composer coverage`, which produces both inputs first.
 */

declare(strict_types=1);

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData;
use SebastianBergmann\CodeCoverage\Driver\Selector;
use SebastianBergmann\CodeCoverage\Filter;
use SebastianBergmann\CodeCoverage\Report\Clover;
use SebastianBergmann\CodeCoverage\Report\Html\Facade as Html;
use SebastianBergmann\CodeCoverage\Report\Text;
use SebastianBergmann\CodeCoverage\Report\Thresholds;
use SebastianBergmann\CodeCoverage\Serialization\Unserializer;

require __DIR__ . '/../vendor/autoload.php';

$root = dirname(__DIR__);

// Hand-parsed rather than getopt(): getopt() stops at the first non-option argument, so it would
// silently ignore every --clover/--html that follows the two paths and write no report at all.
$positional = [];
$options    = [];

for ($i = 1; $i < $argc; $i++) {
    if (str_starts_with($argv[$i], '--')) {
        $options[substr($argv[$i], 2)] = $argv[++$i] ?? '';
    } else {
        $positional[] = $argv[$i];
    }
}

[$unit, $dumps] = $positional + [null, null];

if ($unit === null || $dumps === null) {
    fwrite(STDERR, "usage: php tools/merge-coverage.php <unit.cov> <e2e-dir> [--clover f] [--html d]\n");
    exit(2);
}

// The same set phpunit.xml.dist's <source> names: every .php file under src/.
$sources = [];

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src')) as $file) {
    if ($file->getExtension() === 'php') {
        $sources[] = $file->getPathname();
    }
}

$filter = new Filter();
$filter->includeFiles($sources);

// CodeCoverage insists on a driver even though nothing here collects: this script only reads
// what the two suites already recorded. `composer coverage` sets the mode; a bare run needs it too.
try {
    $driver = new Selector()->forLineCoverage($filter);
} catch (Throwable $exception) {
    fwrite(STDERR, 'tools/merge-coverage.php needs coverage mode: ' . $exception->getMessage() . "\n");
    exit(2);
}

$coverage = new CodeCoverage($driver, $filter);

// PHPUnit's half, already processed: line hits attributed to the tests that produced them.
// Serialization strips the common prefix off every path, so put it back before merging in the
// dev server's dumps, which carry absolute ones.
$serialized = new Unserializer()->unserialize($unit);
$unitData   = $serialized['codeCoverage'];

foreach (array_keys($unitData->lineCoverage()) as $relative) {
    $unitData->renameFile($relative, $serialized['basePath'] . DIRECTORY_SEPARATOR . $relative);
}

$coverage->setData($unitData);

// The dev server's half, one dump per request it handled.
$requests = glob($dumps . '/*.cov') ?: [];

foreach ($requests as $dump) {
    /** @var array<string, array<int, int>> $raw */
    $raw = unserialize((string) file_get_contents($dump), ['allowed_classes' => false]);

    $coverage->append(RawCodeCoverageData::fromLineCoverage($raw), 'e2e:' . basename($dump, '.cov'));
}

printf("Merged test/unit/ with %d request(s) from the verify script.\n", count($requests));

$report = $coverage->getReport();

echo new Text(Thresholds::default(), showUncoveredFiles: true)->process($report, true);

if (isset($options['clover'])) {
    new Clover()->process($report, (string) $options['clover']);
}

if (isset($options['html'])) {
    new Html()->process($report, (string) $options['html']);
}
