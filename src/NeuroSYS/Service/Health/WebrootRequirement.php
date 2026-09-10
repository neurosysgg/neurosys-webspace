<?php

declare(strict_types=1);

namespace NeuroSYS\Service\Health;

use NeuroSYS\Config;
use NeuroSYS\Exception\UpdateException;
use NeuroSYS\Http\ServerVariable;
use NeuroSYS\Model\Health\Area;
use NeuroSYS\Model\Health\Finding;
use NeuroSYS\Model\Health\Level;
use NeuroSYS\Model\Health\Requirement;

/**
 * The WebrootRequirement class. `DOCUMENT_ROOT` resolves to a webroot inside this deployment.
 *
 * **A requirement of this site's rather than of the core's**, which is why it lives here and not
 * under `Model\Health`: it asks {@link Config::webroot()}, and nothing in the core may know this
 * site's `Config`. It is also the first use of the extension point by the code that defines it —
 * see {@link Requirement}.
 *
 * Required, because a push mirrors into this directory: a deployment whose webroot will not resolve
 * cannot be updated over `/api` at all, and has to be fixed with `deploy.sh`.
 *
 * **The refusal is caught and becomes the finding**, which is the contract {@link Requirement}
 * states. `Config::webroot()` refuses with an {@link UpdateException}, and left alone that would
 * reach {@link \NeuroSYS\Controller\ApiController}'s catch and turn the whole report into a 422. A
 * health check that will not report because something is unhealthy is not a health check; the
 * refusal's own sentence is the most useful thing on this line.
 */
final readonly class WebrootRequirement implements Requirement
{
    /**
     * Named for the variable rather than for the directory, because the variable is what is
     * checked: the webroot is what it resolves to, and the finding says which.
     *
     * @return string
     */
    public function name(): string
    {
        return ServerVariable::DocumentRoot->value;
    }

    /**
     * @return Area
     */
    public function area(): Area
    {
        return Area::Deployment;
    }

    /**
     * @return Level
     */
    public function level(): Level
    {
        return Level::Required;
    }

    /**
     * @return string
     */
    public function expected(): string
    {
        return 'a directory inside this deployment';
    }

    /**
     * @return Finding
     */
    public function check(): Finding
    {
        try {
            return new Finding(Config::webroot()->path, true);
        } catch (UpdateException $refusal) {
            return new Finding($refusal->getMessage(), false);
        }
    }
}
