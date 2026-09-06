<?php

declare(strict_types=1);

namespace NeuroSYS\Service;

use NeuroSYS\Config;
use NeuroSYS\Model\Demo;
use NeuroSYS\Support\File;
use NeuroSYS\Support\SearchableCollection;

/**
 * The DemoRepository class. Loads `data/demos.php` and finds one demo by its slug.
 *
 * Shaped like {@link ReleaseRepository} and guarded like {@link ProfileRepository}, which is the
 * one decision here worth stating. A missing `data/releases.php` is a broken deployment and
 * `require` says so loudly; a missing `data/demos.php` is the ordinary case — **the file is
 * gitignored**, so every clone starts without one and the site has to serve perfectly well with no
 * demos in it. Absent therefore means an empty catalogue, and every `/demos/…` URL 404s, which is
 * exactly what "there are no demos here" should look like.
 *
 * It is gitignored because `origin` is a public GitHub repository and this file names unreleased
 * tracks. `deploy.sh` rsyncs `data/` from the working tree without consulting git, so the file
 * still reaches the server — the two facts fit together on purpose, and neither is an oversight.
 * See CLAUDE.md and `docs/demos.md`.
 */
class DemoRepository
{
    private readonly File $dataFile;
    /** @var SearchableCollection<Demo>|null */
    private ?SearchableCollection $collection = null;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param File|null $dataFile The demos PHP data file, or null for `data/demos.php`.
     */
    public function __construct(?File $dataFile = null)
    {
        $this->dataFile = $dataFile ?? Config::dataFile('demos.php');
    }

    /**
     * Every demo, slug-keyed.
     *
     * @return SearchableCollection<Demo>
     */
    public function all(): SearchableCollection
    {
        return $this->collection ??= $this->load();
    }

    /**
     * Finds a demo by its URL slug, or null where there is none.
     *
     * **This is what makes the slug safe to use as a directory name.** A slug that comes back with
     * a demo is one `data/demos.php` declares; anything else is null here and a 404 above, so no
     * arrangement of characters in a URL reaches {@link Config::demoDir()} unless it was written
     * down in advance.
     *
     * @param string $slug
     * @return ?Demo
     */
    public function find(string $slug): ?Demo
    {
        return $this->all()->find($slug);
    }

    /**
     * Loads the data file, or answers with nothing where there is no file to load.
     *
     * @return SearchableCollection<Demo>
     */
    private function load(): SearchableCollection
    {
        /** @var array<string, Demo> $data */
        // `require` takes a path: this file is PHP that returns an array, not bytes to read.
        $data       = $this->dataFile->exists() ? require $this->dataFile->path : [];
        $collection = new SearchableCollection(Demo::class);

        foreach ($data as $slug => $demo) {
            $collection = $collection->with($slug, $demo);
        }

        return $collection;
    }
}
