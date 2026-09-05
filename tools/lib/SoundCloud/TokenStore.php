<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\SoundCloud;

use JsonException;
use NeuroSYS\Support\Directory;
use NeuroSYS\Support\File;

/**
 * The TokenStore class. Where the token lives between runs.
 *
 * **Outside the repository, and that is the whole point.** Every other secret this project has sits
 * in `data/`, kept off the server by an `--exclude` in `deploy.sh` — one line per file, added by
 * hand, and the file is deployed the day somebody forgets. A token under `~/.config/` is not
 * protected by a rule that can be forgotten: `deploy.sh` cannot reach it, `.gitignore` does not
 * need an entry for it, and no `rsync` in this repo names a path it is under.
 *
 * **The write is atomic because the refresh token rotates.** Spending a refresh token voids it and
 * returns a new one, so the moment between "the server issued the next token" and "the next token
 * is on disk" is the only moment where a crash costs a browser round trip to recover from. That is
 * {@link File::write()}'s guarantee — written beside and renamed into place — and it is where the
 * reasoning for it is recorded, because this is the one thing in the repository that write protects.
 */
final readonly class TokenStore
{
    /** Overrides the directory, so a test never writes near a real token. */
    public const string HOME = 'NEUROSYS_SOUNDCLOUD_HOME';

    /** The file, under whichever directory {@link self::default()} settles on. */
    private const string FILE = 'soundcloud.json';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param File $file The file to read and write.
     */
    public function __construct(public File $file) {}

    /**
     * The store at its usual address: `$XDG_CONFIG_HOME/neurosys/soundcloud.json`.
     *
     * @return self
     */
    public static function default(): self
    {
        return new self(self::directory()->file(self::FILE));
    }

    /**
     * The stored token, or null where there is none to read.
     *
     * @return AccessToken|null
     * @throws SoundCloudException if the file exists and cannot be read as the JSON it should be.
     */
    public function read(): ?AccessToken
    {
        if (!$this->file->exists()) {
            return null;
        }

        $raw = $this->file->read();

        if ($raw === null) {
            throw new SoundCloudException(sprintf('%s exists but cannot be read.', $this->file->path));
        }

        try {
            /** @var array<string, mixed> $stored */
            $stored = (array) json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            // Loud rather than treated as absent. The remedy — authorizing again — overwrites this
            // file, so a truncated one that read as "no token" would be destroyed by the fix.
            throw new SoundCloudException(sprintf(
                '%s is not readable as JSON (%s). Look at it before authorizing again, which '
                . 'overwrites it.',
                $this->file->path,
                $exception->getMessage(),
            ));
        }

        return AccessToken::fromArray($stored);
    }

    /**
     * Writes the token, replacing whatever was there.
     *
     * @param AccessToken $token
     * @return void
     * @throws SoundCloudException if the directory or the file cannot be written.
     */
    public function write(AccessToken $token): void
    {
        $directory = $this->file->directory();

        // 0700 rather than the default: the directory holding a credential should not be listable
        // by anyone but its owner, and a file's own mode says nothing about that.
        if (!$directory->create(0o700)) {
            throw new SoundCloudException(sprintf('%s cannot be created.', $directory->path));
        }

        $json = json_encode($token->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";

        // 0600 is applied to the temporary file before the rename, inside write(): a file created
        // world-readable and narrowed a moment later is world-readable for that moment.
        if (!$this->file->write($json, 0o600)) {
            throw new SoundCloudException(sprintf('%s cannot be written.', $this->file->path));
        }
    }

    /**
     * The directory the store lives in.
     *
     * @return Directory
     */
    private static function directory(): Directory
    {
        $override = getenv(self::HOME);

        if (is_string($override) && $override !== '') {
            return new Directory(rtrim($override, '/'));
        }

        $config = getenv('XDG_CONFIG_HOME');
        $config = is_string($config) && $config !== '' ? $config : (getenv('HOME') ?: '.') . '/.config';

        return new Directory(rtrim($config, '/') . '/neurosys');
    }
}
