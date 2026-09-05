<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Php;

/**
 * The Expression interface. One piece of the PHP source {@link \NeuroSYS\Tool\Release\EntryWriter}
 * emits.
 *
 * This exists for the reason the markup tree exists, and the objection is the same one: nothing
 * should build PHP by concatenating it, any more than `View/` builds HTML by concatenating it. An
 * entry used to be a heredoc with `%s` holes and a `sprintf` per fragment, which meant a class name
 * was a string, an enum case was `'MusicalKey::' . $key->name`, and the only thing standing between
 * a typo and a data file that will not parse was reading it carefully.
 *
 * Now the emitter composes values and one renderer writes the syntax — so `Genre::Dubstep` comes out
 * of a real `Genre`, and a name that does not exist cannot be written down.
 */
interface Expression
{
    /**
     * The expression as PHP source.
     *
     * The **first line carries no indent** — the caller has already placed it, at a column this
     * cannot know. Every line after it is prefixed with `$indent`, which is why nesting works by
     * handing children `$indent . self::STEP`.
     *
     * @param string $indent The column this expression starts at.
     * @return string
     */
    public function render(string $indent = ''): string;
}
