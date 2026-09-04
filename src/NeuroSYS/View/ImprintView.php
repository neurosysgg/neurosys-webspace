<?php

declare(strict_types=1);

namespace NeuroSYS\View;

use NeuroSYS\Config;
use NeuroSYS\View\Html\CssClass;
use NeuroSYS\View\Html\Element;
use NeuroSYS\View\Html\Fragment;
use NeuroSYS\View\Html\HtmlAttribute;
use NeuroSYS\View\Html\HtmlTag;
use NeuroSYS\View\Html\Node;

/**
 * The ImprintView class. Renders the legally required imprint, in German and English.
 *
 * The two halves say the same thing under § 5 DDG and § 18 Abs. 2 MStV, so the address and the
 * contact line are built once each and used from both — a legal document with two copies of an
 * address is a legal document with one wrong address, eventually.
 */
class ImprintView extends View
{
    /** @var list<string> The postal address, one line per element. */
    private const array ADDRESS = [
        'Niclas Ahl',
        'c/o Adressgeber #2109',
        'An der alten Ziegelei 38',
        '48157 Münster',
        'Germany',
    ];

    public function pageTitle(): string { return self::title('Imprint'); }

    public function content(): Node
    {
        return new Element(HtmlTag::Section)
            ->attr(HtmlAttribute::ClassName, CssClass::PageSection)
            ->containing(
                self::heading(HtmlTag::H1, 'Impressum'),
                self::heading(HtmlTag::H2, 'Angaben gemäß § 5 DDG'),
                self::address(),
                self::heading(HtmlTag::H2, 'Kontakt'),
                self::contact('E-Mail: '),
                self::heading(HtmlTag::H2, 'Verantwortlicher im Sinne des § 18 Abs. 2 MStV'),
                self::address(),
                self::heading(HtmlTag::H1, 'Imprint'),
                self::heading(HtmlTag::H2, 'Information pursuant to § 5 DDG'),
                self::address(),
                self::heading(HtmlTag::H2, 'Contact'),
                self::contact('E-Mail: '),
                self::heading(HtmlTag::H2, 'Responsible for content pursuant to § 18 Abs. 2 MStV'),
                self::address(),
            );
    }

    private static function heading(HtmlTag $level, string $text): Element
    {
        return new Element($level)->containing($text);
    }

    /** The postal address as one paragraph, its lines separated by `<br>`. */
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

    private static function contact(string $label): Element
    {
        return new Element(HtmlTag::P)->containing(
            $label,
            new Element(HtmlTag::A)
                ->attr(HtmlAttribute::Href, 'mailto:' . Config::EMAIL)
                ->containing(Config::EMAIL),
        );
    }
}
