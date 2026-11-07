<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Data;

use Closure;
use NeuroSYS\DataFile;
use NeuroSYS\Exception\ReleaseVerificationException;
use NeuroSYS\Model\Demo;
use NeuroSYS\Model\Release;
use NeuroSYS\Service\Data\DemoCodec;
use NeuroSYS\Service\Data\ReleaseCodec;
use NeuroSYS\Site;
use Phpanta\Support\File;
use Phpanta\Support\SearchableCollection;

/**
 * The EntryFile class. A slug-keyed data file the tools write into: `data/releases.json` or
 * `data/demos.json`.
 *
 * **The whole file is read through its codec before a byte is written**, so a tool never writes
 * beside an entry it could not read: a file that does not decode is refused, with where and why,
 * and stays as it was. What is written is the codec's own output, which is the form the verify
 * script holds `data/releases.json` to — so a tool's write changes the one entry and nothing else.
 *
 * A new entry goes first, because both files are read newest first: the catalogue in the order it
 * renders, the demos in the order they were made. A replacement keeps its place.
 *
 * @template T of Release|Demo
 */
final readonly class EntryFile
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param File                                          $file
     * @param class-string<T>                               $type
     * @param Closure(string, string): SearchableCollection $decode The codec's decode.
     * @param Closure(SearchableCollection): string         $encode The codec's encode.
     */
    private function __construct(
        public File $file,
        private string $type,
        private Closure $decode,
        private Closure $encode,
    ) {}

    /**
     * `data/releases.json`, or $file in its place.
     *
     * @param File|null $file A test seam.
     * @return self<Release>
     */
    public static function releases(?File $file = null): self
    {
        return new self(
            $file ?? Site::current()->dataFile(DataFile::Releases),
            Release::class,
            ReleaseCodec::decode(...),
            ReleaseCodec::encode(...),
        );
    }

    /**
     * `data/demos.json`, or $file in its place.
     *
     * @param File|null $file A test seam.
     * @return self<Demo>
     */
    public static function demos(?File $file = null): self
    {
        return new self(
            $file ?? Site::current()->dataFile(DataFile::Demos),
            Demo::class,
            DemoCodec::decode(...),
            DemoCodec::encode(...),
        );
    }

    /**
     * Every entry, in the file's order; none where there is no file yet.
     *
     * @return SearchableCollection<T>
     * @throws ReleaseVerificationException if the file is there and does not decode.
     */
    public function entries(): SearchableCollection
    {
        $json = $this->file->read();

        return $json === null ? new SearchableCollection($this->type) : ($this->decode)($json, $this->file->path);
    }

    /**
     * Whether $slug is an entry already.
     *
     * @param string $slug
     * @return bool
     * @throws ReleaseVerificationException
     */
    public function has(string $slug): bool
    {
        return $this->entries()->find($slug) !== null;
    }

    /**
     * $entry under $slug, first in the file — unless $slug is there already.
     *
     * @param string $slug
     * @param T      $entry
     * @return EntryWrite
     * @throws ReleaseVerificationException if the file does not decode.
     */
    public function prepend(string $slug, Release|Demo $entry): EntryWrite
    {
        $entries = $this->entries();
        if ($entries->find($slug) !== null) {
            return EntryWrite::Exists;
        }

        $written = new SearchableCollection($this->type)->with($slug, $entry);
        foreach ($entries as $key => $existing) {
            $written = $written->with((string) $key, $existing);
        }

        return $this->write($written);
    }

    /**
     * $entry in place of the one under $slug, where it was.
     *
     * @param string $slug
     * @param T      $entry
     * @return EntryWrite
     * @throws ReleaseVerificationException if the file does not decode.
     */
    public function replace(string $slug, Release|Demo $entry): EntryWrite
    {
        $entries = $this->entries();
        if ($entries->find($slug) === null) {
            return EntryWrite::Missing;
        }

        $written = new SearchableCollection($this->type);
        foreach ($entries as $key => $existing) {
            $written = $written->with((string) $key, (string) $key === $slug ? $entry : $existing);
        }

        return $this->write($written);
    }

    /**
     * @param SearchableCollection<T> $entries
     * @return EntryWrite
     * @throws ReleaseVerificationException if an entry holds something the file cannot.
     */
    private function write(SearchableCollection $entries): EntryWrite
    {
        return $this->file->write(($this->encode)($entries)) ? EntryWrite::Written : EntryWrite::Unwritable;
    }
}
