<?php
declare(strict_types=1);

use NeuroSYS\Model\Platform;

/**
 * External profile links rendered in the site footer.
 *
 * Leave a URL empty to hide that link entirely — same convention as release
 * formats in releases.php. Paste the profile URL once DistroKid has delivered
 * and the profile exists.
 *
 * Every platform's icon is vendored under public/assets/img/brand/; adding a new
 * one means vendoring its asset too, never hot-linking. See docs/branding.md.
 */
return [
    Platform::SoundCloud->value => 'https://soundcloud.com/neurosysgg',
    Platform::Spotify->value    => '',
    Platform::AppleMusic->value => '',
    Platform::YouTube->value    => 'https://www.youtube.com/@neurosysgg',
    Platform::X->value          => 'https://x.com/neurosysgg',
    Platform::GitHub->value     => 'https://github.com/neurosysgg',
];
