<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Release;

use NeuroSYS\Tool\Flp\FlpException;
use NeuroSYS\Tool\Flp\FlpFile;
use NeuroSYS\Tool\Flp\Project;
use NeuroSYS\Support\Directory;
use NeuroSYS\Support\File;
use ZipArchive;

/**
 * The ProjectFile class. The FL Studio project belonging to a release folder, if it has one.
 *
 * Finding it is its own small problem, because a project is not stored the way a master is. A
 * `.flp` on its own is not portable — it references samples by absolute path — so these are kept
 * zipped, the way FL's own *Export project file* writes them, and the zip beside a release is
 * usually where the project actually lives. So this looks for a loose `.flp` first and then inside
 * any zip in the folder.
 *
 * The zip is read **into memory rather than extracted**: `FlpFile::read()` takes bytes, and a
 * staging tool that unpacked a 500MB archive to read 8MB out of the middle of it would be doing
 * something the rest of this layer never does.
 *
 * A project that will not parse is carried as an {@link self::$error} rather than thrown, because
 * every other thing that can be wrong with a folder arrives as a {@link Finding} and this one is
 * not special enough to end the command by itself.
 */
final readonly class ProjectFile
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string       $name    The project's file name, for the report.
     * @param Project|null $project Null when it could not be parsed.
     * @param string|null  $error   Why not, when it could not.
     */
    private function __construct(
        public string $name,
        public ?Project $project,
        public ?string $error = null,
    ) {}

    /**
     * Reads a project named outright — `--project`, pointing at a `.flp`, a zip, or a folder.
     *
     * @param string $path
     * @return self|null null when the path holds no project, which the report says out loud rather
     *                   than falling back to the folder: a named project that is not there is a
     *                   typo, and silently reading a different one would hide it.
     */
    public static function at(string $path): ?self
    {
        if (is_dir($path)) {
            return self::in(new Directory($path));
        }

        $file = new File($path);

        if (!$file->exists()) {
            return null;
        }

        return $file->extension() === 'flp'
            ? self::read($file->name(), $file->read() ?? '')
            : self::inZip($file);
    }

    /**
     * Finds the project belonging to a folder.
     *
     * @param Directory $folder
     * @return self|null null when the folder holds no project at all, which is a WARN rather than
     *                   an error — the two shipped releases were staged without one.
     */
    public static function in(Directory $folder): ?self
    {
        foreach ($folder->files('*.flp') as $loose) {
            return self::read($loose->name(), $loose->read() ?? '');
        }

        foreach ($folder->files('*.zip') as $archive) {
            $found = self::inZip($archive);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * The first `.flp` inside a zip.
     *
     * @param File $archive
     * @return self|null
     */
    private static function inZip(File $archive): ?self
    {
        // Asked before opening: a zero-byte file is not an archive, and handing one to ZipArchive
        // is deprecated as of PHP 8.5 rather than merely false — which `failOnWarning` turns into
        // a failing test the moment any folder holds a placeholder.
        if ($archive->size() === 0) {
            return null;
        }

        $zip = new ZipArchive();

        if ($zip->open($archive->path) !== true) {
            return null;
        }

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);

                if ($name === false || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'flp') {
                    continue;
                }

                return self::read(basename($name), (string) $zip->getFromIndex($index));
            }
        } finally {
            $zip->close();
        }

        return null;
    }

    /**
     * @param string $name
     * @param string $bytes
     * @return self
     */
    private static function read(string $name, string $bytes): self
    {
        try {
            return new self($name, Project::of(FlpFile::read($bytes)));
        } catch (FlpException $failure) {
            return new self($name, null, $failure->getMessage());
        }
    }
}
