<?php

declare(strict_types=1);

namespace NeuroSYS\Service;

use NeuroSYS\Config;
use NeuroSYS\Http\ServerVariable;
use NeuroSYS\Model\ReleaseFormat;
use NeuroSYS\Support\File;

/**
 * The DownloadLogger class. Appends a JSON log entry to the downloads log for each download.
 */
class DownloadLogger
{
    private File $logFile;

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
     * @return void
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
            referrer: ServerVariable::Referer->string() ?? '',
        );

        // The locked append lives on File now, and the failure is still silent on purpose: the
        // log's directory is excluded from deploy.sh, so on the server this returns false and
        // nothing is written. That was once "fixed" with an @mkdir and had to be reverted — see
        // CLAUDE.md. A download is not worth failing over a log.
        $this->logFile->append((string) $entry);
    }
}
