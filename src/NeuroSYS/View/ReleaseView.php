<?php

declare(strict_types=1);

namespace NeuroSYS\View;

use NeuroSYS\Config;
use NeuroSYS\Model\Format;
use NeuroSYS\Model\Release;
use NeuroSYS\Model\Production\Section;
use NeuroSYS\Model\ReleaseFormat;
use NeuroSYS\Support\Collection;
use NeuroSYS\View\Html\ArrangementAttribute;
use NeuroSYS\View\Html\CardAttribute;
use NeuroSYS\View\Html\CoverArtAttribute;
use NeuroSYS\View\Html\CssClass;
use NeuroSYS\View\Html\Element;
use NeuroSYS\View\Html\Fragment;
use NeuroSYS\View\Html\HtmlAttribute;
use NeuroSYS\View\Html\HtmlTag;
use NeuroSYS\View\Html\LinkAttribute;
use NeuroSYS\View\Html\Node;
use NeuroSYS\View\Html\Tag;
use NeuroSYS\View\Terminal\Terminal;
use NeuroSYS\View\Terminal\TerminalCommand;
use NeuroSYS\View\Terminal\TerminalField;
use NeuroSYS\View\Terminal\TerminalTone;

/**
 * The ReleaseView class. Renders the detail page for a single release.
 */
