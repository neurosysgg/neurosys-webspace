<?php

declare(strict_types=1);

namespace NeuroSYS\View;

use NeuroSYS\Config;
use NeuroSYS\Model\Demo;
use NeuroSYS\Model\DemoTrack;
use NeuroSYS\Model\Waveform;
use NeuroSYS\Support\BareString;
use NeuroSYS\Support\Collection;
use NeuroSYS\Support\SearchableCollection;
use NeuroSYS\Support\SitePath;
use NeuroSYS\View\Html\CssClass;
use NeuroSYS\View\Html\Element;
use NeuroSYS\View\Html\Fragment;
use NeuroSYS\View\Html\HtmlAttribute;
use NeuroSYS\View\Html\HtmlTag;
use NeuroSYS\View\Html\MediaPreload;
use NeuroSYS\View\Html\Node;
use NeuroSYS\View\Html\Tag;
use NeuroSYS\View\Html\WaveformAttribute;
use NeuroSYS\View\Terminal\Terminal;
use NeuroSYS\View\Terminal\TerminalCommand;
use NeuroSYS\View\Terminal\TerminalField;
use NeuroSYS\View\Terminal\TerminalTone;

/**
 * The DemoView class. Renders one demo's page — the mixes, and the ask.
 *
 * **The player is a native `<audio>` and not a custom element**, which is the opposite of what the
 * release page does with `<soundcloud-player>` and `<cover-art>`, and it is deliberate. Those two
 * build their own markup, so with JavaScript off a release page shows an empty box where the player
 * would be — a cost CLAUDE.md sets out and accepts, because the page is still a page. A demo *is*
 * the audio: an empty box is the whole thing missing. The browser's own control also seeks, takes a
 * keyboard, and is announced by a screen reader, none of which would come free in a rewrite.
 *
 * Seeking is why {@link \NeuroSYS\Http\FileResponse} answers byte ranges. The two halves belong to
 * each other: this emits the control, and that is what makes dragging it work.
 *
 * **The waveform does not spend that.** `<demo-waveform>` prepends a canvas to the card and leaves
 * the control alone, so a visitor with no JavaScript gets the same card without a picture behind
 * it — which is also what a mix with no sidecar gets, and what every demo staged before
 * {@link Waveform} existed still gets. A decoration is allowed to be absent; a player is not.
 *
 * The page names no file. Every `src` is `/demos/<slug>/<label>`, and the label is matched against
 * what the demo declares — see {@link \NeuroSYS\Controller\DemoAudioController}.
 */
