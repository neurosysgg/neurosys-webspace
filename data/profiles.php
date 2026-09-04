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
 * A platform whose icon isn't vendored yet is skipped even with a URL set, so
 * SoundCloud, YouTube and X are configured here but stay hidden until their
 * brand assets land in public/assets/img/brand/. See docs/branding.md.
 */
return [
    Platform::SoundCloud->value => 'https://soundcloud.com/neurosysgg',
    Platform::Spotify->value    => '',
    Platform::AppleMusic->value => '',
    Platform::YouTube->value    => 'https://www.youtube.com/@neurosysgg',
    Platform::X->value          => 'https://x.com/neurosysgg',
    Platform::GitHub->value     => 'https://github.com/neurosysgg',
];
