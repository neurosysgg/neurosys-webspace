<?php

declare(strict_types=1);

namespace NeuroSYS\View;

use NeuroSYS\Config;
use NeuroSYS\Model\Format;
use NeuroSYS\Model\Release;
use NeuroSYS\Model\ReleaseFormat;
use NeuroSYS\Support\Collection;
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

    public function pageTitle(): string
    {
        return self::title($this->release->title);
    }

    public function content(): Node
    {
        return new Fragment($this->heroSection(), $this->infoSection());
    }

    /** Builds the hero section with terminal metadata and cover art. */
    private function heroSection(): Element
    {
        $release = $this->release;

        $terminal = new Terminal(
            label:   'release.log',
            command: new TerminalCommand('./release', '--track', $release->title),
            fields:  new Collection(TerminalField::class)->with(
                new TerminalField('artist', Config::NAME),
                new TerminalField('bpm', (string) $release->bpm),
                new TerminalField('key', $release->key->value),
                new TerminalField('genre', $release->genre->value),
                new TerminalField('status', 'ready', TerminalTone::Ok),
            ),
        );

        $cover = new Element(Tag::CoverArt)
            ->attr(CoverArtAttribute::Src, $release->cover?->url() ?? Config::COVER_PLACEHOLDER)
            ->attr(CoverArtAttribute::Fallback, Config::COVER_PLACEHOLDER)
            ->attr(CoverArtAttribute::Alt, $release->title . ' cover art');

        return new Element(HtmlTag::Section)
            ->attr(HtmlAttribute::ClassName, CssClass::Hero)
            ->containing($terminal->toElement(), $cover);
    }

    /** Builds the release info section with player and download cards. */
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

        return $section->containing($this->downloads());
    }

    /** Builds the download group: a heading and one card per format. */
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
