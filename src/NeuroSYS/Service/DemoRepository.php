<?php

declare(strict_types=1);

namespace NeuroSYS\Service;

use NeuroSYS\DataFile;
use NeuroSYS\Exception\ReleaseVerificationException;
use NeuroSYS\Model\Demo;
use NeuroSYS\Service\Data\DemoCodec;
use NeuroSYS\Site;
use Phpanta\Support\File;
use Phpanta\Support\SearchableCollection;

/**
 * The DemoRepository class. Loads `data/demos.json` and finds one demo by its slug.
 *
 * Shaped like {@link ReleaseRepository} and guarded like {@link ProfileRepository}, which is the
 * one decision here worth stating. A missing `data/releases.json` is a broken deployment and
 * says so loudly; a missing `data/demos.json` is the ordinary case — **the file is
 * gitignored**, so every clone starts without one and the site has to serve perfectly well with no
 * demos in it. Absent therefore means an empty catalogue, and every `/demos/…` URL 404s, which is
 * exactly what "there are no demos here" should look like.
 *
 * It is gitignored because `origin` is a public GitHub repository and this file names unreleased
 * tracks. `deploy.sh` rsyncs `data/` from the working tree without consulting git, so the file
 * still reaches the server — the two facts fit together on purpose, and neither is an oversight.
 * See `docs/demos.md`.
 */
class DemoRepository
{
    private readonly File $dataFile;
    /** @var SearchableCollection<Demo>|null */
    private ?SearchableCollection $collection = null;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param File|null $dataFile The demos file, or null for `data/demos.json`.
     */
    public function __construct(?File $dataFile = null)
    {
        $this->dataFile = $dataFile ?? Site::current()->dataFile(DataFile::Demos);
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
     * a demo is one `data/demos.json` declares; anything else is null here and a 404 above, so no
     * arrangement of characters in a URL reaches {@link Site::demoDir()} unless it was written
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
     * Loads the data file, or answers with nothing where there is no file to load. A file that is
     * there and does not read is refused, not taken for none: it was written to say something.
     *
     * @return SearchableCollection<Demo>
     * @throws ReleaseVerificationException if the file holds anything but demos.
     */
    private function load(): SearchableCollection
    {
        $json = $this->dataFile->read();

        return $json === null
            ? new SearchableCollection(Demo::class)
            : DemoCodec::decode($json, $this->dataFile->path);
    }
}
