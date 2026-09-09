<?php

declare(strict_types=1);

use NeuroSYS\Exception\SiteException;
use NeuroSYS\Http\Request;
use NeuroSYS\Http\SecurityHeaders;
use NeuroSYS\Router;
use NeuroSYS\Service\Auth;
use NeuroSYS\Support\RouteInitialization;

require __DIR__ . '/../autoload.php';

/*
 * The last resort, installed before anything can need it.
 *
 * Every exception in NeuroSYS\Exception is a LogicException or close to one: "something in this
 * repository is written wrong". Nothing recovers from that and nothing should try — but "nothing
 * recovers" and "nothing catches" are different sentences, and until this handler existed the site
 * was living with the second. An uncaught throwable is a PHP fatal, which on a host whose
 * display_errors we do not own is either a blank page with a 200 already on the wire or a stack
 * trace naming absolute paths, and neither is a thing to leave to a php.ini.
 *
 * Three decisions, and each is about this being the code that runs when other code did not:
 *
 * - **It depends on nothing.** No Response, no MimeType, no view. A handler that reached for the
 *   markup tree would be reaching for the most likely thing to have just broken, and a throw inside
 *   an exception handler is a fatal with the original swallowed — strictly worse than what this
 *   replaces. `instanceof` against an interface is the one exception, and it is free: a
 *   SiteException cannot have been thrown without its class being loaded, and for anything else the
 *   answer is false either way.
 * - **The body says nothing.** No message, no class, no file, no trace. What a visitor learns is
 *   the status code; what an operator learns is in the log, which is where the difference between a
 *   fault from this repository and one from underneath it is recorded, because that is the first
 *   question either way and SiteException is the only type that can answer it.
 * - **`headers_sent()` decides whether there is anything to say.** A response that has already
 *   started cannot be given a 500, and appending to a half-written page would corrupt the half that
 *   is right. The log line has already been written by then, which is the part that matters.
 */
set_exception_handler(static function (Throwable $fault): void {
    error_log(sprintf(
        'neuro.SYS: uncaught %s %s at %s:%d — %s',
        $fault::class,
        $fault instanceof SiteException ? '(from this repository)' : '(from underneath it)',
        $fault->getFile(),
        $fault->getLine(),
        $fault->getMessage(),
    ));

    if (headers_sent()) {
        return;
    }

    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');

    echo "500\n";
});

SecurityHeaders::send();

$request = Request::fromGlobals();

Auth::requireSiteAuth($request);

new Router(RouteInitialization::routes())->dispatch($request)->send($request);
