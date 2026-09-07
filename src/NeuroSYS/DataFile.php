<?php

declare(strict_types=1);

namespace NeuroSYS;

/**
 * The DataFile enum. Every file this site reads out of `data/`.
 *
 * **A misspelled name here is a working site with nothing in it.** {@link Config::dataFile()} hands
 * back a {@link Support\File} for any path at all, `read()` answers null for one that is not there,
 * and each repository turns that null into an empty collection on purpose — because a clone that
 * has never staged a demo has to be a site rather than a fatal. So the guard that makes a fresh
 * checkout work is the same guard that swallows a typo: `releaes.php` gives an empty catalogue, a
 * 200, and no line in any log. Two of the seven do it worse than that — {@link self::Admin} and
 * {@link self::SiteAuth} are where the credentials live.
 *
 * The vocabulary already existed before this enum did. It was written down as a hand-maintained
 * data provider in `test/unit/ConfigTest.php`, which listed four of these seven and had no way to
 * notice the other three; that provider now iterates {@link self::cases()} and asks
 * {@link self::isTracked()} instead, so the list cannot fall behind the site again.
 */
enum DataFile: string
{
    /** The catalogue: PHP returning slug-keyed {@link Model\Release} objects. */
    case Releases = 'releases.php';

    /** The footer's profile links, keyed by {@link Model\Platform} value. */
    case Profiles = 'profiles.php';

    /**
     * The demos, named per {@link Model\Demo} slug.
     *
     * Gitignored — `origin` is a public repository and this file names unreleased tracks — and
     * deliberately *not* excluded from `deploy.sh`, which rsyncs the working tree rather than
     * consulting git. So it never reaches GitHub and always reaches Strato.
     */
    case Demos = 'demos.php';

    /** bcrypt credentials for `/admin/stats`. The repo copy is a placeholder; the deploy skips it. */
    case Admin = 'admin.php';

    /**
     * The pre-launch site gate's credentials, whose **absence is the off switch**.
     *
     * The one file here whose presence changes what the site does rather than what it shows, which
     * makes it the one where a misspelling is not merely quiet but inverted: a typo reads as "no
     * such file", and no such file means the gate stands down.
     */
    case SiteAuth = 'site_auth.php';

    /**
     * The privacy policy in German — half of the one document {@link View\Html\RawHtml} exists for.
     *
     * **Two files rather than one, split at the boundary that was always in it.** The policy was a
     * single `privacy.html` holding a German document and an English one end to end, which is how
     * two e-recht24 exports get concatenated by hand. Now that
     * {@link \NeuroSYS\View\PrivacyView} shows the visitor's own language first, the halves have
     * to be separable — and separating them at read time would mean searching a legal document for
     * a heading, which is the fragile way round.
     *
     * Both are tracked, so a clone has the policy it needs; a half that fails to read is an empty
     * half rather than an error, which is what {@link \NeuroSYS\Support\File::read()} already
     * answered for the single file.
     */
    case PrivacyGerman = 'privacy.de.html';

    /** The privacy policy in English, the other half of {@link self::PrivacyGerman}. */
    case PrivacyEnglish = 'privacy.en.html';

    /**
     * The downloads log, and the only case that is written rather than read.
     *
     * Nothing writes it today: {@link Config::DOWNLOAD_LOGGING} is off for legal reasons and
     * {@link Service\DownloadLogger::log()} returns on that switch before an entry is built. Its
     * directory is excluded from `deploy.sh` and {@link Support\File} will not create one, so a
     * logger switched on later writes nothing on the server until `data/logs/` exists by hand.
     */
    case DownloadLog = 'logs/downloads.log';

    /**
     * Whether the repository carries this file, and so whether every clone has it.
     *
     * The five that are tracked have to be present for the site to be the site; the other three
     * each have their own reason to be absent — two are gitignored so that a public repository
     * cannot publish what they hold, and the third does not exist until something logs a download.
     * That difference is what the test asserting these files are where {@link Config::dataFile()}
     * says used to encode by listing four names and omitting three without saying so.
     *
     * @return bool
     */
    public function isTracked(): bool
    {
        return match ($this) {
            self::Releases, self::Profiles, self::Admin,
            self::PrivacyGerman, self::PrivacyEnglish => true,
            self::Demos, self::SiteAuth, self::DownloadLog            => false,
        };
    }
}
