<?php

declare(strict_types=1);

namespace NeuroSYS\Exception;

use LogicException;

/**
 * The RouteException class. Thrown when a {@link \NeuroSYS\Support\SitePath} is asked for an address
 * it cannot build — a pattern given the wrong number of values for its placeholders.
 *
 * **Extends `LogicException` for the reason {@link MarkupException} does**, and it is the same
 * classification rather than a copied one: a route filled in wrongly is not a condition a request
 * put the site into, it is a line in this repository that names a path it does not have the parts
 * for. Nothing catches it and nothing should.
 *
 * A visitor's bad URL is emphatically **not** this. That is a 404, decided by
 * {@link \NeuroSYS\Router::dispatch()} finding no route that matches — and the two are kept apart on
 * purpose: one is data and answers with a page, the other is code and answers by stopping.
 */
class RouteException extends LogicException
{
}
