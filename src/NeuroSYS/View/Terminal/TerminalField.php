<?php

declare(strict_types=1);

namespace NeuroSYS\View\Terminal;

use JsonSerializable;
use NeuroSYS\Support\BareArray;

/**
 * The TerminalField class. One key/value row of terminal output.
 *
 * A typed row rather than a string of markup, so a view declares what the terminal says and
 * {@link Terminal} decides how it crosses to the client.
 *
 * **`JsonSerializable` rather than a `toArray()` a caller maps over.** The array was only ever
 * built to be handed straight to `json_encode`, and mapping to one meant
 * {@link Terminal::toElement()} asking a `Collection<TerminalField>` for a collection of arrays —
 * which {@link \NeuroSYS\Support\TypedItems::SCALARS} refuses, and rightly: the escape hatch a
 * collection exists to close should not reopen at a JSON door. Implementing the interface lets the
 * encoder ask each row for itself, so the rows cross as a `Collection` right up to the encode.
 */
final readonly class TerminalField implements JsonSerializable
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
    #[BareArray('JsonSerializable::jsonSerialize() is the interface; its shape is not ours to choose')]
    public function jsonSerialize(): array
    {
        return [
            TerminalFieldKey::Key->value   => $this->key,
            TerminalFieldKey::Value->value => $this->value,
            TerminalFieldKey::Tone->value  => $this->tone->value,
        ];
    }
}
