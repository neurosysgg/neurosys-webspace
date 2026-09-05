<?php

declare(strict_types=1);

namespace NeuroSYS\View;

use NeuroSYS\Service\DownloadStats;
use NeuroSYS\View\Html\CssClass;
use NeuroSYS\View\Html\Element;
use NeuroSYS\View\Html\HtmlAttribute;
use NeuroSYS\View\Html\HtmlTag;
use NeuroSYS\View\Html\Node;

/**
 * The StatsView class. Renders the download statistics admin page.
 */
class StatsView extends View
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param DownloadStats|null $stats What the log adds up to, or **null where it was never
     *                                  read** because logging is switched off. Those are two
     *                                  different pages, and one object carrying both would need a
     *                                  flag beside it saying which — which is what this replaced.
     */
    public function __construct(private readonly ?DownloadStats $stats = null) {}

    /**
     * @return string
     */
    public function pageTitle(): string { return self::title('stats'); }

    /**
     * @return Node
     */
    public function content(): Node
    {
        // Distinguish "switched off" from "on, but nothing yet" — otherwise an empty page reads as
        // a bug. Logging is off for legal reasons; see DownloadLogger and CLAUDE.md.
        if ($this->stats === null) {
            return self::notice('Download logging is switched off — nothing is recorded.');
        }

        if ($this->stats->isEmpty()) {
            return self::notice('No downloads logged yet.');
        }

        return new Element(HtmlTag::Section)
            ->attr(HtmlAttribute::ClassName, CssClass::PageSection)
            ->containing(
                new Element(HtmlTag::H2)
                    ->attr(HtmlAttribute::ClassName, CssClass::PageHeading)
                    ->containing('stats'),
                new Element(HtmlTag::P)
                    ->attr(HtmlAttribute::ClassName, CssClass::Muted)
                    ->containing(
                        'total downloads: ',
                        new Element(HtmlTag::Strong)->containing((string) $this->stats->total),
                    ),
                self::subheading('by format'),
                self::table($this->stats->byFormat),
                self::subheading('by day'),
                self::table($this->stats->byDay),
            );
    }

    /**
     * A page that is only a sentence: switched off, or on with nothing to show.
     *
     * @param string $text
     * @return Element
     */
    private static function notice(string $text): Element
    {
        return new Element(HtmlTag::Section)
            ->attr(HtmlAttribute::ClassName, CssClass::PageSection)
            ->containing(
                new Element(HtmlTag::P)->attr(HtmlAttribute::ClassName, CssClass::Muted)->containing($text),
            );
    }

    /**
     * @param string $text
     * @return Element
     */
    private static function subheading(string $text): Element
    {
        return new Element(HtmlTag::H3)
            ->attr(HtmlAttribute::ClassName, CssClass::StatsSub)
            ->containing($text);
    }

    /**
     * @param array<string, int> $rows Counts keyed by whatever the table is grouped by.
     * @return Element
     */
    private static function table(array $rows): Element
    {
        return new Element(HtmlTag::Table)
            ->attr(HtmlAttribute::ClassName, CssClass::StatsTable)
            ->containing(...array_map(
                static fn(string $key, int $count): Element => new Element(HtmlTag::Tr)->containing(
                    new Element(HtmlTag::Td)->containing($key),
                    new Element(HtmlTag::Td)
                        ->attr(HtmlAttribute::ClassName, CssClass::StatsCount)
                        ->containing((string) $count),
                ),
                array_map(strval(...), array_keys($rows)),
                array_values($rows),
            ));
    }
}
