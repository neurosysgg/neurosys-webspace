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

    public function headerName(): string
    {
        return $this->value;
    }
}
