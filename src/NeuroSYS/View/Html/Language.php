<?php

declare(strict_types=1);

namespace NeuroSYS\View\Html;

/**
 * The Language enum. A language this site is written in.
 *
 * An attribute *value* like {@link LinkRel}, {@link LinkTarget}, {@link ScriptType},
 * {@link MediaPreload} and {@link MetaName}: a fixed vocabulary, so it is a case and not a string.
 * Server-only like all five — nothing client-side reads a `lang` here — so there is no TypeScript
 * mirror and none is wanted.
 *
 * **A wrong `lang` is the whole list's failure mode, which is not at all.** Nothing validates a
 * language tag: a browser meeting `lang="eng"` or `lang="en-"` does not warn, it simply stops
 * having an answer for the questions the attribute exists to answer. A screen reader announces
 * German text in an English voice, or falls back to the interface language; hyphenation breaks
 * words at the wrong points; `:lang()` selectors stop matching; a translation offer never appears.
 * Every one of those is discovered by somebody who is not looking at the markup.
 *
 * Two cases rather than {@link LinkTarget}'s one, because two are genuinely used: the imprint and
 * the privacy policy carry both languages, and {@link \NeuroSYS\Http\AcceptedLanguages} picks which
 * half a visitor is shown first. Everything else on the site is English, which is what
 * {@link \NeuroSYS\View\View::language()} answers by default.
 *
 * Bare primary subtags, deliberately — `en` rather than `en-GB`. A region says something about
 * spelling and date order that this site does not make good on, and a tag claiming more than it
 * delivers is worse than one claiming less.
 */
enum Language: string
{
    /** The site's own language: every page but the two legal documents, and those in part. */
    case English = 'en';

    /**
     * The language the imprint and the privacy policy are also written in.
     *
     * Not because the site is German but because its obligations are: § 5 DDG and § 18 Abs. 2 MStV
     * are met in German, and the English half beside each is a courtesy. See
     * {@link \NeuroSYS\View\ImprintView}.
     */
    case German = 'de';
}
