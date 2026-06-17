<?php
declare(strict_types=1);

namespace NeuroSYS\Controller;

use NeuroSYS\Http\Request;
use NeuroSYS\Http\Response;
use NeuroSYS\Http\ViewResponse;
use NeuroSYS\Service\Auth;
use NeuroSYS\Service\DownloadLogEntry;
use NeuroSYS\View\StatsView;

/**
 * The StatsController class. Handles requests to the admin stats page.
 *
 * Requires admin authentication; parses the downloads log and renders aggregate stats.
 */
class StatsController implements Controller
{
    private string $logFile;

    /** Constructs an instance of {@link self}. */
    public function __construct()
    {
        $this->logFile = dirname(__DIR__, 3) . '/data/logs/downloads.log';
    }

    public function handle(Request $request): Response
    {
        Auth::requireAdminAuth($request);

        [$total, $byFormat, $byDay] = $this->parseLog();

        return new ViewResponse(new StatsView($total, $byFormat, $byDay));
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

        foreach (file($this->logFile) as $rawLine) {
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
