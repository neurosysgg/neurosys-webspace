<?php

declare(strict_types=1);

namespace NeuroSYS\Service;

/**
 * The DownloadLogger class. Appends a JSON log entry to the downloads log for each download.
 */
class DownloadLogger
{
    /**
     * Master switch for download logging. **Deliberately off — nothing about a download is recorded.**
     *
     * The early return in {@link self::log()} happens before the {@link DownloadLogEntry} is built, so the
     * referrer is never even read. Turning this on is a privacy-policy decision before it is a code one:
     * `data/privacy.html` makes no download-tracking claim, so it would have to be amended first. See CLAUDE.md.
     */
    public const bool ENABLED = false;

    private string $logFile;

    /** Constructs an instance of {@link self}. */
    public function __construct()
    {
        $this->logFile = dirname(__DIR__, 3) . '/data/logs/downloads.log';
    }

    /**
     * Logs a download event.
     *
     * @param string $slug   The release slug.
     * @param string $format The format identifier (e.g. 'flac', 'mp3').
     */
    public function log(string $slug, string $format): void
    {
        if (!self::ENABLED) {
            return;
        }

        $entry = new DownloadLogEntry(
            time:     date('c'),
            slug:     $slug,
            format:   $format,
            referrer: $_SERVER['HTTP_REFERER'] ?? '',
        );

        $fp = @fopen($this->logFile, 'ab');
        if ($fp === false) {
            return;
        }
        if (flock($fp, LOCK_EX)) {
            fwrite($fp, $entry . "\n");
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }
}
