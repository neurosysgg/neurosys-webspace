<?php

declare(strict_types=1);

namespace NeuroSYS\Http\Api;

use NeuroSYS\Http\HttpMethod;
use NeuroSYS\Model\Api\VerifiedRequest;
use NeuroSYS\Service\Api\HealthReport;

/**
 * The HealthAction enum. What the `health` service can be asked to do.
 *
 * One case, and the enum is worth having at one case for {@link ApiVersion}'s reason: the segment
 * is matched by the router as `([^/]+)` and could otherwise be any string at all, so without a
 * vocabulary there is nothing that can say an action does not exist.
 *
 * **This is the enum that proves {@link ApiService}'s claim** — *adding a service is this file, its
 * action enum, and its handlers, and no route*. The whole of `health` is three files and one arm
 * of one `match`: no route to register, no method policy to choose, no second arrangement of the
 * gate, the silence or the serial. That claim was written when there was one service to make it
 * about, which is the kind of claim worth cashing rather than trusting.
 */
enum HealthAction: string implements ApiAction
{
    /**
     * Everything this deployment can say about itself.
     *
     * A read, deliberately, and the only kind of action `health` will ever have: a service that
     * reports is a service that changes nothing, so it consumes no serial and its credential
     * replays to another read. Its answer is
     * {@link \NeuroSYS\Service\Api\HealthReport}, which is where the argument for each fact lives.
     */
    case Report = 'report';

    /**
     * @return HttpMethod
     */
    public function method(): HttpMethod
    {
        return HttpMethod::Get;
    }

    /**
     * The handler takes nothing from the manifest, which is what a service with no parameters
     * looks like — and is why this signature declares no `@throws` where
     * {@link UpdateAction::handler()} declares one. There is no field to be missing.
     *
     * @param VerifiedRequest $verified
     * @return ApiHandler
     */
    public function handler(VerifiedRequest $verified): ApiHandler
    {
        return match ($this) {
            self::Report => new HealthReport(),
        };
    }
}
