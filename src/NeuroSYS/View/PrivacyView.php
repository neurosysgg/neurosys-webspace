<?php

declare(strict_types=1);

namespace NeuroSYS\View;

use NeuroSYS\View\Html\CssClass;
use NeuroSYS\View\Html\Element;
use NeuroSYS\View\Html\HtmlAttribute;
use NeuroSYS\View\Html\HtmlTag;
use NeuroSYS\View\Html\Node;
use NeuroSYS\View\Html\RawHtml;

/**
 * The PrivacyView class. Renders data/privacy.html inside the page shell.
 *
 * The only view that holds {@link RawHtml}, and the reason that class exists: the policy is a
 * hand-authored document, not markup a view assembles. It is read from a file next to the code and
 * nothing about a request can reach it — see RawHtml before adding a second call site.
 */
class PrivacyView extends View
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $html The policy document, trusted verbatim.
     */
    public function __construct(private readonly string $html) {}

    /**
     * @return string
     */
    public function pageTitle(): string { return self::title('Privacy Policy'); }

    /**
     * @return Node
     */
    public function content(): Node
    {
        return new Element(HtmlTag::Section)
            ->attr(HtmlAttribute::ClassName, CssClass::PageSection)
            ->containing(new RawHtml($this->html));
    }
}
