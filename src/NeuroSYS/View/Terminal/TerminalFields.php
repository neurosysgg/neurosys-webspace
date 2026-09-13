<?php

declare(strict_types=1);

namespace NeuroSYS\View\Terminal;

use JsonException;
use NeuroSYS\Exception\TerminalException;
use NeuroSYS\View\Html\Tag;
use Phpanta\Support\Collection;
use Phpanta\Text\Language;
use Phpanta\Text\Translatable;

/**
 * The TerminalFields class. The rows of a terminal, as the JSON `<terminal-window>` reads — in
 * a language.
 *
 * The rows cross to the client as JSON in one attribute, and a row's caption is translated, so the
 * JSON cannot be written until the language is known. That is at render, which makes this a
 * {@link Translatable}: {@link Terminal::toElement()} hands it to the attribute unencoded, and the
 * element encodes it in whichever language it renders in, like any other translated value.
 */
final readonly class TerminalFields implements Translatable
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Collection<TerminalField> $fields
     */
    public function __construct(private Collection $fields) {}

    /**
     * `JSON_THROW_ON_ERROR` is what makes a row that cannot be serialised loud rather than a silent
     * `false`, and the {@link JsonException} is translated rather than propagated: a terminal whose
     * rows will not encode is a page that cannot be built, which is what every other failure in
     * this layer throws.
     *
     * @param Language $language
     * @return string
     * @throws TerminalException if a row cannot be encoded — in practice, invalid UTF-8 in a value.
     */
    public function in(Language $language): string
    {
        $rows = [];

        foreach ($this->fields as $field) {
            $rows[] = $field->row($language);
        }

        try {
            return json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $cause) {
            // The tag through the enum rather than written out. A quoted angle bracket followed by
            // a tag name, anywhere under src/, fails the verify script's "nothing builds markup
            // from a string" check — rightly, since it cannot tell an error message from a heredoc.
            throw new TerminalException(
                'A terminal row could not be encoded for ' . Tag::TerminalWindow->value
                . ': ' . $cause->getMessage(),
                previous: $cause,
            );
        }
    }
}
