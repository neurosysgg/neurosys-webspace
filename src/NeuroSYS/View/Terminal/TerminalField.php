<?php

declare(strict_types=1);

namespace NeuroSYS\View\Terminal;

/**
 * The TerminalField class. One key/value row of terminal output.
 *
 * A typed row rather than a string of markup, so a view declares what the terminal says and
 * {@link Terminal} decides how it crosses to the client.
 */
final readonly class TerminalField
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string       $key   The row's label, rendered in the fixed-width first column.
     * @param string       $value The row's value.
     * @param TerminalTone $tone  How the row reads.
     */
    public function __construct(
        public string       $key,
        public string       $value,
        public TerminalTone $tone = TerminalTone::Plain,
    ) {}

    /** @return array{key: string, value: string, tone: string} */
    public function toArray(): array
    {
        return ['key' => $this->key, 'value' => $this->value, 'tone' => $this->tone->value];
    }
}
