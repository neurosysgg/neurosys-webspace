<?php

declare(strict_types=1);

namespace NeuroSYS\View;

use NeuroSYS\Service\DownloadStats;
use NeuroSYS\Support\SearchableCollection;
use NeuroSYS\Text\Texts;
use NeuroSYS\Text\Translatable;
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
     *                                  flag beside it saying which.
     */
    public function __construct(private readonly ?DownloadStats $stats = null) {}

    /**
     * @return Translatable
     */
    public function pageTitle(): Translatable { return self::title(Texts::Stats::Title); }

    /**
     * @return Node
     */
    public function content(): Node
    {
        // Distinguish "switched off" from "on, but nothing yet" — otherwise an empty page reads as
        // a bug. Logging is off for legal reasons; see DownloadLogger and CLAUDE.md.
        if ($this->stats === null) {
            return self::notice(Texts::Stats::LoggingOff);
        }

        if ($this->stats->isEmpty()) {
            return self::notice(Texts::Stats::NothingYet);
        }

        return new Element(HtmlTag::Section)
            ->attr(HtmlAttribute::ClassName, CssClass::PageSection)
            ->containing(
                new Element(HtmlTag::H2)
                    ->attr(HtmlAttribute::ClassName, CssClass::PageHeading)
                    ->containing(Texts::Stats::Title),
                new Element(HtmlTag::P)
                    ->attr(HtmlAttribute::ClassName, CssClass::Muted)
                    ->containing(
                        Texts::Stats::Total,
                        new Element(HtmlTag::Strong)->containing((string) $this->stats->total),
                    ),
                self::subheading(Texts::Stats::ByFormat),
                self::table($this->stats->byFormat),
                self::subheading(Texts::Stats::ByDay),
                self::table($this->stats->byDay),
            );
    }

    /**
     * A page that is only a sentence: switched off, or on with nothing to show.
     *
     * @param Translatable $text
     * @return Element
     */
    private static function notice(Translatable $text): Element
    {
        return new Element(HtmlTag::Section)
            ->attr(HtmlAttribute::ClassName, CssClass::PageSection)
            ->containing(
                new Element(HtmlTag::P)->attr(HtmlAttribute::ClassName, CssClass::Muted)->containing($text),
            );
    }

    /**
     * @param Translatable $text
     * @return Element
     */
    private static function subheading(Translatable $text): Element
    {
        return new Element(HtmlTag::H3)
            ->attr(HtmlAttribute::ClassName, CssClass::StatsSub)
            ->containing($text);
    }

    /**
     * @param SearchableCollection<int> $rows Counts keyed by whatever the table is grouped by.
     * @return Element
     */
    private static function table(SearchableCollection $rows): Element
    {
        return new Element(HtmlTag::Table)
            ->attr(HtmlAttribute::ClassName, CssClass::StatsTable)
            ->containing(...$rows->map(
                static fn(int $count, string $key): Element => new Element(HtmlTag::Tr)->containing(
                    new Element(HtmlTag::Td)->containing($key),
                    new Element(HtmlTag::Td)
                        ->attr(HtmlAttribute::ClassName, CssClass::StatsCount)
                        ->containing((string) $count),
                ),
            )->toValues());
    }
}
