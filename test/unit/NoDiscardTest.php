<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;

/**
 * The results this site may not drop on the floor, pinned in both directions.
 *
 * Everything immutable here builds by copying — `with()`, `allow()`, `attr()`, `containing()` —
 * and every one of those was named so that a discarded call would *read* as wrong: `$c->add(…)`
 * as a statement looks finished, `$c->with(…)` as a statement looks like somebody forgot the
 * left-hand side. That was the whole enforcement mechanism, and it was a naming convention doing
 * a compiler's job. PHP 8.5's `#[\NoDiscard]` is the compiler doing it: a call whose result goes
 * nowhere is an E_WARNING, and `phpunit.xml.dist` has `failOnWarning`, so it is a failing test.
 *
 * The set is asserted rather than left to each class's own good judgement, for the same reason
 * {@link HtmlTest::testExactlyTheAddressCarryingAttributesAreCheckedAsUrls()} asserts its own: an
 * attribute nobody remembered to add is a guarantee that silently is not there, and the page looks
 * right either way. Adding a copy-returning builder means adding it here too.
 *
 * `Auth::accepts()` is the one member that is not a builder, and it is the one where dropping the
 * result is not merely useless but unsafe: it is the gate's entire decision, and the two `require*`
 * methods are only the challenge wrapped around it.
 *
 * The deliberate discards are all in the tests — proving that a builder did not mutate what it was
 * called on, or that a bad argument threw — and each is spelled `(void)`, which says out loud what
 * the test is there to demonstrate.
 */
#[CoversNothing]
final class NoDiscardTest extends TestCase
{
    /**
     * Every method under `src/` whose result the caller must use.
     *
     * Four copy-returning builders, and the auth gate's decision.
     */
    public function testExactlyTheseResultsMayNotBeDiscarded(): void
    {
        self::assertSame(
            [
                'NeuroSYS\Http\Security\ContentSecurityPolicy::allow',
                'NeuroSYS\Service\Auth::accepts',
                'NeuroSYS\Support\Collection::with',
                'NeuroSYS\Support\SearchableCollection::with',
                'NeuroSYS\View\Html\Element::attr',
                'NeuroSYS\View\Html\Element::containing',
            ],
            array_keys(self::noDiscardMethods()),
        );
    }

    /**
     * Every one carries its own sentence, because the default warning does not have one.
     *
     * A bare `#[\NoDiscard]` says the return value should be used, which the reader already
     * suspected. What is worth saying is *why the call did nothing* — that `with()` copies rather
     * than appends, that a dropped `allow()` never reaches the header — and that only fits in the
     * message. An empty attribute is the same shape of mistake as an unlabelled magic number.
     */
    public function testEachOneSaysWhyTheResultMatters(): void
    {
        foreach (self::noDiscardMethods() as $method => $message) {
            self::assertNotSame('', $message, $method . ' carries a bare #[\NoDiscard]');
        }
    }

    /**
     * The `#[\NoDiscard]` methods under `src/`, keyed `Class::method`, valued by their message.
     *
     * Reflection rather than a source grep, unlike the audits in {@link HtmlTest}: what is being
     * asserted here is which methods *carry* the attribute, and the engine is the thing that knows.
     *
     * @return array<string, string>
     */
    private static function noDiscardMethods(): array
    {
        $found = [];

        foreach (self::classesUnderSrc() as $class) {
            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                foreach ($method->getAttributes(\NoDiscard::class) as $attribute) {
                    /** @var array{0?: string} $arguments */
                    $arguments = $attribute->getArguments();

                    $found[$class . '::' . $method->getName()] = $arguments[0] ?? '';
                }
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * Every class, interface, enum and trait under `src/`, derived from its path.
     *
     * The autoloader maps the two onto each other, so a file this cannot name is a file the site
     * could not have loaded either.
     *
     * Traits are included because they are where a `#[\NoDiscard]` could most easily hide: PHP
     * flattens a trait's methods into the using class, so `getDeclaringClass()` below names the
     * class and the trait's own file is never otherwise visited. A trait carrying one would be
     * counted twice rather than not at all, which is the direction this test can survive.
     *
     * @return list<class-string>
     */
    private static function classesUnderSrc(): array
    {
        $root  = NEUROSYS_ROOT . '/src/NeuroSYS/';
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        $classes = [];

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root), -strlen('.php'));
            $class    = 'NeuroSYS\\' . str_replace('/', '\\', $relative);

            if (
                class_exists($class)
                || interface_exists($class)
                || enum_exists($class)
                || trait_exists($class)
            ) {
                /** @var class-string $class */
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }
}
