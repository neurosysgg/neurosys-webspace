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

    /**
     * The row as it crosses to `<terminal-window>`, keyed by {@link TerminalFieldKey}.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            TerminalFieldKey::Key->value   => $this->key,
            TerminalFieldKey::Value->value => $this->value,
            TerminalFieldKey::Tone->value  => $this->tone->value,
        ];
    }
}
