<?php

declare(strict_types=1);

namespace NeuroSYS\Text;

use Phpanta\Text\Translatable;
use Phpanta\Text\Translated;
use Phpanta\Text\Translation;

/**
 * The DemoText enum. The words of a demo's page.
 *
 * A demo's own description is not here: `src/` is public, and a case naming an unreleased track
 * would publish it. It is written inline in `data/demos.php`, which is gitignored.
 */
enum DemoText: string implements Translatable
{
    use Translated;

    // The terminal's captions, beside TerminalText's two shared ones.

    #[Translation(en: 'mixes', de: 'mixes')]
    case Mixes = 'mixes';

    #[Translation(en: 'latest', de: 'neueste')]
    case Latest = 'latest';

    /** The status row's value. */
    #[Translation(en: 'unreleased', de: 'unveröffentlicht')]
    case Unreleased = 'unreleased';

    /** The tagline of a demo that has no description of its own. */
    #[Translation(en: 'work in progress', de: 'in arbeit')]
    case WorkInProgress = 'work-in-progress';

    /** The sentence the whole arrangement is for — see DemoView::notice(). */
    #[Translation(
        en: "unreleased — please keep the link and the password to yourself, and don't repost or "
            . 'share the audio.',
        de: 'unveröffentlicht — bitte behalte link und passwort für dich, und lade das audio '
            . 'nirgends hoch und teile es nicht.',
    )]
    case Notice = 'notice';
}
