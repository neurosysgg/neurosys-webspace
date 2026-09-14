<?php

declare(strict_types=1);

namespace NeuroSYS;

use Phpanta\DataFileName;

/**
 * The DataFile enum. Every file this site reads out of `data/`.
 *
 * **A misspelled name here is a working site with nothing in it.** {@link App::dataFile()} hands
 * back a {@link Support\File} for any path at all, `read()` answers null for one that is not there,
 * and each repository turns that null into an empty collection on purpose — because a clone that
 * has never staged a demo has to be a site rather than a fatal. So the guard that makes a fresh
 * checkout work is the same guard that swallows a typo: `releaes.php` gives an empty catalogue, a
 * 200, and no line in any log. The files where that would be worse — the admin's credentials and
 * keys — are not here: the code that reads them is the framework's, so they are
 * {@link CredentialFile}'s cases, and {@link App::dataFiles()} lists both vocabularies together.
 *
 * `test/unit/AppTest.php` iterates {@link self::cases()} and asks {@link self::isTracked()}
 * rather than keeping a hand-maintained list of its own, so the list of files the site expects
 * cannot fall behind the site. See docs/history/types.md.
 */
enum DataFile: string implements DataFileName
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

    /**
     * The privacy policy in German — half of the one document {@link View\Html\MarkupParser}
     * exists for.
     *
     * **Two files rather than one, split at the boundary that was always in it.** The policy was a
     * single `privacy.html` holding a German document and an English one end to end, which is how
     * two e-recht24 exports get concatenated by hand. Now that
     * {@link \NeuroSYS\View\PrivacyView} shows the visitor's own language first, the halves have
     * to be separable — and separating them at read time would mean searching a legal document for
     * a heading, which is the fragile way round.
     *
     * Both are tracked, so a clone has the policy it needs; a half that fails to read is an empty
     * half rather than an error, which is what {@link \Phpanta\Support\File::read()} already
     * answered for the single file.
     */
    case PrivacyGerman = 'privacy.de.html';

    /** The privacy policy in English, the other half of {@link self::PrivacyGerman}. */
    case PrivacyEnglish = 'privacy.en.html';

    /**
     * The downloads log, and the only case that is written rather than read.
     *
     * Nothing writes it today: {@link Site::DOWNLOAD_LOGGING} is off for legal reasons and
     * {@link Service\DownloadLogger::log()} returns on that switch before an entry is built. Its
     * directory is excluded from `deploy.sh` and {@link Support\File} will not create one, so a
     * logger switched on later writes nothing on the server until `data/logs/` exists by hand.
     */
    case DownloadLog = 'logs/downloads.log';

    /**
     * Whether the repository carries this file, and so whether every clone has it.
     *
     * The four that are tracked have to be present for the site to be the site; the other two each
     * have their own reason to be absent — the demos are gitignored so that a public repository
     * cannot publish unreleased tracks, and the log does not exist until something logs a
     * download. The test asserting these files are
     * where {@link App::dataFile()} says asks this rather than listing names.
     *
     * @return bool
     */
    public function isTracked(): bool
    {
        return match ($this) {
            self::Releases, self::Profiles,
            self::PrivacyGerman, self::PrivacyEnglish => true,
            self::Demos, self::DownloadLog            => false,
        };
    }
}
