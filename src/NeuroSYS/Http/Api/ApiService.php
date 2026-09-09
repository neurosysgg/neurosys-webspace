<?php

declare(strict_types=1);

namespace NeuroSYS\Http\Api;

/**
 * The ApiService enum. What `/api` has to offer.
 *
 * One case today, and the enum is worth having at one case for {@link ApiVersion}'s reason: the
 * segment is matched by the router as `([^/]+)` and could otherwise be any string at all, so
 * without a vocabulary there is nothing that can say a service does not exist — only a `match` with
 * a default, written wherever somebody happened to need one.
 *
 * **Adding a service is this file, its action enum, and its handlers — and no route.** That is the
 * whole point of the address being `/api/{service}/{version}/{action}` rather than a case per
 * endpoint: {@link \NeuroSYS\Support\SitePath::Api} already matches every one of them, so a new
 * service inherits the gate, the silence and the method policy without anybody remembering to
 * arrange them again.
 *
 * Server-only, and no TypeScript mirror is wanted — see {@link ApiVersion}.
 */
enum ApiService: string
{
    /**
     * Deploying, and asking what is deployed.
     *
     * It is the reason `/api` exists rather than the first thing that happened to be put there: the
     * signed push was already the one route that wrote, and generalising it was cheaper than
     * building a second endpoint beside it that would have had to repeat every one of its
     * properties.
     */
    case Update = 'update';

    /**
     * The action $action names on this service at $version, or null where it names none.
     *
     * **The one place a service is mapped to its own action set**, so the vocabulary each version
     * offers is stated once rather than reconstructed at whatever asks. The `match (true)` is
     * deliberate over a nested `match ($this)`: it keeps the version beside the service in one
     * arm, which is the pairing that actually decides the answer, and a second version of one
     * service is then a line here rather than a shape change.
     *
     * Null rather than a throw, for {@link \NeuroSYS\Http\HttpMethod::tryFrom()}'s reason: the
     * segment is whatever the caller sent, and the caller being verified does not make their typo
     * an exception. {@link \NeuroSYS\Controller\ApiController} turns it into a `404` that says so.
     *
     * @param ApiVersion $version
     * @param string $action The raw URL segment.
     * @return ApiAction|null
     */
    public function action(ApiVersion $version, string $action): ?ApiAction
    {
        return match (true) {
            $this === self::Update && $version === ApiVersion::V1 => UpdateAction::tryFrom($action),

            // A pair nothing has wired yet, which is only reachable once a second version exists.
            // It is null rather than an unhandled match for the same reason the typo above is: a
            // version this service does not offer is an address it does not have, and answering a
            // verified caller with a 500 would report our omission as their fault.
            default => null,
        };
    }
}
