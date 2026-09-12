<?php

declare(strict_types=1);

namespace NeuroSYS\View;

use NeuroSYS\Site;
use NeuroSYS\Support\UrlScheme;
use NeuroSYS\Text\Language;
use NeuroSYS\Text\Translatable;
use NeuroSYS\View\Html\CssClass;
use NeuroSYS\View\Html\Element;
use NeuroSYS\View\Html\HtmlAttribute;
use NeuroSYS\View\Html\HtmlTag;
use NeuroSYS\View\Html\Node;

/**
 * The ImprintView class. Renders the legally required imprint, in German and English.
 *
 * The two halves say the same thing under § 5 DDG and § 18 Abs. 2 MStV, so the address and the
 * contact line are built once each and used from both — a legal document with two copies of an
 * address is a legal document with one wrong address, eventually.
 *
 * **Both halves are always rendered; only their order changes**, by the language the request is
 * answered in — the visitor's `lang` cookie, else their `Accept-Language`; see
 * {@link \NeuroSYS\Http\Request::language()}. The German half is the one
 * that discharges the obligation, so it is never the half left out; what the language decides is
 * only which one a visitor reads first. Each carries its own `lang`, so a screen reader changes
 * voice at the boundary rather than reading German aloud in English.
 */
class ImprintView extends View
{
    /**
     * The contact line's label, the same in both halves — `E-Mail:` is what a German imprint says,
     * and close enough to the English that the two share it rather than pretend to differ.
     *
     * A constant rather than a literal at the call, because a word a view writes as a literal is
     * what `TranslationTest` refuses — and this one belongs in neither catalog: the legal pages are
     * written in each language, not translated.
     */
    private const string CONTACT = 'E-Mail: ';

    /** @var list<string> The postal address, one line per element. */
    private const array ADDRESS = [
        'Niclas Ahl',
        'c/o Adressgeber #2109',
        'An der alten Ziegelei 38',
        '48157 Münster',
        'Germany',
    ];

    /**
     * Constructs an instance of {@link self}.
     *
     * @param Language $language Which half to put first.
     */
    public function __construct(private readonly Language $language = Language::English) {}

    /**
     * The title in whichever language leads.
     *
     * Both spellings are already headings of the document below, so nothing here is translated that
     * was not translated before.
     *
     * @return Translatable
     */
    public function pageTitle(): Translatable
    {
        return self::title(match ($this->language) {
            Language::German  => 'Impressum',
            Language::English => 'Imprint',
        });
    }

    /**
     * @return Node
     */
    public function content(): Node
    {
        // A match rather than a sort: there are two halves, both are always rendered, and the only
        // question is which leads. It is also what makes a third Language case a loud failure here
        // rather than a page quietly missing a third of itself.
        $halves = match ($this->language) {
            Language::German  => [self::german(), self::english()],
            Language::English => [self::english(), self::german()],
        };

        return new Element(HtmlTag::Section)
            ->attr(HtmlAttribute::ClassName, CssClass::PageSection)
            ->containing(...$halves);
    }

    /**
     * The half that discharges the obligation.
     *
     * @return Element
     */
    private static function german(): Element
    {
        return self::half(
            Language::German,
            self::heading(HtmlTag::H1, 'Impressum'),
            self::heading(HtmlTag::H2, 'Angaben gemäß § 5 DDG'),
            self::address(),
            self::heading(HtmlTag::H2, 'Kontakt'),
            self::contact(),
            self::heading(HtmlTag::H2, 'Verantwortlicher im Sinne des § 18 Abs. 2 MStV'),
            self::address(),
        );
    }

    /**
     * The half that is a courtesy. Note the two § references stay German — they name German law,
     * and a translated statute reference points at nothing.
     *
     * @return Element
     */
    private static function english(): Element
    {
        return self::half(
            Language::English,
            self::heading(HtmlTag::H1, 'Imprint'),
            self::heading(HtmlTag::H2, 'Information pursuant to § 5 DDG'),
            self::address(),
            self::heading(HtmlTag::H2, 'Contact'),
            self::contact(),
            self::heading(HtmlTag::H2, 'Responsible for content pursuant to § 18 Abs. 2 MStV'),
            self::address(),
        );
    }

    /**
     * One language's imprint, in a section that says which language it is.
     *
     * @param Language $language
     * @param Node ...$content
     * @return Element
     */
    private static function half(Language $language, Node ...$content): Element
    {
        return new Element(HtmlTag::Section)
            ->attr(HtmlAttribute::Lang, $language)
            ->containing(...$content);
    }

    /**
     * @param HtmlTag $level
     * @param string $text
     * @return Element
     */
    private static function heading(HtmlTag $level, string $text): Element
    {
        return new Element($level)->containing($text);
    }

    /**
     * The postal address as one paragraph, its lines separated by `<br>`.
     *
     * @return Element
     */
    private static function address(): Element
    {
        $lines = [];

        foreach (self::ADDRESS as $index => $line) {
            if ($index > 0) {
                $lines[] = new Element(HtmlTag::Br);
            }

            $lines[] = $line;
        }

        return new Element(HtmlTag::P)->containing(...$lines);
    }

    /**
     * The contact line, which is the same in both languages — see {@link self::CONTACT}.
     *
     * @return Element
     */
    private static function contact(): Element
    {
        return new Element(HtmlTag::P)->containing(
            self::CONTACT,
            new Element(HtmlTag::A)
                ->attr(HtmlAttribute::Href, UrlScheme::Mailto->url(Site::EMAIL))
                ->containing(Site::EMAIL),
        );
    }
}
