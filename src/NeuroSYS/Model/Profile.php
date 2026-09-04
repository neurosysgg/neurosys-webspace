<?php

declare(strict_types=1);

namespace NeuroSYS\Model;

/**
 * The Profile class. One external profile — a platform, and where ours lives on it.
 *
 * Replaces the `['platform' => …, 'url' => …]` shape {@link \NeuroSYS\Service\ProfileRepository}
 * used to hand back. An anonymous array shape is a value object nobody named: nothing checks the
 * keys, and a caller destructuring it wrongly gets null rather than an error. The footer asks the
 * platform for its own label, icon and height, so all this has to carry is the pairing.
 */
final readonly class Profile
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Platform $platform The platform this profile is on.
     * @param string   $url      The profile's public URL. An unclaimed profile has no Profile at
     *                           all — {@link \NeuroSYS\Service\ProfileRepository} skips it — so
     *                           this is never empty.
     */
    public function __construct(
        public Platform $platform,
        public string   $url,
    ) {}
}
