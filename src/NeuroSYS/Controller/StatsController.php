<?php

declare(strict_types=1);

namespace NeuroSYS\Controller;

use NeuroSYS\Config;
use NeuroSYS\Http\CacheControl;
use NeuroSYS\Http\Header;
use NeuroSYS\Http\Request;
use NeuroSYS\Http\Response;
use NeuroSYS\Http\ResponseHeader;
use NeuroSYS\Http\ViewResponse;
use NeuroSYS\Service\Auth;
use NeuroSYS\Service\DownloadStats;
use NeuroSYS\Support\File;
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
    private File $logFile;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param File|null $logFile The log to read; defaults to the real one. Injectable for the
     *                           same reason {@link ReleasesController}'s repository is — the
     *                           parser is the only logic here, and it needs a log it can be given
     *                           rather than the one this machine happens to have.
     */
    public function __construct(?File $logFile = null)
    {
        $this->logFile = $logFile ?? Config::downloadLog();
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function handle(Request $request): Response
    {
        Auth::requireAdminAuth($request);

        // Logging off means the log is not read at all, not even a stale one left over from a
        // previous machine — and the view is handed null rather than an empty tally, because
        // "switched off" and "on, and nothing yet" are different sentences on that page.
        return self::response(new StatsView(
            Config::DOWNLOAD_LOGGING ? DownloadStats::fromLines($this->logFile->lines()) : null,
        ));
    }

    /**
     * Wraps a stats view in a response the browser is told not to keep.
     *
     * Every other page here is public and says `no-cache` — keep it, but ask before reusing it, and
     * usually be told 304. This one is reached by handing over a password, so it says `no-store`
     * instead: not "revalidate", but "do not write this to disk at all", which is what keeps it out
     * of the cache a shared or borrowed machine would leave it in. `private` says the same to
     * anything in between.
     *
     * Passing it here is also what makes {@link ViewResponse} stand down: a response
     * whose caller already said how it may be kept gets no validator and can never answer a 304, so
     * a gated page cannot be handed back on the strength of a guessed ETag. Neither part is
     * load-bearing today — the page shows aggregate counts and nothing else — but the rule wants to
     * be attached to the gate rather than to what happens to be behind it right now.
     *
     * @param StatsView $view
     * @return ViewResponse
     */
    private static function response(StatsView $view): ViewResponse
    {
        return new ViewResponse($view, headers: [
            new Header(ResponseHeader::CacheControl, CacheControl::doNotStore()),
        ]);
    }
}
