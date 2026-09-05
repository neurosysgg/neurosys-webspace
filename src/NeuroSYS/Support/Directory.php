<?php

declare(strict_types=1);

namespace NeuroSYS\Support;

/**
 * The Directory class. The other half of {@link File}: a path that holds files.
 *
 * The site asks it for exactly one thing — {@link \NeuroSYS\Config::dataFile()} resolves a file
 * inside `data/` through {@link self::file()}, so the one derivation of that path stays one
 * derivation and now hands back something typed. Everything else here is read by the tooling, which
 * lists and creates directories, and by the tests, which build fixtures out of them.
 *
 * **It does not recurse, and that is a decision.** {@link self::files()} lists this directory and
 * not the tree under it, and {@link self::remove()} takes away the files it holds and then itself,
 * refusing rather than descending. A fixture is one level deep and the `data/` directory is one
 * level deep; a recursive delete is a thing nothing here needs, and a thing worth not having lying
 * around where somebody could reach for it with a path they had not checked.
 */
final readonly class Directory
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $path An absolute path, for the reason {@link File} gives.
     */
    public function __construct(public string $path) {}

    /**
     * A new directory under the system's temporary one, created and ready to write into.
     *
     * Here rather than in a test helper because a fixture directory is what four test files were
     * each opening with three lines of their own — and because the name matters: it is random, so
     * two tests running at once cannot collide, and it is prefixed, so anything left behind by a
     * test that died says which suite left it.
     *
     * @param string $prefix Prepended to the random part, e.g. `neurosys-flp-`.
     * @return self
     */
    public static function temporary(string $prefix): self
    {
        $directory = new self(sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6)));

        $directory->create(0o700);

        return $directory;
    }

    /**
     * Whether there is a directory here.
     *
     * @return bool
     */
    public function exists(): bool
    {
        return is_dir($this->path);
    }

    /**
     * A file in this directory, by name. The file need not exist.
     *
     * @param string $name A file name, or a path relative to this directory — `logs/downloads.log`.
     * @return File
     */
    public function file(string $name): File
    {
        return new File($this->path . '/' . $name);
    }

    /**
     * A directory inside this one. It need not exist.
     *
     * @param string $name
     * @return self
     */
    public function directory(string $name): self
    {
        return new self($this->path . '/' . $name);
    }

    /**
     * The files directly in this directory, in the order the filesystem gives them.
     *
     * Directories are left out: every caller wants files, and one that had to check each entry
     * would be doing by hand what this exists to have done once.
     *
     * @param string $pattern A glob pattern matched against the name — `*.flac`, `*`.
     * @return list<File>
     */
    public function files(string $pattern = '*'): array
    {
        $matches = glob($this->path . '/' . $pattern) ?: [];

        return array_values(array_filter(
            array_map(static fn(string $path): File => new File($path), $matches),
            static fn(File $file): bool => $file->exists(),
        ));
    }

    /**
     * Creates the directory, and any directory above it that is missing.
     *
     * @param int $mode
     * @return bool True if the directory exists afterwards, however it got there — two processes
     *              racing to create the same one both succeed, which is the postcondition anyone
     *              calling this is actually asking about.
     */
    public function create(int $mode = 0o755): bool
    {
        return $this->exists() || @mkdir($this->path, $mode, true) || $this->exists();
    }

    /**
     * Removes the files in this directory, and then the directory.
     *
     * Refuses to descend — a subdirectory left inside means `rmdir()` fails and this answers false,
     * rather than this quietly deleting a tree somebody did not mean to name.
     *
     * @return bool True if there is nothing here afterwards.
     */
    public function remove(): bool
    {
        if (!$this->exists()) {
            return true;
        }

        foreach ($this->files() as $file) {
            if (!$file->delete()) {
                return false;
            }
        }

        return @rmdir($this->path);
    }
}
