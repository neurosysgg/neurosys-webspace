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
 * Native tags are deliberately absent. `<a>`, `<h1>`, `<img>` and the rest carry meaning the browser
 * already knows; a typo in one of those is a tag the browser also does not know, but the failure is
 * visible immediately rather than silent.
 */
enum Tag: string
{
    case SoundCloudPlayer = 'soundcloud-player';

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
}
