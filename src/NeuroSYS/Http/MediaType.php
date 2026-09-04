<?php

declare(strict_types=1);

namespace NeuroSYS\Http;

/**
 * The MediaType enum. What a response body is.
 *
 * Two cases, because the site sends two kinds of body: a page and a line of plain text. The enum
 * exists for the `charset` rather than the type — that half was the part being retyped, and it is
 * the part that matters, because a browser told a document's bytes are text but not which encoding
 * has to decide for itself. `X-Content-Type-Options: nosniff` stops it guessing the *type*; nothing
 * stops it guessing the encoding.
 *
 * {@link ViewResponse} used to send no `Content-Type` at all and inherit PHP's `default_mimetype`
 * and `default_charset` ini settings, which happen to be right. That is a fact about the runtime,
 * not about this code, and it was the only response on the site whose headers were not written
 * down anywhere — awkward in particular for the AJAX fragment, which carries no `<meta charset>`
 * of its own, so the header is the only thing that says how to read it.
 */
enum MediaType: string
{
    /** A page, or the fragment of one that {@link ViewResponse} sends to the SPA router. */
    case Html = 'text/html';

    /** A 405 refusal, or a download that has no file behind it yet. */
    case PlainText = 'text/plain';

    /** The `Content-Type` value: the type, and the encoding the whole site is written in. */
    public function contentType(): string
    {
        return $this->value . '; charset=utf-8';
    }
}
