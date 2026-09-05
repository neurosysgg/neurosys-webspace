<?php

declare(strict_types=1);

namespace NeuroSYS\Controller;

use NeuroSYS\Config;
use NeuroSYS\Http\Header;
use NeuroSYS\Http\Request;
use NeuroSYS\Http\Response;
use NeuroSYS\Http\ResponseHeader;
use NeuroSYS\Http\ViewResponse;
use NeuroSYS\Service\Auth;
use NeuroSYS\Service\DownloadLogEntry;
use NeuroSYS\View\StatsView;

/**
 * The StatsController class. Handles requests to the admin stats page.
 *
 * Requires admin authentication; parses the downloads log and renders aggregate stats.
 *
 * The only page on the site behind a gate, and so the only one worth telling the browser not to
 * keep: see {@link self::response()}.
 */
class StatsController implements Controller
{
    private string $logFile;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param string|null $logFile The log to read; defaults to the real one. Injectable for the
     *                             same reason {@link ReleasesController}'s repository is — the
     *                             parser is the only logic here, and it needs a log it can be
     *                             given rather than the one this machine happens to have.
     */
    public function __construct(?string $logFile = null)
    {
        $this->logFile = $logFile ?? Config::downloadLog();
    }

    public function handle(Request $request): Response
    {
        Auth::requireAdminAuth($request);

        // Logging off means the log is not read at all, not even a stale one left over from a previous machine.
        if (!Config::DOWNLOAD_LOGGING) {
            return self::response(new StatsView(0, [], [], false));
        }

        [$total, $byFormat, $byDay] = $this->parseLog();

        return self::response(new StatsView($total, $byFormat, $byDay, true));
    }

    /**
     * Wraps a stats view in a response the browser is told not to keep.
     *
     * Every other page here is public and cacheable. This one is reached by handing over a password,
     * so `no-store` keeps it out of the disk cache a shared or borrowed machine would leave it in.
     * `private` says the same thing to anything in between. Neither is load-bearing today — the page
     * shows aggregate counts and nothing else — but the rule wants to be attached to the gate rather
     * than to what happens to be behind it right now.
     */
    private static function response(StatsView $view): ViewResponse
    {
        return new ViewResponse($view, headers: [
            new Header(ResponseHeader::CacheControl, 'no-store, private'),
        ]);
    }

    /**
     * Parses the downloads log file into aggregate stats arrays.
     *
     * @return array{int, array<string, int>, array<string, int>}
     *     Total download count, counts by "slug/format" key, counts by date.
     */
    private function parseLog(): array
    {
        if (!is_file($this->logFile)) {
            return [0, [], []];
        }

        $byFormat = [];
        $byDay    = [];
        $total    = 0;

        // file() returns false on a log that exists but can't be read; foreach would TypeError.
        foreach (file($this->logFile) ?: [] as $rawLine) {
            $entry = DownloadLogEntry::fromJson(trim($rawLine));
            if ($entry === null) {
                continue;
            }
            $total++;
            $key            = $entry->slug . '/' . $entry->format;
            $byFormat[$key] = ($byFormat[$key] ?? 0) + 1;
            $day            = substr($entry->time, 0, 10) ?: '?';
            $byDay[$day]    = ($byDay[$day] ?? 0) + 1;
        }

        return [$total, $byFormat, $byDay];
    }
}
