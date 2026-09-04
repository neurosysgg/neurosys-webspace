<?php

declare(strict_types=1);

namespace NeuroSYS\Service;

use NeuroSYS\Model\Platform;

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
     * Returns the linked platforms paired with their URLs, in enum declaration
     * order. Platforms with no URL are skipped, so an unclaimed profile simply
     * doesn't render.
     *
     * @return array<array{platform: Platform, url: string}>
     */
    public function all(): array
    {
        $this->links ??= is_file($this->dataFile) ? require $this->dataFile : [];

        $linked = [];

        foreach (Platform::cases() as $platform) {
            $url = $this->links[$platform->value] ?? '';

            if ($url === '') {
                continue;
            }

            $linked[] = ['platform' => $platform, 'url' => $url];
        }

        return $linked;
    }
}
