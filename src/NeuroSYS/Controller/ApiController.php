<?php

declare(strict_types=1);

namespace NeuroSYS\Controller;

use NeuroSYS\Exception\ApiException;
use NeuroSYS\Http\Allow;
use NeuroSYS\Http\Api\ApiAction;
use NeuroSYS\Http\Api\ApiService;
use NeuroSYS\Http\Api\ApiVersion;
use NeuroSYS\Http\Header;
use NeuroSYS\Http\HttpStatusCode;
use NeuroSYS\Http\PlainTextResponse;
use NeuroSYS\Http\Request;
use NeuroSYS\Http\Response;
use NeuroSYS\Http\ResponseHeader;
use NeuroSYS\Service\ApiGate;
use NeuroSYS\Support\Collection;

/**
 * The ApiController class. Everything under `/api`, and it answers as though none of it is there
 * unless the request carries a signature this deployment can verify.
 *
 * **The refusal is the interesting half.** A 401 would announce the endpoint; so would a 403, and
 * so would a 405 naming POST. What this returns instead is *exactly* what the router returns for a
 * path no route claims — the rendered 404 for a read method, the `text/plain` 405 with
 * `Allow: GET, HEAD` for a write one — so every address under `/api` is indistinguishable from a
 * typo under every verb. That is why {@link \NeuroSYS\Support\SitePath::Api} is registered
 * {@link \NeuroSYS\Support\MethodPolicy::Delegated}: a route accepting POST alone would answer
 * `GET /api/update/v1/patch` with `405 Allow: POST` and give itself away in the one response an
 * idle prober is most likely to make.
 *
 * Both responses come from {@link UnroutedController}, which is the very object
 * {@link \NeuroSYS\Router} delegates to when no route matches at all. They are not two
 * implementations kept in step by a test — they are one, so "indistinguishable" is a property of
 * the structure rather than something anybody has to remember. A test asserts it anyway, because
 * it is worth failing loudly if the two are ever split again.
 *
 * **It verifies before it resolves, and that order is the whole reason one class can serve every
 * service.** Asking "does this service exist" first would answer an unsigned caller through a
 * different path depending on what they guessed, and two paths that produce the same answer today
 * are two paths free to stop. Verified first, a service that does not exist and a signature that
 * does not verify are the same `null` reaching the same line.
 *
 * Past the gate the posture inverts completely and every failure is reported in full, because the
 * caller has proved it holds the private key. An unknown service, version or action is a real 404
 * with a sentence in it, and a verb that is not the action's is a real 405 naming the one that is.
 * Only the key holder ever sees either. There is nowhere else for that detail to go: the live host
 * has `display_errors` off and an empty `error_log`.
 */
final readonly class ApiController implements Controller
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $service The first segment, exactly as it was sent. A string rather than an
     *                        {@link ApiService}, because resolving it here would mean a `from()` in
     *                        the route factory — a bare `ValueError`, uncaught, *before* the
     *                        signature is checked, which is both a 500 announcing the endpoint and
     *                        an exception this repository does not own.
     * @param string $version Same, for the second.
     * @param string $action Same, for the fourth.
     * @param ApiGate|null $gate A test seam, the way {@link DemoAudioController}'s repository is.
     */
    public function __construct(
        private string   $service,
        private string   $version,
        private string   $action,
        private ?ApiGate $gate = null,
    ) {}

    /**
     * @param Request $request
     * @return Response
     */
    public function handle(Request $request): Response
    {
        $gate     = $this->gate ?? new ApiGate();
        $verified = $gate->accepts($request);

        if ($verified === null) {
            return new UnroutedController()->handle($request);
        }

        $action = $this->action();

        if ($action === null) {
            return new PlainTextResponse(HttpStatusCode::NotFound, sprintf(
                "no such API action: %s %s/%s/%s\n",
                $verified->envelope->method,
                $this->service,
                $this->version,
                $this->action,
            ));
        }

        // The gate has already checked that the *credential* was minted for this method; this asks
        // whether the method is one the action answers on at all. They are different questions and
        // both are needed — the first stops a read's credential being replayed as a write, and this
        // stops a correctly signed request asking for something that makes no sense.
        if ($action->method() !== $request->method()) {
            return new PlainTextResponse(
                HttpStatusCode::MethodNotAllowed,
                sprintf("%s answers %s\n", $this->action, $action->method()->value),
                new Collection(Header::class)->with(
                    new Header(ResponseHeader::Allow, Allow::of($action->method())),
                ),
            );
        }

        // **One catch around building the handler and around running it**, which is what makes this
        // the only place on the site that writes the word. Both throws mean the same thing — a
        // verified caller asked for something this deployment will not do — and both happen before
        // anything has been written, since UpdateApplier's own contract is that nothing has when it
        // throws. Two handlers each phrasing that refusal for themselves is two spellings of one
        // sentence, which is what `GuidelineTest`'s two-files clause caught when they were.
        try {
            $handler = $action->handler($verified);

            // Only an action that changes something consumes the serial. A read leaves it alone,
            // and so does a dry run, so the very same credential can then be sent for real.
            //
            // **Recorded before the action runs, not after**, which is the one behaviour here that
            // differs from the endpoint this replaces. That one wrote the whole tree and then
            // discovered it could not arm the replay guard, leaving a deployment that had been
            // updated and a credential that could update it again; there was nothing useful to do
            // about it but say so in the report. Arming first turns that into a refusal with
            // nothing written. It costs a serial on a deployment that cannot record one, which is a
            // deployment that is not going to accept the next push either.
            if ($handler->isWrite() && !$gate->accept($verified->envelope->serial)) {
                return new PlainTextResponse(
                    HttpStatusCode::InternalServerError,
                    "the update serial could not be recorded, so nothing was written — this "
                    . "payload would have been replayable\n",
                );
            }

            return $handler->handle();
        } catch (ApiException $e) {
            return new PlainTextResponse(
                HttpStatusCode::UnprocessableContent,
                'refused: ' . $e->getMessage() . "\n",
            );
        }
    }

    /**
     * The action all three segments name, or null where they name none.
     *
     * `tryFrom` at every step rather than `from`, for {@link \NeuroSYS\Http\HttpMethod::tryFrom()}'s
     * reason: each segment is whatever the caller sent, and a caller being verified does not make
     * their typo an exception. Three nulls collapse to one, because the difference between a
     * service that does not exist and an action that does not is of no use to anyone who has
     * already been told the address is wrong.
     *
     * @return ApiAction|null
     */
    private function action(): ?ApiAction
    {
        $service = ApiService::tryFrom($this->service);
        $version = ApiVersion::tryFrom($this->version);

        if ($service === null || $version === null) {
            return null;
        }

        return $service->action($version, $this->action);
    }
}
