<?php

declare(strict_types=1);

namespace NeuroSYS\Service;

use NeuroSYS\Model\Release;
use NeuroSYS\Support\SearchableCollection;

/**
 * The ReleaseRepository class. Loads and provides access to the site's release catalogue.
 *
 * Lazily loads the release data on first access; subsequent calls reuse the same collection.
 * Defaults to the canonical data file path relative to this file's location.
 */
class ReleaseRepository
{
    private readonly string $dataFile;
    /** @var SearchableCollection<Release>|null */
    private ?SearchableCollection $collection = null;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param string|null $dataFile Absolute path to the releases PHP data file,
     *                              or null to use the default (data/releases.php).
     */
    public function __construct(?string $dataFile = null)
    {
        $this->dataFile = $dataFile ?? dirname(__DIR__, 3) . '/data/releases.php';
    }

    /**
     * Returns all releases as a typed, slug-keyed collection.
     * @return SearchableCollection<Release>
     */
    public function all(): SearchableCollection
    {
        return $this->collection ??= $this->load();
    }

    /** Finds a release by its URL slug, or returns null if not found. */
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
        $data       = require $this->dataFile;
        $collection = new SearchableCollection(Release::class);

        foreach ($data as $slug => $release) {
            $collection = $collection->with($slug, $release);
        }

        return $collection;
    }
}
