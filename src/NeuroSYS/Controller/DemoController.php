<?php

declare(strict_types=1);

namespace NeuroSYS\Controller;

use NeuroSYS\Http\CacheControl;
use NeuroSYS\Http\Header;
use NeuroSYS\Http\Request;
use NeuroSYS\Http\Response;
use NeuroSYS\Http\ResponseHeader;
use NeuroSYS\Http\RobotsPolicy;
use NeuroSYS\Http\ViewResponse;
use NeuroSYS\Service\Auth;
use NeuroSYS\Service\DemoRepository;
use NeuroSYS\Service\WaveformRepository;
use NeuroSYS\Support\Collection;
use NeuroSYS\View\DemoView;

/**
 * The DemoController class. Handles a demo's page, behind that demo's own password.
 *
 * **There is no not-found branch here**, and its absence is the design rather than an omission.
 * Every other controller answers an unknown slug with a 404; this one hands the null straight to
 * {@link Auth::requireDemoAuth()}, which refuses it identically to a wrong password. A 404 for a
 * slug that names nothing and a 401 for one that names something is a catalogue of unreleased
 * tracks, readable one guess at a time — see that method for the rest of the reasoning, including
 * why the timing is levelled too.
 */
readonly class DemoController implements Controller
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $slug The URL slug identifying the demo.
     * @param DemoRepository|null $demos The catalogue to read, or null for the canonical one.
     *                                   Only tests pass this — `data/demos.php` is gitignored, so
     *                                   there is not reliably one to read on any given machine.
     * @param WaveformRepository|null $waveforms Where the sidecars are read from, or null for
     *                                   `data/demos/`. Only tests pass this, for the same reason.
     */
    public function __construct(
        private string $slug,
        private ?DemoRepository $demos = null,
        private ?WaveformRepository $waveforms = null,
    ) {}

    /**
     * @param Request $request
     * @return Response
     */
    public function handle(Request $request): Response
    {
        $demo = Auth::requireDemoAuth(
            $request,
            $this->slug,
            ($this->demos ?? new DemoRepository())->find($this->slug),
        );

        // Fetched here rather than in the view, and after the gate rather than before it: reading
        // these is one file read per mix, and a request that has not answered the challenge should
        // not cause any. It is the rule every controller follows — a controller fetches its own
        // data — applied to the one page whose data sits behind a password.
        $waveforms = ($this->waveforms ?? new WaveformRepository())->forDemo($this->slug, $demo);

        // Two headers a public page does not send, both attached to the gate rather than to what
        // happens to be behind it. `no-store` keeps the page out of the cache a shared or borrowed
        // machine would leave it in, and — because ViewResponse stands down when a caller has
        // already said how a response may be kept — also means no ETag and so no 304, which is
        // what stops a gated page being handed back on a guessed validator.
        return new ViewResponse(
            new DemoView($demo, $this->slug, $waveforms),
            headers: new Collection(Header::class)->with(
                new Header(ResponseHeader::CacheControl, CacheControl::doNotStore()),
                new Header(ResponseHeader::Robots, RobotsPolicy::hide()),
            ),
        );
    }
}
