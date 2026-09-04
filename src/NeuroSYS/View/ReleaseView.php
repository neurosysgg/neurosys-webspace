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
              <terminal-window>
                <div class="terminal-bar">
                  <span class="dot"></span><span class="dot"></span><span class="dot"></span>
                  <span class="terminal-title">release.log</span>
                </div>
                <div class="terminal-body">
                  <p><span class="prompt">\$</span> ./release --track "$title"</p>
                  <p class="out"><span class="key">artist</span>neuro.SYS</p>
                  <p class="out"><span class="key">bpm</span>$bpm</p>
                  <p class="out"><span class="key">key</span>$key</p>
                  <p class="out"><span class="key">genre</span>$genre</p>
                  <p class="out"><span class="key">status</span><span class="ok">ready</span></p>
                  <p><span class="prompt">\$</span> <span class="cursor">_</span></p>
                </div>
              </terminal-window>

              <cover-art fallback="$placeholder">
                <img src="$coverSrc" alt="$alt" />
              </cover-art>
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
              <div class="downloads">
                <h2>downloads</h2>
                $dlCards
              </div>
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
     * is requested from the provider until then. The provider is named from the embed rather
     * than hardcoded, so a non-SoundCloud embed needs no change here.
     */
    private function playerHtml(): string
    {
        $embed = $this->release->embed;

        if ($embed === null) {
            return '';
        }

        $markup   = htmlspecialchars($embed->toHtml($this->release->title));
        $provider = htmlspecialchars($embed->platform()->displayName());
        $height   = $embed->height();

        return <<<HTML
            <div class="player">
              <player-consent height="$height" embed="$markup">
                <p class="player-consent-label">$provider player</p>
                <button class="btn-primary">Load player</button>
                <p class="player-consent-hint">Third-party content — clicking connects you to $provider&rsquo;s servers.</p>
              </player-consent>
            </div>
            HTML;
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
                      <a class="dl-card" data-no-spa href="/releases/$slug/$type">
                        <span class="dl-label">$label</span>
                        <span class="dl-meta">$meta</span>
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
