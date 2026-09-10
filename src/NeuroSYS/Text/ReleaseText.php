<?php

declare(strict_types=1);

namespace NeuroSYS\Text;

/**
 * The ReleaseText enum. The words of the catalogue and of a release's own page.
 */
enum ReleaseText: string implements Translatable
{
    use Translated;

    /**
     * What each release is, in a line — `Texts::Releases::Descriptions::Ill`. A step of the path
     * rather than a value, which is why it is not upper case; see {@link Texts}.
     */
    public const string Descriptions = ReleaseDescription::class;

    /** A catalogue card's tempo. */
    #[Translation(en: '{bpm} bpm', de: '{bpm} bpm')]
    case Beats = 'beats';

    /** What the cover is, for whoever cannot see it. */
    #[Translation(en: '{title} cover art', de: 'cover von {title}')]
    case CoverArt = 'cover-art';

    // The terminal's captions, beside TerminalText's two shared ones.

    #[Translation(en: 'bpm', de: 'bpm')]
    case Bpm = 'bpm';

    #[Translation(en: 'key', de: 'tonart')]
    case Key = 'key';

    #[Translation(en: 'genre', de: 'genre')]
    case Genre = 'genre';

    /** How long the track took to make. */
    #[Translation(en: 'time', de: 'zeit')]
    case Time = 'time';

    #[Translation(en: 'made with', de: 'gemacht mit')]
    case MadeWith = 'made-with';

    /** The status row's value. */
    #[Translation(en: 'ready', de: 'fertig')]
    case Ready = 'ready';

    // The page's two headings.

    #[Translation(en: 'arrangement', de: 'arrangement')]
    case Arrangement = 'arrangement';

    #[Translation(en: 'downloads', de: 'downloads')]
    case Downloads = 'downloads';

    // What a download card says about its format.

    #[Translation(
        en: 'non-commercial — commercial licensing: {email}',
        de: 'nicht kommerziell — kommerzielle lizenz: {email}',
    )]
    case Stems = 'stems';

    #[Translation(en: '320 kbps', de: '320 kbps')]
    case Mp3 = 'mp3';

    #[Translation(en: 'OGG Vorbis', de: 'OGG Vorbis')]
    case Ogg = 'ogg';

    #[Translation(en: 'lossless, 24-bit/48kHz', de: 'verlustfrei, 24 bit/48 kHz')]
    case Lossless = 'lossless';

    #[Translation(en: 'lossy', de: 'verlustbehaftet')]
    case Lossy = 'lossy';
}
