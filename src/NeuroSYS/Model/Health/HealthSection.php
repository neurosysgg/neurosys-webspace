<?php

declare(strict_types=1);

namespace NeuroSYS\Model\Health;

use NeuroSYS\Support\BareString;
use NeuroSYS\Support\Collection;

/**
 * The HealthSection class. One heading of {@link \NeuroSYS\Service\Api\HealthReport} and whatever
 * sits under it.
 *
 * **Two constructors, because the report has two kinds of section and only one kind of shape.**
 * Five of them are facts — a name in a column and a value beside it — and the sixth is the tail of
 * an error log, which is lines of somebody else's text with no name to give them. Both end as a
 * caption and an indented block, so what this holds is the block: a `Collection<string>` of
 * rendered lines, with {@link self::facts()} and {@link self::lines()} the two ways in.
 *
 * That is why the indent lives here rather than on {@link HealthFact}. A section places its lines;
 * a fact only decides what one says. Left on the fact, the log tail would have needed the same
 * number written a second time in a second class, which is the failure this codebase names
 * everywhere else.
 *
 * {@link self::facts()} takes a collection and {@link self::lines()} takes a variadic, and the
 * difference is not inconsistency: every fact section is built by filtering and mapping an
 * existing set — {@link PhpSetting::cases()}, {@link PhpExtension::cases()},
 * {@link \NeuroSYS\DataFile::cases()} — so a variadic there would mean spreading a collection only
 * to have it rebuilt, where the log's lines are written out at their one call site and a variadic
 * is a check PHP makes for free.
 *
 * The caption is a plain string and stays one. It is the report's own copy rather than a
 * vocabulary anything else reads: nothing selects on it, nothing parses it, and the six that exist
 * are written in the one class that shows them.
 */
#[BareString(
    'string',
    "a scalar type name standing in a class-string's place, spelled the way get_debug_type() "
    . 'spells it. Support\\TypedItems and Model\\Update\\UpdateReport carry the same excuse for '
    . 'the same word; this is the collection of rendered lines a section is, and join() will only '
    . 'join a collection that says it holds strings.',
)]
final readonly class HealthSection
{
    /** How far a section's lines sit under its caption. */
    private const string INDENT = '  ';

    /**
     * Constructs an instance of {@link self}.
     *
     * Private, so a section is one of the two shapes below rather than any block of text that
     * happens to be handed in already indented.
     *
     * @param string $caption
     * @param Collection<string> $lines Rendered and indented, in the order they should be read.
     */
    private function __construct(private string $caption, private Collection $lines) {}

    /**
     * A section of named values.
     *
     * @param string $caption
     * @param Collection<HealthFact> $facts
     * @return self
     */
    public static function facts(string $caption, Collection $facts): self
    {
        return new self(
            $caption,
            $facts->map(static fn(HealthFact $fact): string => self::INDENT . $fact->render()),
        );
    }

    /**
     * A section of plain text — today, the error log's own lines.
     *
     * @param string $caption
     * @param string ...$lines
     * @return self
     */
    public static function lines(string $caption, string ...$lines): self
    {
        return new self(
            $caption,
            new Collection('string')->with(...$lines)
                ->map(static fn(string $line): string => self::INDENT . $line),
        );
    }

    /**
     * The caption and everything under it, with no trailing newline — joining sections is
     * {@link \NeuroSYS\Service\Api\HealthReport}'s to do, the way joining lines is this class's.
     *
     * @return string
     */
    public function render(): string
    {
        return $this->caption . "\n" . $this->lines->join("\n");
    }
}
