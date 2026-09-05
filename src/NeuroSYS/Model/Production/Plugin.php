<?php

declare(strict_types=1);

namespace NeuroSYS\Model\Production;

use NeuroSYS\Exception\ReleaseVerificationException;

/**
 * The Plugin class. One instrument or effect a release was made with.
 *
 * A name and nothing else, which is the whole design. `tools/lib/Flp/Plugins` can recover candidate
 * names from a project's wrapper blobs, but only as candidates — the layout it reads is not one FL
 * documents, and the scan finds wreckage alongside the real names. So the tool emits the list
 * **commented out** for a person to trim, and what reaches `data/releases.php` is hand-authored,
 * exactly like `description`.
 *
 * That is why this carries no version, no vendor and no id: everything else would be a field the
 * author has to keep true by hand for no gain, and a credit is a name.
 */
final readonly class Plugin
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $name As it should be credited — `Serum 2`, not `Serum2`.
     *
     * @throws ReleaseVerificationException if the name is blank.
     */
    public function __construct(public string $name)
    {
        if (trim($this->name) === '') {
            throw new ReleaseVerificationException('Plugin::name cannot be blank.');
        }
    }
}
