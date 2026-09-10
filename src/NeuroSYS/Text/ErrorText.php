<?php

declare(strict_types=1);

namespace NeuroSYS\Text;

/**
 * The ErrorText enum. What the site says when it cannot do what was asked.
 *
 * Two of these are plain-text bodies rather than pages — the 405 and the download 503 — so they
 * are put into the request's language by the controller that sends them, with `in()`.
 */
enum ErrorText: string implements Translatable
{
    use Translated;

    /** The 404 terminal's caption. */
    #[Translation(en: 'error', de: 'fehler')]
    case Error = 'error';

    #[Translation(en: '404 — not found', de: '404 — nicht gefunden')]
    case NotFound = 'not-found';

    /** The 404's way back. */
    #[Translation(en: '← home', de: '← startseite')]
    case Home = 'home';

    /** The body of every 405, whichever of the two paths sent it — see UnroutedController. */
    #[Translation(en: 'This site is read-only.', de: 'Diese Seite ist schreibgeschützt.')]
    case ReadOnly = 'read-only';

    /** The body of a download 503: a format with no file behind it yet. */
    #[Translation(
        en: "This file isn't available yet — check back soon.",
        de: 'Diese Datei ist noch nicht verfügbar — schau bald wieder vorbei.',
    )]
    case NotYetAvailable = 'not-yet-available';
}
