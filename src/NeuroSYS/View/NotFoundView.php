<?php

declare(strict_types=1);

namespace NeuroSYS\View;

use NeuroSYS\Support\SitePath;
use NeuroSYS\Text\Texts;
use NeuroSYS\View\Html\CssClass;
use NeuroSYS\View\Terminal\Terminal;
use NeuroSYS\View\Terminal\TerminalCommand;
use NeuroSYS\View\Terminal\TerminalField;
use NeuroSYS\View\Terminal\TerminalTone;
use Phpanta\Support\Collection;
use Phpanta\Text\Translatable;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\HtmlAttribute;
use Phpanta\View\Html\HtmlTag;
use Phpanta\View\Html\Node;
use Phpanta\View\View;

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
     * @return Translatable
     */
    public function pageTitle(): Translatable { return self::title('404'); }

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
                    fields:  new Collection(TerminalField::class)->with(
                        new TerminalField(Texts::Errors::Error, Texts::Errors::NotFound, TerminalTone::Error),
                    ),
                    narrow:  true,
                )->toElement(),
                new Element(HtmlTag::P)
                    ->attr(HtmlAttribute::ClassName, CssClass::BackHome)
                    ->containing(
                        new Element(HtmlTag::A)
                            ->attr(HtmlAttribute::Href, SitePath::Home->to())
                            ->containing(Texts::Errors::Home),
                    ),
            );
    }
}
