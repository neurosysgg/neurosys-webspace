<?php

declare(strict_types=1);

namespace NeuroSYS\View;

use NeuroSYS\Model\Release;
use NeuroSYS\Model\ReleaseFormat;
use NeuroSYS\Support\Collection;
use NeuroSYS\View\Html\CardAttribute;
use NeuroSYS\View\Html\CoverArtAttribute;
use NeuroSYS\View\Html\Element;
use NeuroSYS\View\Html\LinkAttribute;
use NeuroSYS\View\Html\Tag;
use NeuroSYS\View\Terminal\Terminal;
use NeuroSYS\View\Terminal\TerminalField;
use NeuroSYS\View\Terminal\TerminalTone;

/**
 * The ReleaseView class. Renders the detail page for a single release.
 */
class ReleaseView extends View
{
    /** Shown when a release has no cover link, and as the onerror fallback for one that fails. */
    private const string COVER_PLACEHOLDER = '/assets/img/cover-placeholder.svg';

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
        return $this->release->title . ' — neuro.SYS';
    }

    public function content(): string
    {
        return $this->heroSection() . $this->infoSection();
    }

    /** Builds the hero section with terminal metadata and cover art. */
    private function heroSection(): string
    {
        $release  = $this->release;
        $terminal = new Terminal(
            label:   'release.log',
            command: './release --track "' . $release->title . '"',
            fields:  new Collection(TerminalField::class)->with(
                new TerminalField('artist', 'neuro.SYS'),
                new TerminalField('bpm', (string) $release->bpm),
                new TerminalField('key', $release->key->value),
                new TerminalField('genre', $release->genre->value),
                new TerminalField('status', 'ready', TerminalTone::Ok),
            ),
        )->toElement();

        $cover = new Element(Tag::CoverArt)
            ->with(CoverArtAttribute::Src, $release->cover?->url() ?? self::COVER_PLACEHOLDER)
            ->with(CoverArtAttribute::Fallback, self::COVER_PLACEHOLDER)
            ->with(CoverArtAttribute::Alt, $release->title . ' cover art')
            ->render();

        return <<<HTML
            <section class="hero">
              $terminal
              $cover
            </section>
            HTML;
    }

    /** Builds the release info section with player and download cards. */
    private function infoSection(): string
    {
        $title    = $this->titleHtml();
        $desc     = htmlspecialchars($this->release->description);
        $player   = $this->playerHtml();
        $dlCards  = $this->downloadCards();

        return <<<HTML

            <section class="release-info">
              <h1>$title</h1>
              <p class="tagline">neuro.SYS &mdash; $desc</p>
              $player
              <download-list>
                <h2>downloads</h2>
                $dlCards
              </download-list>
            </section>
            HTML;
    }

    /**
     * Renders the release title, splitting a trailing punctuation mark off so it can
     * carry the accent colour — 'hello world!' and 'ill.' both read as name + mark.
     */
    private function titleHtml(): string
    {
        $title = $this->release->title;
        $mark  = '';

        if (preg_match('/[!.?]$/', $title, $matches)) {
            $mark  = $matches[0];
            $title = substr($title, 0, -1);
        }

        $title = htmlspecialchars($title);

        return $mark === ''
            ? $title
            : $title . '<span class="bang">' . htmlspecialchars($mark) . '</span>';
    }

    /**
     * Builds the click-to-load consent placeholder for the release's embed.
     *
     * Nothing reaches the page but the tag and its attributes: <soundcloud-player> builds the gate,
     * and only builds the iframe once the visitor clicks it, so nothing is requested from the
     * provider before then. The provider comes from the embed rather than being hardcoded, so a
     * non-SoundCloud embed needs no change here.
     */
    private function playerHtml(): string
    {
        $embed = $this->release->embed;

        if ($embed === null) {
            return '';
        }

        return $embed->toElement($this->release->title);
    }

    /**
     * Builds the download card links for all formats on this release.
     *
     * The `<a>` inside stays server-rendered and stays native: downloads have to work without JS,
     * and `data-no-spa` has to land on a real link or the SPA router fetches the 303 and swallows
     * it. That is why the card wraps the anchor rather than replacing it.
     */
    private function downloadCards(): string
    {
        $cards = '';
        $slug  = htmlspecialchars($this->slug);
        $noSpa = LinkAttribute::NoSpa->attribute();

        foreach ($this->release->formats->all() as $format) {
            $type  = $format->type->value;
            $label = htmlspecialchars($format->type->label());
            $meta  = htmlspecialchars($this->formatMeta($format->type));

            $cards .= new Element(Tag::DownloadCard)
                ->with(CardAttribute::Format, $type)
                ->containing(<<<HTML

                          <a $noSpa href="/releases/$slug/$type">
                            <download-label>$label</download-label>
                            <download-meta>$meta</download-meta>
                          </a>

                        HTML)
                ->render() . "\n";
        }

        return self::indent(rtrim($cards), 4);
    }

    /**
     * Returns the human-readable metadata string for a given format type.
     *
     * Which formats are lossless is {@link ReleaseFormat::isLossless()}'s to know — listing
     * them again here would be a second copy of that fact, free to drift from the first.
     */
    private function formatMeta(ReleaseFormat $format): string
    {
        return match ($format) {
            ReleaseFormat::STEMS => 'non-commercial — commercial licensing: neuro.sys@neurosys.gg',
            ReleaseFormat::MP3   => '320 kbps',
            ReleaseFormat::OGG   => 'OGG Vorbis',
            default              => $format->isLossless() ? 'lossless, 24-bit/48kHz' : 'lossy',
        };
    }
}