class ReleaseView extends View
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Release $release The release to display.
     * @param string  $slug    The URL slug identifying the release.
     */
    public function __construct(
        private readonly Release $release,
        private readonly string  $slug,
    ) {}

    /**
     * @return string
     */
    public function pageTitle(): string
    {
        return self::title($this->release->title);
    }

    /**
     * @return Node
     */
    public function content(): Node
    {
        return new Fragment($this->heroSection(), $this->infoSection());
    }

    /**
     * Builds the hero section with terminal metadata and cover art.
     *
     * @return Element
     */
    private function heroSection(): Element
    {
        $release = $this->release;

        $terminal = new Terminal(
            label:   'release.log',
            command: new TerminalCommand('./release', '--track', $release->title),
            fields:  new Collection(TerminalField::class)->with(...$this->terminalFields()),
        );

        $cover = new Element(Tag::CoverArt)
            ->attr(CoverArtAttribute::Src, $release->cover?->url() ?? Config::COVER_PLACEHOLDER)
            ->attr(CoverArtAttribute::Fallback, Config::COVER_PLACEHOLDER)
            ->attr(CoverArtAttribute::Alt, $release->title . ' cover art');

        return new Element(HtmlTag::Section)
            ->attr(HtmlAttribute::ClassName, CssClass::Hero)
            ->containing($terminal->toElement(), $cover);
    }

    /**
     * Builds the release info section with player and download cards.
     *
     * @return Element
     */
    private function infoSection(): Element
    {
        $section = new Element(HtmlTag::Section)
            ->attr(HtmlAttribute::ClassName, CssClass::ReleaseInfo)
            ->containing(
                new Element(HtmlTag::H1)->containing(...self::accented($this->release->title)),
                new Element(HtmlTag::P)
                    ->attr(HtmlAttribute::ClassName, CssClass::Tagline)
                    ->containing(Config::NAME . ' — ' . $this->release->description),
            );

        // A release with no embed emits no player element at all, rather than an empty one: the
        // stylesheet reserves the gate's height, so an empty box would be 300px of nothing.
        $embed = $this->release->embed;

        if ($embed !== null) {
            $section = $section->containing($embed->toElement($this->release->title));
        }

        // Between the player and the downloads: it is about the track rather than about getting it.
        $arrangement = $this->release->arrangement;

        if ($arrangement !== null && !$arrangement->isEmpty()) {
            $section = $section->containing($this->arrangement());
        }

        return $section->containing($this->downloads());
    }

    /**
     * Builds the download group: a heading and one card per format.
     *
     * @return Element
     */
    /**
     * The terminal's rows, with the two the project file fills added where a release carries them.
     *
     * Built as one list rather than spread into `with()` beside the others, because `status` has to
     * stay last and PHP forbids a positional argument after an unpacked one. A release staged
     * before `tools/lib/Flp/` existed renders exactly the five rows it always did — the terminal is
     * the page's fact table, and a fact nothing knows is one it should not have a blank row for.
     *
     * @return list<TerminalField>
     */
    private function terminalFields(): array
    {
        $release   = $this->release;
        $timeSpent = $release->timeSpent;
        $madeWith  = $release->madeWith->all();

        $fields = [
            new TerminalField('artist', Config::NAME),
            new TerminalField('bpm', (string) $release->bpm),
            new TerminalField('key', $release->key->value),
            new TerminalField('genre', $release->genre->value),
        ];

        if ($timeSpent !== null) {
            $fields[] = new TerminalField('time', $timeSpent->render());
        }

        if ($madeWith !== []) {
            $fields[] = new TerminalField(
                'made with',
                implode(', ', array_map(static fn($plugin): string => $plugin->name, $madeWith)),
            );
        }

        $fields[] = new TerminalField('status', 'ready', TerminalTone::Ok);

        return $fields;
    }

    /**
     * The arrangement, as the project's own markers describe it.
     *
     * **Server-rendered, and that is a decision rather than an oversight.** Every self-building
     * element on this site costs a visitor with no JS the content inside it, and `CLAUDE.md` asks
     * for that cost to be re-read whenever another fragment moves. The release page has already
     * spent it twice, on the cover and the player; a list of section names is text, and text that
     * only appears for people running scripts is a worse trade than the one the terminal made.
     * So `<release-arrangement>` follows `<release-list>` — a name, a guard, and nothing built.
     *
     * @return Element
     */
    private function arrangement(): Element
    {
        $arrangement = $this->release->arrangement;
        $bpm         = $this->release->bpm;

        return new Element(Tag::ReleaseArrangement)->containing(
            new Element(HtmlTag::H2)->containing('arrangement'),
            ...array_map(
                fn(Section $section): Element => $this->section($section, $bpm, $arrangement->ppq),
                $arrangement->sections->all(),
            ),
        );
    }

    /**
     * One section, named and timed.
     *
     * @param Section $section
     * @param int     $bpm
     * @param int     $ppq
     * @return Element
     */
    private function section(Section $section, int $bpm, int $ppq): Element
    {
        return new Element(Tag::ArrangementSection)
            // A kind of null leaves the attribute off entirely, so the stylesheet's
            // `[kind]` rules simply do not match and the section draws plainly.
            ->attr(ArrangementAttribute::Kind, $section->kind?->value)
            ->containing(
                new Element(HtmlTag::Span)
                    ->attr(HtmlAttribute::ClassName, CssClass::SectionTime)
                    ->containing($section->timestamp($bpm, $ppq)),
                new Element(HtmlTag::Span)
                    ->attr(HtmlAttribute::ClassName, CssClass::SectionLabel)
                    ->containing($section->label),
            );
    }

    /**
     * The download cards, one per format the release offers.
     *
     * @return Element
     */
    private function downloads(): Element
    {
        return new Element(Tag::DownloadList)->containing(
            new Element(HtmlTag::H2)->containing('downloads'),
            ...array_map($this->downloadCard(...), $this->release->formats->all()),
        );
    }

    /**
     * Builds one format's card.
     *
     * The `<a>` inside stays native and server-rendered: downloads have to work without JS, and
     * `data-no-spa` has to land on a real link or the SPA router fetches the 303 and swallows it.
     * That is why the card wraps the anchor rather than replacing it.
     *
     * @param Format $format
     * @return Element
     */
    private function downloadCard(Format $format): Element
    {
        $type = $format->type;

        return new Element(Tag::DownloadCard)
            ->attr(CardAttribute::Format, $type)
            ->containing(
                new Element(HtmlTag::A)
                    ->attr(LinkAttribute::NoSpa)
                    ->attr(HtmlAttribute::Href, '/releases/' . $this->slug . '/' . $type->value)
                    ->containing(
                        new Element(Tag::DownloadLabel)->containing($type->label()),
                        new Element(Tag::DownloadMeta)->containing(self::formatMeta($type)),
                    ),
            );
    }

    /**
     * Returns the human-readable metadata string for a given format type.
     *
     * Which formats are lossless is {@link ReleaseFormat::isLossless()}'s to know — listing
     * them again here would be a second copy of that fact, free to drift from the first.
     *
     * @param ReleaseFormat $format
     * @return string
     */
    private static function formatMeta(ReleaseFormat $format): string
    {
        return match ($format) {
            ReleaseFormat::STEMS => 'non-commercial — commercial licensing: ' . Config::EMAIL,
            ReleaseFormat::MP3   => '320 kbps',
            ReleaseFormat::OGG   => 'OGG Vorbis',
            default              => $format->isLossless() ? 'lossless, 24-bit/48kHz' : 'lossy',
        };
    }
}
