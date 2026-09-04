<?php

declare(strict_types=1);

namespace NeuroSYS\View;

use NeuroSYS\Model\Release;
use NeuroSYS\Model\ReleaseFormat;

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
        $title       = htmlspecialchars($this->release->title);
        $bpm         = $this->release->bpm;
        $key         = htmlspecialchars($this->release->key->value);
        $genre       = htmlspecialchars($this->release->genre->value);
        $coverSrc    = htmlspecialchars($this->release->cover?->url() ?? self::COVER_PLACEHOLDER);
        $alt         = htmlspecialchars($this->release->title . ' cover art');
        $placeholder = self::COVER_PLACEHOLDER;

        return <<<HTML
            <section class="hero">
              <terminal-window label="release.log">
                <terminal-command>./release --track "$title"</terminal-command>
                <terminal-field><terminal-key>artist</terminal-key>neuro.SYS</terminal-field>
                <terminal-field><terminal-key>bpm</terminal-key>$bpm</terminal-field>
                <terminal-field><terminal-key>key</terminal-key>$key</terminal-field>
                <terminal-field><terminal-key>genre</terminal-key>$genre</terminal-field>
                <terminal-field><terminal-key>status</terminal-key><terminal-ok>ready</terminal-ok></terminal-field>
                <terminal-cursor></terminal-cursor>
              </terminal-window>

              <cover-art src="$coverSrc" fallback="$placeholder" alt="$alt"></cover-art>
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
     * The markup never reaches the page directly — it is escaped into the element's `embed`
     * attribute and only swapped in by <player-consent> once the visitor clicks, so nothing
     * is requested from the provider until then. The element builds the gate itself, including
     * the notice naming the provider; all this has to emit is the tag. The provider comes from
     * the embed rather than being hardcoded, so a non-SoundCloud embed needs no change here.
     */
    private function playerHtml(): string
    {
        $embed = $this->release->embed;

        if ($embed === null) {
            return '';
        }

        return $embed->toElement($this->release->title);
    }

    /** Builds the download card links for all formats on this release. */
    private function downloadCards(): string
    {
        $cards = '';
        $slug  = htmlspecialchars($this->slug);

        foreach ($this->release->formats->all() as $format) {
            $type  = $format->type->value;
            $label = htmlspecialchars($format->type->label());
            $meta  = htmlspecialchars($this->formatMeta($format->type));
            $cards .= <<<HTML
                    <download-card format="$type">
                      <a data-no-spa href="/releases/$slug/$type">
                        <download-label>$label</download-label>
                        <download-meta>$meta</download-meta>
                      </a>
                    </download-card>

                HTML;
        }

        return $cards;
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
