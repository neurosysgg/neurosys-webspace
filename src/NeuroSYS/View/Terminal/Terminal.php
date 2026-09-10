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
     * @param TerminalCommand           $command The command line above the output. An object rather
     *                                           than a string because the two views that build one
     *                                           interpolate a release title and a request path into
     *                                           it, and neither could quote what it interpolated —
     *                                           see {@link TerminalCommand}.
     * @param Collection<TerminalField> $fields  The output rows, in order.
     * @param bool                      $narrow  Constrain the window's width.
     *
     * @throws ReleaseVerificationException if the collection holds something else.
     */
    public function __construct(
        public string          $label,
        public TerminalCommand $command,
        public Collection      $fields = new Collection(TerminalField::class),
        public bool            $narrow = false,
    ) {
        // Collection::with() rejects the wrong item; only its element type is left to check, which
        // is the one thing a PHP generic cannot say. Same guard as Release::verify(), which is
        // also where the reason it asks is_a() rather than !== is written down.
        if (!is_a($this->fields->type, TerminalField::class, true)) {
            throw new ReleaseVerificationException(
                'Terminal::fields must be a Collection of \TerminalField.'
            );
        }
    }

    /**
     * Returns the element that builds this terminal.
     *
     * The rows go to the attribute unencoded, as {@link TerminalFields}: their captions are
     * translated, so the JSON can only be written at render, in the language the element renders
     * in. A row that will not encode is a {@link \NeuroSYS\Exception\TerminalException} then,
     * rather than here.
     *
     * @return Element
     */
    public function toElement(): Element
    {
        return new Element(Tag::TerminalWindow)
            ->attr(TerminalAttribute::Label, $this->label)
            ->attr(TerminalAttribute::Command, $this->command->render())
            ->attr(TerminalAttribute::Fields, new TerminalFields($this->fields))
            ->attr(TerminalAttribute::Narrow, $this->narrow);
    }
}
