<?php
declare(strict_types=1);

namespace NeuroSYS\Service;

/**
 * The DownloadLogger class. Appends a JSON log entry to the downloads log for each download.
 */
class DownloadLogger
{
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
