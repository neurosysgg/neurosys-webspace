<?php

declare(strict_types=1);

namespace NeuroSYS\Controller;

use NeuroSYS\Exception\UpdateException;
use NeuroSYS\Http\HttpStatusCode;
use NeuroSYS\Http\PlainTextResponse;
use NeuroSYS\Http\Request;
use NeuroSYS\Http\Response;
use NeuroSYS\Service\UpdateApplier;
use NeuroSYS\Service\UpdateGate;

/**
 * The UpdateController class. The one route on this site that writes, and it answers as though it
 * does not exist unless the request carries a signature this deployment can verify.
 *
 * **The refusal is the interesting half.** A 401 would announce the endpoint; so would a 403, and so
 * would a 405 naming POST. What this returns instead is *exactly* what the router returns for a
 * path no route claims — the rendered 404 for a read method, the `text/plain` 405 with
 * `Allow: GET, HEAD` for a write one — so `/update` is indistinguishable from a typo under every
 * verb. That is why {@link \NeuroSYS\Support\SitePath::Update} is registered accepting GET and HEAD
 * as well as POST: a route accepting POST alone would answer `GET /update` with `405 Allow: POST`
 * and give itself away in the one response an idle prober is most likely to make.
 *
 * Both responses come from {@link UnroutedController}, which is the very object
 * {@link \NeuroSYS\Router} delegates to when no route matches at all. They are not two
 * implementations kept in step by a test — they are one, so "indistinguishable" is a property of
 * the structure rather than something anybody has to remember. A test asserts it anyway, because
 * it is worth failing loudly if the two are ever split again.
 *
 * Past the gate the posture inverts completely and every failure is reported in full, because the
 * caller has proved it holds the private key. There is nowhere else for that detail to go: the live
 * host has `display_errors` off and an empty `error_log`.
 */
final readonly class UpdateController implements Controller
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param UpdateGate|null $gate A test seam, the way {@link DemoAudioController}'s repository is.
     * @param UpdateApplier|null $applier Same.
     */
    public function __construct(
        private ?UpdateGate    $gate = null,
        private ?UpdateApplier $applier = null,
    ) {}

    /**
     * @param Request $request
     * @return Response
     */
    public function handle(Request $request): Response
    {
        $gate     = $this->gate ?? new UpdateGate();
        $verified = $gate->accepts($request);

        if ($verified === null) {
            return new UnroutedController()->handle($request);
        }

        [$manifest, $archive] = $verified;

        try {
            $report = ($this->applier ?? new UpdateApplier())->apply($archive, $manifest);
        } catch (UpdateException $e) {
            // Refused before anything was written, so the serial is deliberately left where it is:
            // the same payload, corrected, should be sendable without minting a new one.
            return new PlainTextResponse(
                HttpStatusCode::UnprocessableContent,
                'refused: ' . $e->getMessage() . "\n",
            );
        }

        // Only a run that actually wrote advances the serial. A dry run leaves it alone so the very
        // same payload can then be sent for real — and so a captured dry run replays to nothing.
        if ($manifest->apply && !$gate->accept($manifest->serial)) {
            $report = $report->failed(
                'the update serial',
                'could not be recorded, so this payload can be replayed — fix before the next push',
            );
        }

        return new PlainTextResponse(
            $report->isComplete() ? HttpStatusCode::Ok : HttpStatusCode::InternalServerError,
            $report->render(),
        );
    }
}
