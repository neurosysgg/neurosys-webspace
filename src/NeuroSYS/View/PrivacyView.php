<?php

declare(strict_types=1);

namespace NeuroSYS\View;

use NeuroSYS\Exception\MarkupException;
use NeuroSYS\Http\RequestHeader;
use NeuroSYS\Support\BareArray;
use NeuroSYS\View\Html\CssClass;
use NeuroSYS\View\Html\Element;
use NeuroSYS\View\Html\HtmlAttribute;
use NeuroSYS\View\Html\HtmlTag;
use NeuroSYS\View\Html\Language;
use NeuroSYS\View\Html\MarkupParser;
use NeuroSYS\View\Html\Node;

/**
 * The PrivacyView class. Renders the privacy policy inside the page shell.
 *
 * The only view that *parses* a document rather than assembling one, and the reason
 * {@link MarkupParser} exists: the policy is hand-authored, not markup a view builds. It is read
 * from two files next to the code and nothing about a request can reach either — which is the
 * standing instruction on {@link Element::containingHtml()}, and it survived that method replacing
 * the `RawHtml` node that used to emit these two files unread.
 *
 * **Both halves are always sent; only their order changes.** The policy has been bilingual all
 * along, German first for everyone; what {@link self::$language} decides is which one a visitor
 * meets at the top of the page. Sending both matters more than it might look: the German half is
 * the one that satisfies the GDPR for a German controller, so it is never the half left out, and a
 * visitor who was given the wrong guess can still scroll to the other.
 *
 * Each half carries its own `lang`, so a screen reader changes voice at the boundary instead of
 * reading one language in the other's.
 */
class PrivacyView extends View
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string   $german   The German policy, trusted verbatim.
     * @param string   $english  The English policy, trusted verbatim.
     * @param Language $language Which half to put first.
     */
    public function __construct(
        private readonly string $german,
        private readonly string $english,
        private readonly Language $language = Language::English,
    ) {}

    /**
     * The title in whichever language leads.
     *
     * Both spellings are already words of the documents below, so nothing here is translated that
     * was not translated before.
     *
     * @return string
     */
    public function pageTitle(): string
    {
        return self::title(match ($this->language) {
            Language::German  => 'Datenschutzerklärung',
            Language::English => 'Privacy Policy',
        });
    }

    /**
     * @return Language
     */
    public function language(): Language
    {
        return $this->language;
    }

    /**
     * @return list<RequestHeader>
     */
    #[BareArray('overrides View::varyOn(); see the reason there')]
    public function varyOn(): array
    {
        return [RequestHeader::AcceptLanguage];
    }

    /**
     * @return Node
     */
    public function content(): Node
    {
        $german  = self::half(Language::German, $this->german);
        $english = self::half(Language::English, $this->english);

        // The preferred half first, the other one after it — never one without the other. A match
        // rather than a sort, for the reason ImprintView::content() gives: a third Language case
        // should fail here loudly rather than drop a half.
        $halves = match ($this->language) {
            Language::German  => [$german, $english],
            Language::English => [$english, $german],
        };

        return new Element(HtmlTag::Section)
            ->attr(HtmlAttribute::ClassName, CssClass::PageSection)
            ->containing(...$halves);
    }

    /**
     * One language's policy, in a section that says which language it is.
     *
     * @param Language $language
     * @param string   $html
     * @return Element
     * @throws MarkupException if the document names an element or an attribute outside this site's
     *                         vocabulary, or does not parse cleanly. That is a fault in
     *                         `data/privacy.*.html`, which is part of this repository.
     */
    private static function half(Language $language, string $html): Element
    {
        return new Element(HtmlTag::Section)
            ->attr(HtmlAttribute::Lang, $language)
            ->containingHtml($html);
    }
}
