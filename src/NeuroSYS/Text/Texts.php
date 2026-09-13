<?php

declare(strict_types=1);

namespace NeuroSYS\Text;

use NeuroSYS\Model\MusicalKey;
use Phpanta\Text\FrameworkText;

/**
 * The Texts class. The index of every catalog, so a view writes `Texts::Releases::Downloads`.
 *
 * Each constant is a catalog's class name, and PHP resolves a class constant fetch on a string, so
 * `Texts::Releases::Downloads` is {@link ReleaseText::Downloads} — the enum case itself, and so the
 * text. The index exists for the call site: one word to remember, and the section of the site to
 * look under. The catalogs are ordinary enums and can be named directly too.
 *
 * **Its constants are not upper case**, against PSR-12 and against every other constant here, and
 * `phpcs.xml.dist` exempts this file by name. They are read as a path through the site's words —
 * `Texts::Releases::Descriptions::Ill` — where `Texts::RELEASES::DESCRIPTIONS::Ill` would shout the
 * part a reader skims past. See docs/language.md.
 *
 * Every catalog is reachable from here — directly, or one step down like `Releases::Descriptions` —
 * and `TranslationTest` checks every case of every one of them, and that every translated enum under
 * `src/` is reachable. So a catalog cannot be left out of this index and go unchecked.
 */
final class Texts
{
    /** The site shell: the header, the footer, the tagline. */
    public const string Layout = LayoutText::class;

    /** The home page. */
    public const string Home = HomeText::class;

    /** The rows both terminals share. */
    public const string Terminal = TerminalText::class;

    /** The catalogue and a release's own page. */
    public const string Releases = ReleaseText::class;

    /** A demo's page. */
    public const string Demo = DemoText::class;

    /** The download statistics. */
    public const string Stats = StatsText::class;

    /** What the site says when it cannot do what was asked. */
    public const string Errors = ErrorText::class;

    /** How each external profile is labelled in the footer. */
    public const string Profiles = ProfileText::class;

    /**
     * The musical keys, which are a model enum and translated like a catalog — `Fis-Dur` beside
     * `F# Major`. A release renders its own key directly; this is here so the key's German is
     * checked with everything else's.
     */
    public const string Keys = MusicalKey::class;

    /** The framework's own words — the few it sends without a site's view around them. */
    public const string Framework = FrameworkText::class;
}
