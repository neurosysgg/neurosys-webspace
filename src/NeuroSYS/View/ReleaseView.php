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
        $title    = htmlspecialchars($this->release->title);
        $bpm      = $this->release->bpm;
        $key      = htmlspecialchars($this->release->key->value);
        $coverSrc = htmlspecialchars($this->release->coverSrc);
        $alt      = htmlspecialchars($this->release->title . ' cover art');

        return <<<HTML
            <section class="hero">
              <div class="terminal">
                <div class="terminal-bar">
                  <span class="dot"></span><span class="dot"></span><span class="dot"></span>
                  <span class="terminal-title">release.log</span>
                </div>
                <div class="terminal-body">
                  <p><span class="prompt">\$</span> ./release --track "$title"</p>
                  <p class="out"><span class="key">artist</span>neuro.SYS</p>
                  <p class="out"><span class="key">bpm</span>$bpm</p>
                  <p class="out"><span class="key">key</span>$key</p>
                  <p class="out"><span class="key">status</span><span class="ok">ready</span></p>
                  <p><span class="prompt">\$</span> <span class="cursor">_</span></p>
                </div>
              </div>

              <div class="cover-art">
                <img
                  src="$coverSrc"
                  onerror="this.src='/assets/img/cover-placeholder.svg';this.onerror=null"
                  alt="$alt"
                />
              </div>
            </section>
            HTML;
    }

    /** Builds the release info section with player and download cards. */
    private function infoSection(): string
    {
        $title    = rtrim(htmlspecialchars($this->release->title), '!');
        $desc     = htmlspecialchars($this->release->description);
        $player   = $this->playerHtml();
        $dlCards  = $this->downloadCards();

        return <<<HTML

            <section class="release-info">
              <h1>$title<span class="bang">!</span></h1>
              <p class="tagline">neuro.SYS &mdash; $desc</p>
              $player
              <div class="downloads">
                <h2>downloads</h2>
                $dlCards
              </div>
            </section>
            HTML;
    }

    /** Builds the click-to-load SoundCloud consent placeholder. */
    private function playerHtml(): string
    {
        if ($this->release->soundcloudEmbedHtml === '') return '';
        $embed = htmlspecialchars($this->release->soundcloudEmbedHtml);
        return <<<HTML
            <div class="player">
              <div class="player-consent" data-embed="$embed">
                <p class="player-consent-label">SoundCloud player</p>
                <button class="btn-primary player-consent-btn">Load player</button>
                <p class="player-consent-hint">Third-party content — clicking connects you to SoundCloud&rsquo;s servers.</p>
              </div>
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
                    <a class="dl-card" data-no-spa href="/releases/$slug/$type">
                      <span class="dl-label">$label</span>
                      <span class="dl-meta">$meta</span>
                    </a>

                HTML;
        }

        return $cards;
    }

    /** Returns the human-readable metadata string for a given format type. */
    private function formatMeta(ReleaseFormat $format): string
    {
        return match ($format) {
            ReleaseFormat::FLAC, ReleaseFormat::WAV, ReleaseFormat::AIFF => 'lossless, 24-bit/48kHz',
            ReleaseFormat::MP3   => '320 kbps',
            ReleaseFormat::OGG   => 'OGG Vorbis',
            ReleaseFormat::STEMS => 'non-commercial — commercial licensing: neuro.sys@neurosys.gg',
        };
    }
}
