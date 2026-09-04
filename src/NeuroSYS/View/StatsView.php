<?php

declare(strict_types=1);

namespace NeuroSYS\View;

use NeuroSYS\View\Html\Element;
use NeuroSYS\View\Html\Fragment;
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
     * @param int                    $total    Total number of logged downloads.
     * @param array<string, int>     $byFormat Download counts keyed by "slug/format".
     * @param array<string, int>     $byDay    Download counts keyed by date (YYYY-MM-DD).
     * @param bool                   $loggingEnabled Whether download logging is switched on at all.
     */
    public function __construct(
        private readonly int   $total,
        private readonly array $byFormat,
        private readonly array $byDay,
        private readonly bool  $loggingEnabled = false,
    ) {}

    public function pageTitle(): string { return 'stats — neuro.SYS'; }

    public function content(): Node
    {
        // Distinguish "switched off" from "on, but nothing yet" — otherwise an empty page reads as
        // a bug. Logging is off for legal reasons; see DownloadLogger and CLAUDE.md.
        if (!$this->loggingEnabled) {
            return self::notice('Download logging is switched off — nothing is recorded.');
        }

        if ($this->total === 0) {
            return self::notice('No downloads logged yet.');
        }

        $days = $this->byDay;
        ksort($days);

        return new Element(HtmlTag::Section)
            ->attr(HtmlAttribute::ClassName, 'page-section')
            ->containing(
                new Element(HtmlTag::H2)
                    ->attr(HtmlAttribute::ClassName, 'page-heading')
                    ->containing('stats'),
                new Element(HtmlTag::P)
                    ->attr(HtmlAttribute::ClassName, 'muted')
                    ->containing(
                        'total downloads: ',
                        new Element(HtmlTag::Strong)->containing((string) $this->total),
                    ),
                self::subheading('by format'),
                self::table($this->byFormat),
                self::subheading('by day'),
                self::table($days),
            );
    }

    /** A page that is only a sentence: switched off, or on with nothing to show. */
    private static function notice(string $text): Element
    {
        return new Element(HtmlTag::Section)
            ->attr(HtmlAttribute::ClassName, 'page-section')
            ->containing(
                new Element(HtmlTag::P)->attr(HtmlAttribute::ClassName, 'muted')->containing($text),
            );
    }

    private static function subheading(string $text): Element
    {
        return new Element(HtmlTag::H3)
            ->attr(HtmlAttribute::ClassName, 'stats-sub')
            ->containing($text);
    }

    /** @param array<string, int> $rows Counts keyed by whatever the table is grouped by. */
    private static function table(array $rows): Element
    {
        return new Element(HtmlTag::Table)
            ->attr(HtmlAttribute::ClassName, 'stats-table')
            ->containing(...array_map(
                static fn(string $key, int $count): Element => new Element(HtmlTag::Tr)->containing(
                    new Element(HtmlTag::Td)->containing($key),
                    new Element(HtmlTag::Td)
                        ->attr(HtmlAttribute::ClassName, 'stats-count')
                        ->containing((string) $count),
                ),
                array_map(strval(...), array_keys($rows)),
                array_values($rows),
            ));
    }
}
