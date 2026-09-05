<?php

declare(strict_types=1);

namespace NeuroSYS\Model\Production;

/**
 * The SectionKind enum. What a part of an arrangement is doing.
 *
 * The vocabulary the **stylesheet** accents, which is the only reason it exists — a drop and an
 * intro should not read the same on the page. It is mirrored in `assets/ts/model/SectionKind.ts`
 * and pinned by `test/js/enum-parity.test.mjs`, because a case renamed on one side only would leave
 * a rule matching nothing, which on a dark page looks like a layout bug rather than a typo.
 *
 * **The label is what renders; this only decides the accent**, and that split is deliberate. A
 * marker in a real project is free text a person typed at four in the morning: `ill` carries
 * `SWITCH 1` and `SWITCH 2`, `break shit` carries `BUILD` where `hello world!` carries `BUILDUP`.
 * Forcing those into cases would either lose the numbering or need a case per spelling. So
 * {@link self::classify()} is allowed to find nothing, and a section it does not recognise is drawn
 * plainly and still named — the same arrangement `TerminalTone` has with a row's text.
 */
enum SectionKind: string
{
    case Intro     = 'intro';
    case Build     = 'build';
    case Drop      = 'drop';
    case Break     = 'break';
    case Bridge    = 'bridge';
    case Switchover = 'switch';
    case BuildDown = 'builddown';
    case Outro     = 'outro';

    /**
     * The kind a marker's label reads as, or null where it reads as nothing in particular.
     *
     * Ordered longest-prefix-first, because `BUILDDOWN` starts with `BUILD` and a shorter match
     * would swallow it — the one place the order of these arms is load-bearing rather than tidy.
     *
     * @param string $label
     * @return self|null
     */
    public static function classify(string $label): ?self
    {
        $normalised = strtolower(trim($label));

        return match (true) {
            str_starts_with($normalised, 'builddown'),
            str_starts_with($normalised, 'build down') => self::BuildDown,
            str_starts_with($normalised, 'build')      => self::Build,
            str_starts_with($normalised, 'intro')      => self::Intro,
            str_starts_with($normalised, 'drop')       => self::Drop,
            str_starts_with($normalised, 'break')      => self::Break,
            str_starts_with($normalised, 'bridge')     => self::Bridge,
            str_starts_with($normalised, 'switch')     => self::Switchover,
            str_starts_with($normalised, 'outro')      => self::Outro,
            default                                    => null,
        };
    }
}
