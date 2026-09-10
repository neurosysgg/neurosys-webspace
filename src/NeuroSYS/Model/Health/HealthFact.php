<?php

declare(strict_types=1);

namespace NeuroSYS\Model\Health;

/**
 * The HealthFact class. One thing {@link \NeuroSYS\Service\Api\HealthReport} has to say, and the
 * one line it says it on.
 *
 * A class rather than the `array{string, string}` it would otherwise be, for
 * {@link \NeuroSYS\Model\Api\VerifiedRequest}'s reason: a two-slot tuple is destructured in the one
 * place that reads it, where `[$name, $value] = $pair` only reads correctly if you already know
 * the answer. Two named properties need no excuse and nothing remembered.
 *
 * **Its whole behaviour is one column.** A report of forty facts across five sections is read by
 * running an eye down the values, and that works only if every section aligns with every *other*
 * section rather than each with itself. So the width is a constant here rather than the longest
 * name in whichever section a fact happened to land in.
 *
 * The indent is deliberately **not** here: a fact renders its own line and {@link HealthSection}
 * places it, which is what lets a section of log lines sit at the same indent without a second
 * class knowing the number.
 */
final readonly class HealthFact
{
    /**
     * How wide the name column is.
     *
     * Sized for the longest name the report has today — `max_execution_time`, at eighteen. A
     * longer one is not an error and is not cut down: it pushes its own value across by however
     * much it overruns, and the next line is back in the column. That is the right failure for a
     * report — one ragged line, rather than a name truncated into a different name.
     */
    private const int COLUMN = 20;

    /**
     * What a fact with nothing to say says.
     *
     * The same dash {@link \NeuroSYS\Service\Api\UpdateVersion} writes for a serial no push has
     * ever set, and for the same reason: an empty value in a column of values is indistinguishable
     * from a line that failed to render.
     *
     * **Asked as `=== ''` and not as `?:`, which is where that class can be copied and this one
     * cannot.** A serial is a `time()` and is never `'0'`, so the falsy test is safe there. Half
     * the values here are php.ini directives, and `max_execution_time` is **`'0'`** on a runtime
     * with no limit at all — a real answer, and the most interesting one that directive has.
     * `?:` reported it as nothing, which was this report's first bug and was visible in its first
     * run.
     */
    private const string NOTHING = '-';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $name What is being reported. Wherever the fact has a vocabulary of its own
     *                     this is that vocabulary's backing value rather than a literal — a
     *                     {@link PhpSetting}, a {@link PhpExtension}, a {@link \NeuroSYS\DataFile}
     *                     — so a name in the report is a name in the code.
     * @param string $value Its value, or `''` where there is none to be had. Empty and absent
     *                      collapse the way {@link \NeuroSYS\Support\File::read()} collapses them:
     *                      to whoever is reading, both mean this did not tell us anything.
     */
    public function __construct(public string $name, public string $value) {}

    /**
     * The fact's line: the name in its column, then the value.
     *
     * @return string
     */
    public function render(): string
    {
        return str_pad($this->name, self::COLUMN) . ' ' . ($this->value === '' ? self::NOTHING : $this->value);
    }
}
