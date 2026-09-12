<?php

declare(strict_types=1);

namespace NeuroSYS\Text;

/**
 * The ErrorText enum. What the site says when it cannot do what was asked.
 *
 * One of these is a plain-text body rather than a page — the download 503 — so it is put into the
 * request's language by the controller that sends it, with `in()`. The 405's body is the
 * framework's to say, in {@link FrameworkText}.
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

    /** The body of a download 503: a format with no file behind it yet. */
    #[Translation(
        en: "This file isn't available yet — check back soon.",
        de: 'Diese Datei ist noch nicht verfügbar — schau bald wieder vorbei.',
    )]
    case NotYetAvailable = 'not-yet-available';
}
