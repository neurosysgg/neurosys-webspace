<?php

declare(strict_types=1);

namespace NeuroSYS\Model;

use NeuroSYS\Exception\ReleaseVerificationException;

/**
 * The Profile class. One external profile — a platform, and where ours lives on it.
 *
 * Replaces the `['platform' => …, 'url' => …]` shape {@link \NeuroSYS\Service\ProfileRepository}
 * used to hand back. An anonymous array shape is a value object nobody named: nothing checks the
 * keys, and a caller destructuring it wrongly gets null rather than an error. The footer asks the
 * platform for its own label, icon and height, so all this has to carry is the pairing.
 *
 * The URL is verified the way {@link Link\HiDriveLink}'s share id is, and for the same reason: it
 * is the one address on this site that arrives as free text from a data file rather than being
 * built from parts. {@link \NeuroSYS\View\Html\Element} would refuse a `javascript:` URL at
 * render time regardless — but that is the backstop, and a backstop reports the fault on whatever
 * page happens to draw the footer. Checking here reports it when `data/profiles.php` loads, naming
 * the value, which is where the mistake actually is.
 */
final readonly class Profile
{
    /**
     * An absolute `https://` URL: a host, then optionally a path, query or fragment.
     *
     * Stricter than what {@link \NeuroSYS\View\Html\Element} allows an `href` in general, because
     * this field is narrower than an href in general: a profile lives on someone else's site, over
     * TLS, at an address we do not construct. Nothing here is ever relative and nothing here is
     * ever `mailto:`, so allowing either would only widen what a typo can turn into.
     *
     * Two details that are load-bearing rather than incidental:
     *
     * - `\S` throughout, so no whitespace survives anywhere in the URL. Browsers strip tabs and
     *   newlines *before* resolving one, which is how `jav&#9;ascript:` reaches the parser as a
     *   scheme this pattern never sees.
     * - `\z` rather than `$`, because `$` also matches immediately before a trailing newline —
     *   the one anchor in PCRE that quietly means something other than "the end".
     */
    private const string URL_PATTERN = '#^https://[^\s/]+(?:[/?\#]\S*)?\z#i';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param Platform $platform The platform this profile is on.
     * @param string   $url      The profile's public URL. An unclaimed profile has no Profile at
     *                           all — {@link \NeuroSYS\Service\ProfileRepository} skips it — so
     *                           this is never empty.
     *
     * @throws ReleaseVerificationException if the URL is not an absolute https:// address.
     */
    public function __construct(
        public Platform $platform,
        public string   $url,
    ) {
        $this->verify();
    }

    /**
     * @throws ReleaseVerificationException
     */
    private function verify(): void
    {
        if (preg_match(self::URL_PATTERN, $this->url) !== 1) {
            throw new ReleaseVerificationException(sprintf(
                "Profile::url must be an absolute https:// URL, got '%s'. "
                . 'Paste the profile address from the platform, scheme and all.',
                $this->url,
            ));
        }
    }
}
