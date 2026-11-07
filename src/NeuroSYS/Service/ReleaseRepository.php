<?php

declare(strict_types=1);

namespace NeuroSYS\Service;

use NeuroSYS\DataFile;
use NeuroSYS\Exception\ReleaseVerificationException;
use NeuroSYS\Model\Release;
use NeuroSYS\Service\Data\ReleaseCodec;
use NeuroSYS\Site;
use Phpanta\Support\File;
use Phpanta\Support\SearchableCollection;

/**
 * The ReleaseRepository class. Loads and provides access to the site's release catalogue.
 *
 * Lazily loads `data/releases.json` on first access, through {@link ReleaseCodec}; subsequent calls
 * reuse the same collection.
 */
class ReleaseRepository
{
    private readonly File $dataFile;
    /** @var SearchableCollection<Release>|null */
    private ?SearchableCollection $collection = null;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param File|null $dataFile The releases file, or null for `data/releases.json`.
     */
    public function __construct(?File $dataFile = null)
    {
        $this->dataFile = $dataFile ?? Site::current()->dataFile(DataFile::Releases);
    }

    /**
     * Returns all releases as a typed, slug-keyed collection.
     * @return SearchableCollection<Release>
     */
    public function all(): SearchableCollection
    {
        return $this->collection ??= $this->load();
    }

    /**
     * Finds a release by its URL slug, or returns null if not found.
     *
     * @param string $slug
     * @return ?Release
     */
    public function find(string $slug): ?Release
    {
        return $this->all()->find($slug);
    }

    /**
     * Loads and converts the releases data file into a {@link SearchableCollection}.
     *
     * Unguarded, unlike {@link ProfileRepository}'s: a missing or unreadable releases file is a
     * broken deployment and says so loudly, where an empty catalogue would render as a working site
     * with nothing in it.
     *
     * @return SearchableCollection<Release>
     * @throws ReleaseVerificationException if the file is missing or holds anything but releases.
     */
    private function load(): SearchableCollection
    {
        $json = $this->dataFile->read() ?? throw new ReleaseVerificationException(sprintf(
            '%s: the catalogue is missing.',
            $this->dataFile->path,
        ));

        return ReleaseCodec::decode($json, $this->dataFile->path);
    }
}
