<?php

declare(strict_types=1);

namespace NeuroSYS\Model;

use NeuroSYS\Exception\ReleaseVerificationException;
use NeuroSYS\Support\BareString;

/**
 * The DemoTrack class. One mix of a demo — the file behind it, and the name it is reached by.
 *
 * A demo is several versions of the same idea, so this is the part that repeats: `v3` and `v4` of
 * one track are two of these on one {@link Demo}.
 *
 * **Both strings are validated, and they guard different things.** The label is a URL segment, so
 * one carrying a slash could never match a route and the version would be quietly unreachable —
 * the same silent failure a misspelled tag is. The file name is resolved against
 * `data/demos/{slug}/` by {@link \NeuroSYS\Controller\DemoAudioController}, so it is where path
 * traversal stops. Neither is data a request can influence — both are written in `data/demos.php`
 * — which is exactly why they are checked *there*, when the file loads, and not at the point a
 * visitor asks for one. Same arrangement as {@link Link\HiDriveLink}'s share id.
 */
#[BareString(
    '%d:%02d',
    'a printf format, which is punctuation with two holes in it rather than a name. What the two '
    . 'call sites share is the shape m:ss and nothing else: this one has seconds already, and '
    . 'Section::timestamp() has to derive them from a tick and a bpm first.',
)]
final readonly class DemoTrack
{
    /**
     * The label, as it appears in the URL: lowercase, and no separator a path could read.
     *
     * `v4`, `rc2`, `final-master`. Deliberately narrower than a route segment allows, because the
     * point is that a label and a path segment can never disagree about where one ends.
     */
    private const string LABEL_PATTERN = '/^[a-z0-9][a-z0-9-]{0,31}\z/';

    /**
     * A bare file name inside the demo's own directory, and nothing that could leave it.
     *
     * No slash, no leading dot, no `..` — a name matching this cannot name anything but a file in
     * the one directory the controller resolves it against. The controller does not rely on this
     * alone (it matches the URL against known labels and never builds a path from a request), so
     * this is the second guard on the same hazard rather than the only one.
     */
    private const string FILE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $label   How this mix is named in the URL and on the page — `v4`, `rc2`.
     * @param string $file    Its file name inside `data/demos/{slug}/`, with extension.
     * @param int    $seconds How long it runs, for the page to say so. 0 where it was not read.
     *
     * @throws ReleaseVerificationException if either name is not one this can address.
     */
    public function __construct(
        public string $label,
        public string $file,
        public int    $seconds = 0,
    ) {
        $this->verify();
    }

    /**
     * The duration as `m:ss`, or `null` where there is none to show.
     *
     * Zero is "not read" rather than "no length": nothing that reaches this page is silent, and a
     * track whose duration ffprobe could not answer for should say nothing rather than `0:00`.
     *
     * @return string|null
     */
    public function duration(): ?string
    {
        if ($this->seconds <= 0) {
            return null;
        }

        return sprintf('%d:%02d', intdiv($this->seconds, 60), $this->seconds % 60);
    }

    /**
     *
     * @return void
     * @throws ReleaseVerificationException
     */
    private function verify(): void
    {
        if (preg_match(self::LABEL_PATTERN, $this->label) !== 1) {
            throw new ReleaseVerificationException(sprintf(
                "DemoTrack::\$label must be lowercase letters, digits and dashes, got '%s'. "
                . 'It is a URL segment: anything else is a version no address can reach.',
                $this->label,
            ));
        }

        if (preg_match(self::FILE_PATTERN, $this->file) !== 1) {
            throw new ReleaseVerificationException(sprintf(
                "DemoTrack::\$file must be a bare file name, got '%s'. "
                . 'It is resolved inside data/demos/{slug}/, so it carries no directory and no "..".',
                $this->file,
            ));
        }

        if ($this->seconds < 0) {
            throw new ReleaseVerificationException(sprintf(
                'DemoTrack::$seconds cannot be negative, got %d. Use 0 for a duration nothing read.',
                $this->seconds,
            ));
        }
    }
}
