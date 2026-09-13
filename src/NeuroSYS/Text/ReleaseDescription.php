<?php

declare(strict_types=1);

namespace NeuroSYS\Text;

use Phpanta\Text\Translatable;
use Phpanta\Text\Translated;
use Phpanta\Text\Translation;

/**
 * The ReleaseDescription enum. Each release's line, in both languages.
 *
 * Reached as `Texts::Releases::Descriptions::Ill`. One case per release, its backing value the
 * release's slug; the release's entry in `data/releases.php` names its case —
 * `description: Texts::Releases::Descriptions::Ill`. A release that has no case yet keeps a plain
 * string, which reads the same in every language.
 *
 * **Here rather than in the data file**, so both languages sit side by side and ship with a push:
 * `src/` is in every push, `data/` only in `./deploy.sh`.
 *
 * **German may fall back here, and only here.** Everywhere else in the catalog a missing German text
 * is a failing test. A description is written by whoever releases the track, possibly before the
 * German exists, so `TranslationTest` lets these cases fall back to the English.
 *
 * **Never a demo's.** `src/` is public, and a case naming an unreleased track would publish it; a
 * demo's description is written inline in `data/demos.php`, which is gitignored.
 */
enum ReleaseDescription: string implements Translatable
{
    use Translated;

    #[Translation(en: 'wub wub', de: 'wub wub')]
    case Ill = 'ill';

    #[Translation(en: 'debut single', de: 'debütsingle')]
    case HelloWorld = 'hello-world';
}
