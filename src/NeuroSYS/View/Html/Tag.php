<?php

declare(strict_types=1);

namespace NeuroSYS\View\Html;

/**
 * The Tag enum. Every custom element the site emits or builds.
 *
 * A tag name is a contract with the browser and with `assets/ts/elements/`: an element the browser
 * has never heard of renders as an inert inline box with no error anywhere, so a misspelled tag is
 * invisible. This is the one list of them, and `assets/ts/model/Tag.ts` mirrors it — the parity test
 * compares the two, so the server cannot emit a tag the client does not register.
 *
 * The standard elements are {@link HtmlTag}, and the split is the point: a case here has to be
 * registered client-side, is mirrored in `assets/ts/model/Tag.ts`, and is asserted against the
 * served markup. None of that applies to `<section>`, which the browser already knows.
 */
enum Tag: string implements TagName
{
    case SoundCloudPlayer  = 'soundcloud-player';
    case SoundCloudProfile = 'soundcloud-profile';

    case CoverArt = 'cover-art';

    case TerminalWindow  = 'terminal-window';
    case TerminalCommand = 'terminal-command';
    case TerminalField   = 'terminal-field';
    case TerminalKey     = 'terminal-key';
    case TerminalValue   = 'terminal-value';
    case TerminalCursor  = 'terminal-cursor';

    case DownloadList  = 'download-list';
    case DownloadCard  = 'download-card';
    case DownloadLabel = 'download-label';
    case DownloadMeta  = 'download-meta';

    case ReleaseList  = 'release-list';
    case ReleaseCard  = 'release-card';
    case ReleaseTitle = 'release-title';
    case ReleaseMeta  = 'release-meta';

    public function tagName(): string
    {
        return $this->value;
    }

    /**
     * Never. A custom element with no closing tag is a parse error the browser recovers from by
     * swallowing everything after it, which is about as quiet as a failure gets.
     */
    public function isVoid(): bool
    {
        return false;
    }
}
