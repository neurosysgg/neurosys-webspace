<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use Phpanta\Test\Floor;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The site stands on the framework's floor and owns none of it.
 *
 * {@link \Phpanta\Test\Unit\FloorTest} pins which framework classes may call the built-ins that
 * reach outside the process — the disk, a process, the network, randomness, digests, ciphers, the
 * response, the runtime. This holds the site's half, and the site's half is empty: every file it
 * reads is a {@link \Phpanta\Support\File} from `Site::current()->dataFile()`, every response
 * leaves through `App::run()`, and nothing under `src/NeuroSYS/` calls one of those built-ins
 * itself. A site class that needed to would be a question for the framework — a primitive it
 * lacks — rather than an exception written here. See phpanta/docs/floor.md.
 *
 * It executes no line of `src/`.
 */
#[CoversNothing]
final class FloorTest extends TestCase
{
    /**
     * No class under `src/NeuroSYS/` calls a floor built-in.
     *
     * @return void
     */
    public function testTheSiteCallsNothingOnTheFloor(): void
    {
        $calls = [];

        foreach (Floor::callsUnder(SourceTree::site()) as $family => $functions) {
            foreach ($functions as $function => $classes) {
                foreach (array_keys($classes) as $class) {
                    $calls[] = "$class calls $function() ($family)";
                }
            }
        }

        self::assertSame([], $calls, 'a site class reaching past the framework\'s floor');
    }

    /**
     * The rule reads the site's tree, rather than passing because it read nothing.
     *
     * @return void
     */
    public function testTheRuleReadsTheSitesClasses(): void
    {
        self::assertGreaterThan(50, count(SourceTree::site()->classes()));
    }
}
