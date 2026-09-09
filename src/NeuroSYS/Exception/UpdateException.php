<?php

declare(strict_types=1);

namespace NeuroSYS\Exception;

use RuntimeException;

/**
 * The UpdateException class. Thrown when a signed update payload cannot be read or cannot be
 * trusted.
 *
 * **Extends `RuntimeException` rather than `LogicException`, which is the opposite classification
 * from {@link RouteException} and deliberately so.** A route filled in wrongly is a line in this
 * repository; a malformed payload arrived over the network, from a caller this code does not
 * control. It is data, so it is a condition rather than a bug, and something does catch it —
 * {@link \NeuroSYS\Controller\UpdateController}, which turns it into a 404 before the signature is
 * verified and into a diagnostic afterwards.
 *
 * That two-sided handling is why the message matters and why it is never sent to an unverified
 * caller. Everything thrown out of {@link \NeuroSYS\Service\UpdateGate} is swallowed and answered
 * with the site's ordinary "no such path"; everything thrown past it is reported in full, because
 * by then the caller has proved it holds the private key.
 */
class UpdateException extends RuntimeException implements SiteException
{
}
