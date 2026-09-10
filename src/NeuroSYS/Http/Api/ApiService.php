<?php

declare(strict_types=1);

namespace NeuroSYS\Http\Api;

/**
 * The ApiService enum. What `/api` has to offer.
 *
 * Two cases, and the enum would be worth having at one for {@link ApiVersion}'s reason: the
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
 * **{@link self::Health} is what cashed that claim**, and it cost what the paragraph above said it
 * would: a case here, one arm of the `match` below, an action enum and a handler. No route, no
 * policy, no second arrangement of anything. A claim about an extension point made while only one
 * thing had ever used it is worth checking rather than trusting, and this one held.
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
     * Asking this deployment what it is.
     *
     * The read-only counterpart to {@link self::Update}, and it exists because every fact it
     * reports was already asserted somewhere and checked nowhere — the four extensions the site is
     * a fatal without are named in `composer.json`, which never runs on the server, and in
     * `test/basic_test.sh`, which runs a developer's PHP. See
     * {@link \NeuroSYS\Service\Api\HealthReport}.
     *
     * **It is a service rather than a third `update` action**, because it is not about deploying.
     * `update version` answers "did my push land" and is what a deploy ends with; this answers "is
     * this host still what I think it is", which is asked when something is wrong and nothing else
     * will say what. Two questions, two vocabularies, and an action enum each — which is exactly
     * the split {@link ApiAction} exists to make expressible.
     */
    case Health = 'health';

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
            $this === self::Health && $version === ApiVersion::V1 => HealthAction::tryFrom($action),

            // A pair nothing has wired yet, which is only reachable once a second version exists.
            // It is null rather than an unhandled match for the same reason the typo above is: a
            // version this service does not offer is an address it does not have, and answering a
            // verified caller with a 500 would report our omission as their fault.
            default => null,
        };
    }
}
