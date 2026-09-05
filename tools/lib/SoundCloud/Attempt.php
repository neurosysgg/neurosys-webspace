<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\SoundCloud;

/**
 * The Attempt enum. What the client was in the middle of when the API said no.
 *
 * Four operations, and the phrase each one goes by in a failure message:
 * *"SoundCloud refused **the upload** with 422"*. It was a `string $what` threaded through two of
 * {@link Client}'s private methods and into {@link SoundCloudException::refused()}, whose docblock
 * had to explain in prose what shape of string it wanted — *"as a phrase — `the upload`"*. A
 * parameter that needs a sentence to describe its own format is a parameter with a type missing.
 *
 * **Backed by the phrase itself**, rather than by a slug with a `phrase()` method beside it. The
 * value is the only thing anything ever wants, so a second representation would be one more thing to
 * keep in step and nothing would ever read it. The definite article is part of the value for the
 * same reason: it is what the message needs, and the alternative is a `'the '` glued on at each of
 * the two places that format one.
 *
 * Exhaustive of what this client does. A case nothing attempts is an operation with nothing on the
 * other end of it — the same rule {@link TrackField} and {@link \NeuroSYS\Tool\Http\OutboundHeader}
 * are held to.
 */
enum Attempt: string
{
    /** Redeeming the code a browser redirect carried, which happens once per account. */
    case Authorization = 'the authorization';

    /** Spending a refresh token for the next one, which happens whenever a token has aged out. */
    case Refresh = 'the refresh';

    /** `POST /tracks` — the one request that puts a file on somebody else's computer. */
    case Upload = 'the upload';

    /** Reading a track back, which {@link Client::upload()} does when the creation response is thin. */
    case Track = 'the track';
}
