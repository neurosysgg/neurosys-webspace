<?php

declare(strict_types=1);

namespace NeuroSYS\Service;

use NeuroSYS\Config;
use NeuroSYS\DataFile;
use NeuroSYS\Model\Platform;
use NeuroSYS\Model\Profile;
use NeuroSYS\Support\Collection;
use NeuroSYS\Support\File;

/**
 * The ProfileRepository class. Loads the site's external profile links.
 *
 * Mirrors {@link ReleaseRepository}: lazily reads the data file on first access
 * and reuses it thereafter. Platforms with an empty URL are omitted, so an
 * unreleased or unclaimed profile simply doesn't render.
 */
class ProfileRepository
{
    private readonly File $dataFile;
    /** @var array<string, string>|null */
    private ?array $links = null;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param File|null $dataFile The profiles data file, or null for the default
     *                            (`data/profiles.php`).
     */
    public function __construct(?File $dataFile = null)
    {
        $this->dataFile = $dataFile ?? Config::dataFile(DataFile::Profiles);
    }

    /**
     * Returns the linked profiles, in enum declaration order. Platforms with no
     * URL are skipped, so an unclaimed profile simply doesn't render.
     *
     * @return Collection<Profile>
     */
    public function all(): Collection
    {
        // `require` takes a path: this file is PHP that returns an array, not bytes to read.
        $this->links ??= $this->dataFile->exists() ? require $this->dataFile->path : [];

        $linked = new Collection(Profile::class);

        foreach (Platform::cases() as $platform) {
            $url = $this->links[$platform->value] ?? '';

            if ($url === '') {
                continue;
            }

            $linked = $linked->with(new Profile($platform, $url));
        }

        return $linked;
    }
}
