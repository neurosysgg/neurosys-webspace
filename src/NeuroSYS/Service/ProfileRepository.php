<?php

declare(strict_types=1);

namespace NeuroSYS\Service;

use NeuroSYS\Model\Platform;
use NeuroSYS\Model\Profile;
use NeuroSYS\Support\Collection;

/**
 * The ProfileRepository class. Loads the site's external profile links.
 *
 * Mirrors {@link ReleaseRepository}: lazily reads the data file on first access
 * and reuses it thereafter. Platforms with an empty URL are omitted, so an
 * unreleased or unclaimed profile simply doesn't render.
 */
class ProfileRepository
{
    private readonly string $dataFile;
    /** @var array<string, string>|null */
    private ?array $links = null;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param string|null $dataFile Absolute path to the profiles data file,
     *                              or null to use the default (data/profiles.php).
     */
    public function __construct(?string $dataFile = null)
    {
        $this->dataFile = $dataFile ?? dirname(__DIR__, 3) . '/data/profiles.php';
    }

    /**
     * Returns the linked profiles, in enum declaration order. Platforms with no
     * URL are skipped, so an unclaimed profile simply doesn't render.
     *
     * @return Collection<Profile>
     */
    public function all(): Collection
    {
        $this->links ??= is_file($this->dataFile) ? require $this->dataFile : [];

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
