<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use Phpanta\Test\SourceTree as Tree;

/**
 * Every class under the two source trees — the site's and the framework's — named the way the two
 * autoloaders name them.
 *
 * The walk itself is the framework's {@link Tree}, parameterised by a root and its prefix, so the
 * framework's suite and this one read a tree the same way. This is the site's view over it: the
 * site's tree on its own, the framework's as vendored here, and the two together for the rules
 * that still read everything this repository ships. One of the two roots quietly dropping out of
 * a rule would be a rule that stopped reading half the code, which is why there is one place that
 * names both.
 */
final class SourceTree
{
    /**
     * `src/NeuroSYS/`, under `NeuroSYS\`.
     *
     * @return Tree
     */
    public static function site(): Tree
    {
        return new Tree(NEUROSYS_ROOT . '/src/NeuroSYS', 'NeuroSYS\\');
    }

    /**
     * `phpanta/src/`, under `Phpanta\` — the framework as this site vendors it.
     *
     * @return Tree
     */
    public static function framework(): Tree
    {
        return new Tree(NEUROSYS_ROOT . '/phpanta/src', 'Phpanta\\');
    }

    /**
     * Every class, interface, enum and trait under both roots, as `absolute path => class-string`,
     * sorted by path.
     *
     * @return array<string, class-string>
     */
    public static function classes(): array
    {
        $classes = [...self::framework()->classes(), ...self::site()->classes()];

        ksort($classes);

        return $classes;
    }

    /**
     * Every PHP file under both roots and any further repository directories named, as absolute
     * paths, sorted — for the rules that read the filesystem rather than the autoloader, and so can
     * reach `tools/lib/` too.
     *
     * @param string ...$extra Repository-relative directories, such as `/tools/lib`.
     * @return list<string>
     */
    public static function files(string ...$extra): array
    {
        $beside = array_map(static fn(string $directory): string => NEUROSYS_ROOT . $directory, $extra);
        $paths  = [...self::framework()->files(), ...self::site()->files(...$beside)];

        sort($paths);

        return $paths;
    }
}
