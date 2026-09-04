<?php

declare(strict_types=1);

namespace NeuroSYS\View\Terminal;

use NeuroSYS\Exception\ReleaseVerificationException;

/**
 * The Terminal class. A terminal window declared as typed values.
 *
 * A view says what the terminal contains; <terminal-window> builds every node of it. Nothing of the
 * subtree — not the command line, not the rows, not the cursor — is written out here, so the whole
 * of ReleaseView::heroSection() is one tag and its attributes.
 *
 * The rows cross as JSON in an attribute. That is the only shape that stays generic: a release lists
 * five metadata rows and a 404 lists one error, and the element does not need to know which is which.
 */
final readonly class Terminal
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string              $label   The window's title, shown in its bar.
     * @param string              $command The command line above the output.
     * @param list<TerminalField> $fields  The output rows, in order.
     * @param bool                $narrow  Constrain the window's width.
     *
     * @throws ReleaseVerificationException if constructed with something that is not a field.
     */
    public function __construct(
        public string $label,
        public string $command,
        public array  $fields  = [],
        public bool   $narrow  = false,
    ) {
        foreach ($this->fields as $field) {
            if (!$field instanceof TerminalField) {
                throw new ReleaseVerificationException(sprintf(
                    'Terminal::fields must contain only %s, got %s.',
                    TerminalField::class,
                    get_debug_type($field),
                ));
            }
        }
    }

    /** Renders the element that builds this terminal. */
    public function toElement(): string
    {
        $fields = json_encode(
            array_map(static fn (TerminalField $f): array => $f->toArray(), $this->fields),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return '<terminal-window'
            . ' label="' . htmlspecialchars($this->label) . '"'
            . ' command="' . htmlspecialchars($this->command) . '"'
            . ' fields="' . htmlspecialchars($fields) . '"'
            . ($this->narrow ? ' narrow' : '')
            . '></terminal-window>';
    }
}
