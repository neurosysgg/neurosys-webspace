<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NoDiscard;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The results this site may not drop on the floor, pinned in both directions.
 *
 * PHP 8.5's `#[\NoDiscard]` makes a call whose result goes nowhere an E_WARNING, and
 * `phpunit.xml.dist` has `failOnWarning`, so it is a failing test. The framework's own
 * {@link \Phpanta\Test\Unit\NoDiscardTest} pins every one under `phpanta/src/` and makes the
 * argument for the copy-returning builders, the collections' queries and the gates; this pins the
 * site's half, under `src/NeuroSYS/`, on the same terms. The set is asserted rather than left to
 * each class's own good judgement, for the same reason
 * {@link HtmlTest::testExactlyTheAddressCarryingAttributesAreCheckedAsUrls()} asserts its own: an
 * attribute nobody remembered to add is a guarantee that silently is not there, and the page looks
 * right either way. Adding a copy-returning builder means adding it here too.
 *
 * `WaveformBand::bands()` is the odd one out: it neither copies nor queries, it *is* three cases.
 * It answers with a {@link \Phpanta\Support\Collection} because {@link GuidelineTest} found it was
 * the one group under `src/` that had stayed a bare array with no door and no variadic behind it —
 * and once it is a collection it belongs here, on the same terms as everything else that answers
 * with one.
 *
 * `DemoGate::admits()` is a gate's entire decision, where dropping the result is not merely useless
 * but unsafe. It is the third credential check beside the framework's `Auth::accepts()`, which asks
 * a `data/` file for the site and admin gates; the demo's credential is a
 * {@link \Phpanta\Support\PasswordHash} on the demo itself. `DemoGate::enter()` is the gate wrapped
 * around it, one step on: it *returns* the 401 rather than ending the request, so the caller has to
 * return it in turn, and a call whose result goes nowhere is the refusal thrown away and the door
 * left open.
 */
#[CoversNothing]
final class NoDiscardTest extends TestCase
{
    /**
     * Every method under `src/NeuroSYS/` whose result the caller must use.
     *
     * @return void
     */
    public function testExactlyTheseResultsMayNotBeDiscarded(): void
    {
        self::assertSame(
            [
                'NeuroSYS\Model\WaveformBand::bands',
                'NeuroSYS\Service\DemoGate::admits',
                'NeuroSYS\Service\DemoGate::enter',
            ],
            array_keys(self::noDiscardMethods()),
        );
    }

    /**
     * Every one carries its own sentence, because the default warning does not have one.
     *
     * A bare `#[\NoDiscard]` says the return value should be used, which the reader already
     * suspected. What is worth saying is *why the call did nothing*, and that only fits in the
     * message. An empty attribute is the same shape of mistake as an unlabelled magic number.
     *
     * @return void
     */
    public function testEachOneSaysWhyTheResultMatters(): void
    {
        foreach (self::noDiscardMethods() as $method => $message) {
            self::assertNotSame('', $message, $method . ' carries a bare #[\NoDiscard]');
        }
    }

    /**
     * The `#[\NoDiscard]` methods under `src/NeuroSYS/`, keyed `Class::method`, valued by their
     * message.
     *
     * Reflection rather than a source grep, unlike the audits in {@link HtmlTest}: what is being
     * asserted here is which methods *carry* the attribute, and the engine is the thing that knows.
     * Traits are walked too, since PHP flattens a trait's methods into the using class and a trait
     * carrying one is then counted twice rather than not at all.
     *
     * @return array<string, string>
     */
    private static function noDiscardMethods(): array
    {
        $found = [];

        foreach (SourceTree::site()->classes() as $class) {
            foreach (new ReflectionClass($class)->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                foreach ($method->getAttributes(NoDiscard::class) as $attribute) {
                    /** @var array{0?: string} $arguments */
                    $arguments = $attribute->getArguments();

                    $found[$class . '::' . $method->getName()] = $arguments[0] ?? '';
                }
            }
        }

        ksort($found);

        return $found;
    }
}
