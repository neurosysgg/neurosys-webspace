<?php
declare(strict_types=1);

use NeuroSYS\Model\Platform;

/**
 * External profile links rendered in the site footer.
 *
 * Leave a URL empty to hide that link entirely — same convention as release
 * formats in releases.php. Paste the profile URL once DistroKid has delivered
 * and the profile exists.
 */
return [
    Platform::Spotify->value    => '',
    Platform::AppleMusic->value => '',
    Platform::YouTube->value    => '',
    Platform::GitHub->value     => '',
];
