<?php

declare(strict_types=1);

namespace NeuroSYS\View\Terminal;

use NeuroSYS\View\Html\AttributeName;

/**
 * The TerminalAttribute enum. What {@link Terminal} tells `<terminal-window>`.
 *
 * Split by who reads them, which is worth knowing before renaming one: `command` and `fields` are
 * read by the element, `label` and `narrow` by the stylesheet — `attr(label)` draws the title bar
 * and `[narrow]` constrains the width. A rename therefore breaks either TypeScript or CSS, and only
 * one of those has a parity test, so both sides are mirrored in
 * `assets/ts/model/TerminalAttribute.ts` and the stylesheet is the remaining manual link.
 */
enum TerminalAttribute: string implements AttributeName
{
    /** The window's title, drawn in its bar by CSS. */
    case Label = 'label';

    /** The command line above the output. */
    case Command = 'command';

    /** The output rows, as JSON — see {@link TerminalField::toArray()}. */
    case Fields = 'fields';

    /** Constrains the window's width. A boolean attribute. */
    case Narrow = 'narrow';

    public function attribute(): string
    {
        return $this->value;
    }
}
