<?php

declare(strict_types=1);

namespace NeuroSYS\Service;

use NeuroSYS\DataFile;
use NeuroSYS\Exception\ReleaseVerificationException;
use NeuroSYS\Model\Platform;
use NeuroSYS\Model\Profile;
use NeuroSYS\Service\Data\Record;
use NeuroSYS\Site;
use Phpanta\Support\Collection;
use Phpanta\Support\File;

/**
 * The ProfileRepository class. Loads the site's external profile links out of `data/profiles.json`.
 *
 * Mirrors {@link ReleaseRepository}: lazily reads the data file on first access
 * and reuses it thereafter. Platforms with no URL, or an empty one, are omitted, so an
 * unreleased or unclaimed profile simply doesn't render.
 */
class ProfileRepository
{
    private readonly File $dataFile;
    /** @var Collection<Profile>|null */
    private ?Collection $profiles = null;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param File|null $dataFile The profiles data file, or null for the default
     *                            (`data/profiles.json`).
     */
    public function __construct(?File $dataFile = null)
    {
        $this->dataFile = $dataFile ?? Site::current()->dataFile(DataFile::Profiles);
    }

    /**
     * Returns the linked profiles, in enum declaration order. Platforms with no
     * URL are skipped, so an unclaimed profile simply doesn't render.
     *
     * @return Collection<Profile>
     * @throws ReleaseVerificationException if the file is there and is not one URL per platform.
     */
    public function all(): Collection
    {
        return $this->profiles ??= $this->load();
    }

    /**
     * @return Collection<Profile>
     * @throws ReleaseVerificationException
     */
    private function load(): Collection
    {
        $json   = $this->dataFile->read();
        $links  = $json === null ? null : Record::parse($json, $this->dataFile->path);
        $linked = new Collection(Profile::class);

        foreach (Platform::cases() as $platform) {
            $url = $links?->optionalString($platform) ?? '';

            if ($url === '') {
                continue;
            }

            $linked = $linked->with(new Profile($platform, $url));
        }

        return $linked;
    }
}
