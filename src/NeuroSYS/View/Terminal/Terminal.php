<?php

declare(strict_types=1);

namespace NeuroSYS\View\Terminal;

use JsonException;
use NeuroSYS\Exception\MarkupException;
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
        // is the one thing a PHP generic cannot say. Same guard as Release::verify().
        if ($this->fields->type !== TerminalField::class) {
            throw new ReleaseVerificationException(
                'Terminal::fields must be a Collection of \TerminalField.'
            );
        }
    }

    /**
     * Returns the element that builds this terminal.
     *
     * `JSON_THROW_ON_ERROR` is what makes a row that cannot be serialised loud rather than a silent
     * `false`, and the {@link JsonException} it throws is caught here rather than propagated — not
     * to swallow it, but to translate it. A terminal whose rows will not encode is a page that
     * cannot be built, which is what {@link MarkupException} already means, and it is what every
     * other failure in this layer throws. Propagating the core exception instead would make every
     * view that declares a terminal owe an `@throws` for a condition none of them can act on.
     *
     * @throws MarkupException if a row cannot be encoded — in practice, invalid UTF-8 in a value.
     */
    public function toElement(): Element
    {
        try {
            $fields = json_encode(
                array_map(static fn (TerminalField $f): array => $f->toArray(), $this->fields->all()),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $exception) {
            // The tag through the enum rather than written out. A quoted angle bracket followed by
            // a tag name, anywhere under src/, fails the verify script's "nothing builds markup
            // from a string" check — rightly, since it cannot tell an error message from a heredoc
            // and should not have to. Naming it through Tag is the better answer anyway.
            throw new MarkupException(
                'A terminal row could not be encoded for ' . Tag::TerminalWindow->value
                . ': ' . $exception->getMessage(),
                previous: $exception,
            );
        }

        return new Element(Tag::TerminalWindow)
            ->attr(TerminalAttribute::Label, $this->label)
            ->attr(TerminalAttribute::Command, $this->command->render())
            ->attr(TerminalAttribute::Fields, $fields)
            ->attr(TerminalAttribute::Narrow, $this->narrow);
    }
}
