<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Http;

/**
 * The Transport interface. Something that can send a {@link Request} and hand back a
 * {@link Response}.
 *
 * One method, and it exists to be replaced. {@link CurlTransport} is the only implementation that
 * touches a network; a test hands the client an implementation that answers from an array and
 * records what it was asked — the same arrangement {@link \NeuroSYS\Tool\Cli\Output} has with its
 * two streams, and for the same reason: the interesting half of an API client is *what it sends*,
 * and that half cannot be asserted against a method that ends in a socket.
 *
 * It is deliberately not a general HTTP abstraction. Two shapes of request go out of this repo —
 * a form-encoded token exchange and a multipart upload — and this is the interface that carries
 * exactly those.
 */
interface Transport
{
    /**
     * Sends a request and returns what came back.
     *
     * A response is a response whatever its status: a 401 is something the caller has to read, not
     * something this may throw over. Only a transfer that never produced one — no route, no TLS, a
     * connection that died mid-body — is a {@link TransportException}.
     *
     * @param Request $request
     * @return Response
     * @throws TransportException if the request never produced a response at all.
     */
    public function send(Request $request): Response;
}
