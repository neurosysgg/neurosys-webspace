<?php

declare(strict_types=1);

namespace NeuroSYS\View;

use NeuroSYS\Support\Collection;
use NeuroSYS\View\Html\CssClass;
use NeuroSYS\View\Html\Element;
use NeuroSYS\View\Html\HtmlAttribute;
use NeuroSYS\View\Html\HtmlTag;
use NeuroSYS\View\Html\Node;
use NeuroSYS\View\Terminal\Terminal;
use NeuroSYS\View\Terminal\TerminalCommand;
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

    /**
     * @return string
     */
    public function pageTitle(): string { return self::title('404'); }

    /**
     * @return Node
     */
    public function content(): Node
    {
        return new Element(HtmlTag::Section)
            ->attr(HtmlAttribute::ClassName, CssClass::PageSection)
            ->containing(
                new Terminal(
                    label:   'error.log',
                    command: new TerminalCommand('find', $this->path),
                    fields:  new Collection(TerminalField::class)
                        ->with(new TerminalField('error', '404 — not found', TerminalTone::Error)),
                    narrow:  true,
                )->toElement(),
                new Element(HtmlTag::P)
                    ->attr(HtmlAttribute::ClassName, CssClass::BackHome)
                    ->containing(new Element(HtmlTag::A)->attr(HtmlAttribute::Href, '/')->containing('← home')),
            );
    }
}
