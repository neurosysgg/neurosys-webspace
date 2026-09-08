<?php

declare(strict_types=1);

namespace NeuroSYS\Model\Update;

use NeuroSYS\Config;
use NeuroSYS\Support\Directory;
use NeuroSYS\Support\File;

/**
 * The Deployment class. The two directories a push may write into, and the mapping from a payload
 * member's name to the file it becomes.
 *
 * **This exists because {@link UpdateRoot} used to answer these questions itself, and that was a
 * real fault rather than an untidiness.** The enum reached for {@link Config::webroot()} to decide
 * so much as whether a name was *under* a root, which meant deciding membership required resolving
 * a path — so a test that pointed `DOCUMENT_ROOT` at a sandbox got a sandbox for one root and the
 * live tree for the other, and the mirror deleted the second. It did exactly that, once, to this
 * repository.
 *
 * The split is the fix and it is the ordinary one: `UpdateRoot` is the **vocabulary** — three names,
 * asked and answered without touching a filesystem — and this is the **environment**, which is a
 * value a caller holds rather than a static reached through. So a test constructs one over a
 * sandbox and cannot reach anything else, and production constructs {@link self::current()} and
 * cannot reach a sandbox.
 */
final readonly class Deployment
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Directory $above Where `src/` and `autoload.php` live.
     * @param Directory $webroot Where `public/` lands, under whatever name the server calls it.
     */
    public function __construct(private Directory $above, private Directory $webroot) {}

    /**
     * The deployment this request is running in.
     *
     * @return self
     */
    public static function current(): self
    {
        return new self(Config::above(), Config::webroot());
    }

    /**
     * Where a root's tree lives, or null when the root is a single file.
     *
     * @param UpdateRoot $root
     * @return Directory|null
     */
    public function directory(UpdateRoot $root): ?Directory
    {
        return match ($root) {
            UpdateRoot::Public   => $this->webroot,
            UpdateRoot::Source   => $this->above->directory($root->value),
            UpdateRoot::Autoload => null,
        };
    }

    /**
     * The absolute destination for a payload member.
     *
     * The payload's prefix is stripped and the root's own directory put in its place, which for
     * {@link UpdateRoot::Source} is the identity and for {@link UpdateRoot::Public} is the whole
     * point — the directory is `public/` in the repository and `neurosys/` on the live host.
     *
     * @param UpdateRoot $root
     * @param string $name A member name that root claimed.
     * @return File
     */
    public function destination(UpdateRoot $root, string $name): File
    {
        $directory = $this->directory($root);

        return $directory === null
            ? $this->above->file($root->value)
            : $directory->file(substr($name, strlen($root->value) + 1));
    }

    /**
     * The payload name a file inside a root's tree would have been packed under.
     *
     * The inverse of {@link self::destination()}, and it exists for the mirror: the applier walks
     * what is on disk and has to ask whether the payload named it. Written beside its inverse so
     * the two halves of one mapping cannot drift.
     *
     * @param UpdateRoot $root
     * @param string $path An absolute path inside {@link self::directory()}.
     * @return string
     */
    public function nameOf(UpdateRoot $root, string $path): string
    {
        $directory = $this->directory($root);

        return $directory === null
            ? $root->value
            : $root->value . '/' . ltrim(substr($path, strlen($directory->path)), '/');
    }
}
