<?php

declare(strict_types=1);

namespace NeuroSYS\Model;

use NeuroSYS\Exception\ReleaseVerificationException;
use NeuroSYS\Support\Collection;
use NeuroSYS\Support\PasswordHash;

/**
 * The Demo class. Something unreleased, put in front of one person at a time.
 *
 * It is the half of the catalogue that comes before a {@link Release}, and it is **deliberately
 * much thinner than one**. A release answers for its bpm, key, genre, cover, formats and player; a
 * demo answers for a title, some mixes, and who may hear them. That is not an omission to fill in
 * later — the tags on the folder this is staged from read `GENRE=bass house???` and, on one master,
 * an empty `TITLE`. A work in progress does not have those facts settled, and a model that demanded
 * them would be asking the tool to guess.
 *
 * **The password is the whole access control**, and it protects the audio rather than only the
 * page: the files live under `data/`, which is outside the webroot, so the one way to them is
 * {@link \NeuroSYS\Controller\DemoAudioController} — behind the same gate as the page. That is why
 * this is not a HiDrive link like a release's: a share URL keeps working after the password is
 * changed, and can be forwarded by whoever was given it.
 *
 * There is no listing anywhere. `/demos` is not a route, `data/demos.php` is gitignored, and
 * nothing links here — so the existence of a demo is not something the site publishes.
 * See CLAUDE.md and `docs/demos.md`.
 */
final readonly class Demo
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string       $title       What the track is called, for the heading and the terminal.
     * @param PasswordHash $password    The bcrypt digest of this demo's own password. One per demo:
     *                                  handing out one does not hand out another.
     * @param Collection<DemoTrack> $tracks The mixes, newest first — the first is what the page
     *                                  leads with.
     * @param string|null  $description A line about what is being asked, where there is one.
     *
     * @throws ReleaseVerificationException if constructed with data it cannot serve.
     */
    public function __construct(
        public string       $title,
        public PasswordHash $password,
        public Collection   $tracks,
        public ?string      $description = null,
    ) {
        $this->verify();
    }

    /**
     * Finds one mix by the label its URL names.
     *
     * The lookup is this way round on purpose: the URL segment is **matched against labels this
     * demo already declares**, never used to build a path. So a segment naming no mix is a null
     * here and a 404 above, and there is no arrangement of characters in it that names a file.
     * {@link DemoTrack}'s own check on `$file` is the second guard on the same hazard.
     *
     * @param string $label
     * @return DemoTrack|null
     */
    public function findTrack(string $label): ?DemoTrack
    {
        return $this->tracks->first(static fn(DemoTrack $track): bool => $track->label === $label);
    }

    /**
     *
     * @return void
     * @throws ReleaseVerificationException
     */
    private function verify(): void
    {
        // Collection::with() rejects the wrong item; only its element type is left to check, which
        // is the one thing a PHP generic cannot say. Same guard as Release::verify(), which is
        // also where the reason it asks is_a() rather than !== is written down.
        if (!is_a($this->tracks->type, DemoTrack::class, true)) {
            throw new ReleaseVerificationException(
                'Demo::$tracks must be a Collection of \DemoTrack.'
            );
        }

        // A release with no formats still has a page worth showing — a cover, a player, an
        // arrangement. A demo is the audio; one with none is a password protecting nothing.
        if ($this->tracks->isEmpty()) {
            throw new ReleaseVerificationException(sprintf(
                "Demo '%s' has no tracks. A demo is the mixes it carries; stage at least one.",
                $this->title,
            ));
        }

        $labels = $this->tracks->map(static fn(DemoTrack $track): string => $track->label);

        if ($labels->unique()->count() !== $labels->count()) {
            throw new ReleaseVerificationException(sprintf(
                "Demo '%s' repeats a track label (%s). A label is the URL a mix is reached at, so "
                . 'two of them means one version is unreachable.',
                $this->title,
                $labels->join(', '),
            ));
        }
    }
}
