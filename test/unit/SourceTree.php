<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every class under the two source trees — the site's and the framework's — named the way the two
 * autoloaders name them.
 *
 * Four tests used to walk `src/NeuroSYS/` with their own copy of the same loop, and each would have
 * had to learn about `phpanta/src/` separately when the framework moved out; one of them not
 * learning it would be a rule that quietly stopped reading half the code. So there is one walk,
 * over both roots, and the mapping from a path to a class is each root's own prefix — the same one
 * its autoloader uses, so a file this cannot name is one production could not have loaded either.
 */
final class SourceTree
{
    /**
     * Each root, under the repository, beside the namespace prefix its autoloader maps to it.
     *
     * @var array<string, string>
     */
    public const array ROOTS = [
        '/src/NeuroSYS/' => 'NeuroSYS\\',
        '/phpanta/src/'  => 'Phpanta\\',
    ];

    /** Cache for {@link self::classes()}: every test asks, and the answer does not change mid-run. */
    private static ?array $classes = null;

    /**
     * Every class, interface, enum and trait under both roots, as `absolute path => class-string`,
     * sorted by path.
     *
     * @return array<string, class-string>
     */
    public static function classes(): array
    {
        if (self::$classes !== null) {
            return self::$classes;
        }

        $classes = [];

        foreach (self::ROOTS as $directory => $prefix) {
            foreach (self::phpFilesUnder(NEUROSYS_ROOT . $directory) as $path) {
                $relative = substr($path, strlen(NEUROSYS_ROOT . $directory), -strlen('.php'));
                $class    = $prefix . str_replace('/', '\\', $relative);

                if (class_exists($class) || interface_exists($class) || enum_exists($class) || trait_exists($class)) {
                    /** @var class-string $class */
                    $classes[$path] = $class;
                }
            }
        }

        ksort($classes);

        return self::$classes = $classes;
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
        $paths = [];

        foreach ([...array_keys(self::ROOTS), ...$extra] as $directory) {
            $paths = [...$paths, ...self::phpFilesUnder(NEUROSYS_ROOT . $directory)];
        }

        sort($paths);

        return $paths;
    }

    /**
     * @param string $directory
     * @return list<string>
     */
    private static function phpFilesUnder(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $paths = [];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }

        return $paths;
    }
}