#[BareString(
    'artist',
    'a caption in the demo terminal. Its twin is the release page\'s, and captions are copy: two '
    . 'pages naming the same row is two designs agreeing, and either is free to stop.',
)]
#[BareString('status', 'a caption; see the one on artist above')]
class DemoView extends View
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Demo   $demo The demo to display.
     * @param string $slug Its slug, which is also the first half of every audio URL on the page.
     * @param SearchableCollection<Waveform> $waveforms One per mix that has one, keyed by label —
     *                                  see {@link \NeuroSYS\Service\WaveformRepository}. Empty by
     *                                  default, which is a page of cards with nothing drawn on
     *                                  them: the ordinary state of a demo staged before waveforms.
     */
    public function __construct(
        private readonly Demo   $demo,
        private readonly string $slug,
        private readonly SearchableCollection $waveforms = new SearchableCollection(Waveform::class),
    ) {}

    /**
     * @return string
     */
    public function pageTitle(): string
    {
        return self::title($this->demo->title);
    }

    /**
     * @return Node
     */
    public function content(): Node
    {
        return new Fragment($this->heroSection(), $this->infoSection());
    }

    /**
     * The terminal, on its own. A release's hero is a two-column grid because it also carries the
     * cover; a demo has no artwork yet, which is most of what makes it a demo.
     *
     * @return Element
     */
    private function heroSection(): Element
    {
        return new Element(HtmlTag::Section)
            ->attr(HtmlAttribute::ClassName, CssClass::DemoHero)
            ->containing($this->terminal()->toElement());
    }

    /**
     * The window above the page: what this is, and what state it is in.
     *
     * `demo.log` rather than `release.log`, and the `status` row reads `unreleased` in the error
     * tone — which on a terminal row accents the *key*, not the value. That is the one line on the
     * page doing the job the notice below does in a sentence.
     *
     * @return Terminal
     */
    private function terminal(): Terminal
    {
        $latest = $this->demo->tracks->first();

        $fields = [
            new TerminalField('artist', Config::NAME),
            new TerminalField('mixes', (string) $this->demo->tracks->count()),
        ];

        if ($latest !== null) {
            $fields[] = new TerminalField(
                'latest',
                $latest->label . ($latest->duration() !== null ? '  ' . $latest->duration() : ''),
                TerminalTone::Ok,
            );
        }

        $fields[] = new TerminalField('status', 'unreleased', TerminalTone::Error);

        return new Terminal(
            label:   'demo.log',
            command: new TerminalCommand('./demo', '--play', $this->demo->title),
            fields:  new Collection(TerminalField::class)->with(...$fields),
            narrow:  true,
        );
    }

    /**
     * The title, the ask, and the mixes.
     *
     * @return Element
     */
    private function infoSection(): Element
    {
        return new Element(HtmlTag::Section)
            ->attr(HtmlAttribute::ClassName, CssClass::DemoInfo)
            ->containing(
                new Element(HtmlTag::H1)->containing(...self::accented($this->demo->title)),
                new Element(HtmlTag::P)
                    ->attr(HtmlAttribute::ClassName, CssClass::Tagline)
                    ->containing(Config::NAME . ' — ' . ($this->demo->description ?? 'work in progress')),
                $this->notice(),
                $this->tracks(),
            );
    }

    /**
     * The sentence the whole arrangement is for.
     *
     * It is written here rather than left to the recipient to infer, because everything else on
     * this page — the gate, the per-demo password, the audio served from outside the webroot — is
     * invisible to whoever was sent the link. Saying it is the only part of the design they see.
     *
     * @return Element
     */
    private function notice(): Element
    {
        return new Element(HtmlTag::P)
            ->attr(HtmlAttribute::ClassName, CssClass::DemoNotice)
            ->containing(
                'unreleased — please keep the link and the password to yourself, '
                . 'and don\'t repost or share the audio.',
            );
    }

    /**
     * Every mix, in the order the demo lists them: newest first.
     *
     * @return Element
     */
    private function tracks(): Element
    {
        return new Element(HtmlTag::Div)
            ->attr(HtmlAttribute::ClassName, CssClass::DemoTracks)
            ->containing(...$this->demo->tracks->map($this->track(...))->toValues());
    }

    /**
     * One mix: its label, how long it runs, the control that plays it, and its shape behind them.
     *
     * `preload="none"` is not decoration — see {@link MediaPreload}. Four mixes of one track on a
     * page would otherwise be four PHP processes reading four files before anyone pressed anything.
     * It is also why the duration is sent as an attribute rather than left to the player: with
     * nothing preloaded there is no duration to read until somebody presses play, and clicking the
     * waveform to seek has to work before that.
     *
     * **The card is the custom element**, rather than a wrapper inside it, because the waveform is
     * the card's background and not a strip within it. Where there is no waveform the same card is
     * a plain `<div>` — one tag different, nothing else — so the absence costs no markup and no
     * empty box.
     *
     * @param DemoTrack $track
     * @return Element
     */
    private function track(DemoTrack $track): Element
    {
        $waveform = $this->waveforms->find($track->label);

        $row = new Element($waveform === null ? HtmlTag::Div : Tag::DemoWaveform)
            ->attr(HtmlAttribute::ClassName, CssClass::DemoTrack);

        if ($waveform !== null) {
            $row = $row
                ->attr(WaveformAttribute::Peaks, $waveform->base64())
                // A duration nothing measured is left off rather than sent as 0 — the element reads
                // an absent attribute as "no idea", which is what it is, and disables the seek.
                ->attr(WaveformAttribute::Duration, $track->seconds > 0 ? $track->seconds : null);
        }

        $row = $row->containing(
            new Element(HtmlTag::Span)
                ->attr(HtmlAttribute::ClassName, CssClass::DemoLabel)
                ->containing($track->label),
        );

        // A duration nothing read is no element at all rather than an empty one, the same way a
        // release with no embed emits no player: an empty span is a gap the stylesheet still spaces.
        $duration = $track->duration();

        if ($duration !== null) {
            $row = $row->containing(
                new Element(HtmlTag::Span)
                    ->attr(HtmlAttribute::ClassName, CssClass::DemoTime)
                    ->containing($duration),
            );
        }

        return $row->containing(
            new Element(HtmlTag::Audio)
                ->attr(HtmlAttribute::Controls, true)
                ->attr(HtmlAttribute::Preload, MediaPreload::None)
                ->attr(HtmlAttribute::Src, SitePath::DemoAudio->to($this->slug, $track->label)),
        );
    }
}
