<?php

declare(strict_types=1);

namespace NeuroSYS\View\Terminal;

use NeuroSYS\Exception\ReleaseVerificationException;
use NeuroSYS\Support\Collection;
use NeuroSYS\View\Html\Element;
use NeuroSYS\View\Html\Tag;

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
     * @param string                    $label   The window's title, shown in its bar.
     * @param string                    $command The command line above the output.
     * @param Collection<TerminalField> $fields  The output rows, in order.
     * @param bool                      $narrow  Constrain the window's width.
     *
     * @throws ReleaseVerificationException if the collection holds something else.
     */
    public function __construct(
        public string     $label,
        public string     $command,
        public Collection $fields = new Collection(TerminalField::class),
        public bool       $narrow = false,
    ) {
        // Collection::with() rejects the wrong item; only its element type is left to check, which
        // is the one thing a PHP generic cannot say. Same guard as Release::verify().
        if ($this->fields->type !== TerminalField::class) {
            throw new ReleaseVerificationException(
                'Terminal::fields must be a Collection of \TerminalField.'
            );
        }
    }

    /** Renders the element that builds this terminal. */
    public function toElement(): string
    {
        $fields = json_encode(
            array_map(static fn (TerminalField $f): array => $f->toArray(), $this->fields->all()),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return new Element(Tag::TerminalWindow)
            ->with(TerminalAttribute::Label, $this->label)
            ->with(TerminalAttribute::Command, $this->command)
            ->with(TerminalAttribute::Fields, $fields)
            ->withFlag(TerminalAttribute::Narrow, $this->narrow)
            ->render();
    }
}
