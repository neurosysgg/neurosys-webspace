<?php

declare(strict_types=1);

namespace NeuroSYS\View;

use NeuroSYS\Support\Collection;
use NeuroSYS\View\Html\Element;
use NeuroSYS\View\Html\HtmlAttribute;
use NeuroSYS\View\Html\HtmlTag;
use NeuroSYS\View\Html\Node;
use NeuroSYS\View\Terminal\Terminal;
use NeuroSYS\View\Terminal\TerminalField;
use NeuroSYS\View\Terminal\TerminalTone;

/**
 * The NotFoundView class. Renders the 404 error page.
 */
class NotFoundView extends View
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $path The request path that was not found, shown in the terminal block.
     */
    public function __construct(private readonly string $path) {}

    public function pageTitle(): string { return '404 — neuro.SYS'; }

    public function content(): Node
    {
        return new Element(HtmlTag::Section)
            ->attr(HtmlAttribute::ClassName, 'page-section')
            ->containing(
                new Terminal(
                    label:   'error.log',
                    command: 'find ' . $this->path,
                    fields:  new Collection(TerminalField::class)
                        ->with(new TerminalField('error', '404 — not found', TerminalTone::Error)),
                    narrow:  true,
                )->toElement(),
                new Element(HtmlTag::P)
                    ->attr(HtmlAttribute::ClassName, 'back-home')
                    ->containing(new Element(HtmlTag::A)->attr(HtmlAttribute::Href, '/')->containing('← home')),
            );
    }
}
