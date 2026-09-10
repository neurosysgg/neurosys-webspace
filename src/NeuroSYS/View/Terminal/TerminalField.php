<?php

declare(strict_types=1);

namespace NeuroSYS\View\Terminal;

use NeuroSYS\Support\BareArray;
use NeuroSYS\Text\Language;
use NeuroSYS\Text\Translatable;

/**
 * The TerminalField class. One key/value row of terminal output.
 *
 * A typed row rather than a string of markup, so a view declares what the terminal says and
 * {@link Terminal} decides how it crosses to the client.
 *
 * **Either half may be translated.** A caption is — `artist`, `künstler` — and a value may be:
 * `ready` is, a tempo is not. So a row is put into a language only when it is encoded, by
 * {@link TerminalFields}, which happens at render; see {@link self::row()}.
 */
final readonly class TerminalField
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string|Translatable $key   The row's label, rendered in the fixed-width first column.
     * @param string|Translatable $value The row's value.
     * @param TerminalTone        $tone  How the row reads.
     */
    public function __construct(
        public string|Translatable $key,
        public string|Translatable $value,
        public TerminalTone        $tone = TerminalTone::Plain,
    ) {}

    /**
     * The row as it crosses to `<terminal-window>`, in $language, keyed by {@link TerminalFieldKey}.
     *
     * A method rather than `JsonSerializable`, which the row once was: `jsonSerialize()` takes no
     * argument, and a translated caption cannot be written down without being told the language.
     *
     * @param Language $language
     * @return array<string, string>
     */
    #[BareArray(
        'the input to json_encode(), keyed by TerminalFieldKey: the shape the client reads, which is '
        . 'not ours to type',
    )]
    public function row(Language $language): array
    {
        return [
            TerminalFieldKey::Key->value   => self::text($this->key, $language),
            TerminalFieldKey::Value->value => self::text($this->value, $language),
            TerminalFieldKey::Tone->value  => $this->tone->value,
        ];
    }

    /**
     * @param string|Translatable $text
     * @param Language            $language
     * @return string
     */
    private static function text(string|Translatable $text, Language $language): string
    {
        return $text instanceof Translatable ? $text->in($language) : $text;
    }
}
