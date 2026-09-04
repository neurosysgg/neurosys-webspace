<?php

declare(strict_types=1);

namespace NeuroSYS\Service;

use NeuroSYS\Config;
use NeuroSYS\Model\ReleaseFormat;

/**
 * The DownloadLogger class. Appends a JSON log entry to the downloads log for each download.
 */
class DownloadLogger
{
    private string $logFile;

    /** Constructs an instance of {@link self}. */
    public function __construct()
    {
        $this->logFile = Config::downloadLog();
    }

    /**
     * Logs a download event.
     *
     * @param string        $slug   The release slug.
     * @param ReleaseFormat  $format The format that was downloaded.
     */
    public function log(string $slug, ReleaseFormat $format): void
    {
        if (!Config::DOWNLOAD_LOGGING) {
            return;
        }

        $entry = new DownloadLogEntry(
            time:     date('c'),
            slug:     $slug,
            format:   $format->value,
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
