<?php

declare(strict_types=1);

namespace NeuroSYS\Http;

/**
 * The RequestHeader enum. The request headers this site reads.
 *
 * {@link ResponseHeader} is the other direction; both are {@link HeaderName}s, so {@link Header}
 * formats either.
 */
enum RequestHeader: string implements HeaderName
{
    /**
     * Set by `Navigation` on its fetches, and the whole signal for a fragment response.
     *
     * If this name drifts on either side the server answers a SPA fetch with a full document and
     * `Navigation` writes `<!DOCTYPE html><html>…` into `<main>` — a page broken in a way nothing
     * reports. `assets/ts/model/RequestHeader.ts` mirrors it and the parity test compares them.
     */
    case RequestedWith = 'X-Requested-With';

    /**
     * The validator a browser sends back to ask "is my copy still good?".
     *
     * The one case here **no client code writes** — the browser adds it on its own from the
     * {@link ResponseHeader::ETag} it was given, and {@link ViewResponse} answers a match with a
     * 304. It is mirrored in `assets/ts/model/RequestHeader.ts` all the same, because that mirror
     * is compared case for case and in order; a case with no reader on one side is the same
     * arrangement as {@link ResponseHeader::PoweredBy}, which names a header the site never sends,
     * and {@link \NeuroSYS\Model\Embed\EmbedAttribute::Loaded}, which no view may emit.
     *
     * Naming it is the point. The alternative is reading `HTTP_IF_NONE_MATCH` off `$_SERVER` as a
     * bare string, which is the thing this enum exists to stop.
     */
    case IfNoneMatch = 'If-None-Match';

    /**
     * Which bytes of a file the client wants, when it does not want all of them.
     *
     * Read only by {@link FileResponse}, which is the only response here with a file behind it.
     * The reason it exists is seeking: an `<audio>` element asks for a range when the scrubber is
     * dragged, so a server that ignores this plays a demo perfectly and refuses to skip — a broken
     * control with nothing in the console about it.
     *
     * Mirrored in `assets/ts/model/RequestHeader.ts` with no reader on that side, the same
     * arrangement as {@link self::IfNoneMatch} above: the parity test compares the two case for
     * case, so a case existing on one side only is what fails.
     */
    case Range = 'Range';

    /**
     * @return string
     */
    public function headerName(): string
    {
        return $this->value;
    }
}
