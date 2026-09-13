<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use Phpanta\Test\SourceNames;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The line between Phpanta and this site, held from this side: nothing under `phpanta/` names a
 * site class.
 *
 * The framework holds the line itself now. Its own {@link \Phpanta\Test\Unit\BoundaryTest} resolves
 * every name its `src/`, `tools/` and `test/` write and fails on anything that is not the framework's,
 * PHP's, or (in tooling and tests) a development dependency's — and a class of this site is none of
 * those, so a reach into `NeuroSYS\` fails there whenever the framework's suite runs here, which
 * `composer unit` always does. Its links test is the framework's too, over the same three trees.
 *
 * What is left is genuinely this site's: the one question only a site that vendors the framework can
 * ask, named in its own namespace. It is redundant with the framework's suite by design, and kept as
 * the smallest statement of the rule this site's CLAUDE.md makes, so that running this suite alone
 * still holds it. The names are resolved by the framework's {@link SourceNames}, so both sides of
 * the line read the code the same way; comments do not count, which is the verify script's grep's
 * job.
 */
#[CoversNothing]
final class BoundaryTest extends TestCase
{
    /**
     * @return void
     */
    public function testNothingInTheFrameworkNamesTheSite(): void
    {
        $reaches = [];

        foreach (self::frameworkFiles() as $path) {
            foreach (SourceNames::classes($path) as $class) {
                if (str_starts_with($class, 'NeuroSYS\\')) {
                    $reaches[] = substr($path, strlen(NEUROSYS_ROOT) + 1) . ' → ' . $class;
                }
            }
        }

        $this->assertSame([], $reaches, 'A framework file names a site class. Phpanta cannot know about the site.');
    }

    /**
     * The framework's own tree is not empty — a walk that found nothing would pass the test above.
     *
     * @return void
     */
    public function testTheFrameworkTreeIsWhereTheWalkLooks(): void
    {
        $this->assertGreaterThan(100, count(SourceTree::framework()->classes()));
        $this->assertGreaterThan(count(SourceTree::framework()->classes()), count(self::frameworkFiles()));
    }

    /**
     * Every PHP file the framework ships or develops with: `src/`, `tools/` and `test/`.
     *
     * @return list<string>
     */
    private static function frameworkFiles(): array
    {
        return SourceTree::framework()->files(NEUROSYS_ROOT . '/phpanta/tools', NEUROSYS_ROOT . '/phpanta/test');
    }
}
