<?php

declare(strict_types=1);

namespace NeuroSYS\Controller;

use NeuroSYS\Http\Allow;
use NeuroSYS\Http\Header;
use NeuroSYS\Http\HttpStatusCode;
use NeuroSYS\Http\PlainTextResponse;
use NeuroSYS\Http\Request;
use NeuroSYS\Http\Response;
use NeuroSYS\Http\ResponseHeader;
use NeuroSYS\Support\Collection;

/**
 * The UnroutedController class. What this site says about an address it does not have: the rendered
 * 404 for a method that reads, the `text/plain` 405 for one that writes.
 *
 * **It exists because two places have to give that answer identically.** {@link \NeuroSYS\Router}
 * gives it when no route matches a path, and {@link ApiController} gives it when a request
 * carries no signature this deployment can verify — because the whole design of `/api` is that
 * an unsigned caller cannot tell it from a typo. Written out twice, those two would be equal on the
 * day they were written and free to drift after: a message reworded, a header added, and the update
 * endpoint starts announcing itself by being subtly different from every other 404 on the site.
 *
 * That is the same failure this codebase names everywhere else — two halves in two files, neither
 * knowing about the other — so the fix is the usual one. There is one answer and one class holding
 * it, and the difference between an address that does not exist and one that is merely hiding is
 * then not expressible.
 *
 * **The 405 is deliberately the read-only `Allow`, even when the route that delegated here accepts
 * POST.** A 405 saying `Allow: GET, HEAD, POST` under `/api` would tell an unsigned caller exactly
 * what it is not allowed to know. What this sends is what the site sends for `/no-such-page`,
 * because that is what the caller is being told the address is.
 */
final readonly class UnroutedController implements Controller
{
    /**
     * The body of every 405 this site sends.
     *
     * A constant because {@link \NeuroSYS\Router} sends one too, for the other 405 — a path a
     * route *did* claim with a method it does not accept — and those two bodies being the same
     * sentence is the whole reason a caller cannot tell the two situations apart. The `Allow`
     * headers differ, correctly; the body must not.
     */
    public const string REFUSAL = "This site is read-only.\n";

    /**
     * @param Request $request
     * @return Response
     */
    public function handle(Request $request): Response
    {
        if ($request->isReadOnly()) {
            return new NotFoundController($request->path())->handle($request);
        }

        return new PlainTextResponse(
            HttpStatusCode::MethodNotAllowed,
            self::REFUSAL,
            new Collection(Header::class)->with(new Header(ResponseHeader::Allow, Allow::readOnly())),
        );
    }
}
