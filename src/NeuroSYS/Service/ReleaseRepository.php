<?php

declare(strict_types=1);

namespace NeuroSYS\Service;

use NeuroSYS\Config;
use NeuroSYS\DataFile;
use NeuroSYS\Model\Release;
use NeuroSYS\Support\File;
use NeuroSYS\Support\SearchableCollection;

/**
 * The ReleaseRepository class. Loads and provides access to the site's release catalogue.
 *
 * Lazily loads the release data on first access; subsequent calls reuse the same collection.
 * Defaults to the canonical data file path relative to this file's location.
 */
class ReleaseRepository
{
    private readonly File $dataFile;
    /** @var SearchableCollection<Release>|null */
    private ?SearchableCollection $collection = null;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param File|null $dataFile The releases PHP data file,
     *                              or null to use the default (data/releases.php).
     */
    public function __construct(?File $dataFile = null)
    {
        $this->dataFile = $dataFile ?? Config::dataFile(DataFile::Releases);
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
     * @return SearchableCollection<Release>
     */
    private function load(): SearchableCollection
    {
        /** @var array<string, Release> $data */
        // Unguarded, unlike ProfileRepository's: a missing releases.php is a broken deployment
        // and `require` says so loudly, where an empty catalogue would render as a working site
        // with nothing in it. `require` takes a path — see File.
        $data       = require $this->dataFile->path;
        $collection = new SearchableCollection(Release::class);

        foreach ($data as $slug => $release) {
            $collection = $collection->with($slug, $release);
        }

        return $collection;
    }
}
