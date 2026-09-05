<?php

declare(strict_types=1);

namespace NeuroSYS\Http;

use NeuroSYS\Layout;
use NeuroSYS\View\Html\Element;
use NeuroSYS\View\Html\Fragment;
use NeuroSYS\View\Html\HtmlTag;
use NeuroSYS\View\View;

/**
 * The ViewResponse class. Renders a {@link View} as an HTTP response.
 *
 * On AJAX requests, emits only the content fragment prefixed by a title tag.
 * On full-page requests, wraps the content in the site {@link Layout}.
 *
 * Sends its own `Content-Type` rather than leaving PHP's `default_mimetype` to supply one — see
 * {@link MimeType}. It matters most for the fragment, which declares no encoding of its own.
 *
 * **It also decides whether a document may be reused**, which is the one thing here that is not
 * simply "render and echo". See {@link self::cacheHeaders()} for why the answer is an `ETag` and
 * `no-cache` rather than a `max-age`.
 */
readonly class ViewResponse implements Response
{
    /**
     * How the body is fingerprinted for the `ETag`.
     *
     * xxh128 rather than sha256: nothing here is a security claim — the value is compared against
     * one this same code sent a moment ago, never against one an attacker chose — and this runs on
     * every page render, where a non-cryptographic hash is several times faster over the same
     * bytes. `HttpStatusCode` picking the wrong page is not a threat model, it is a collision at
     * 1 in 2^128.
     */
    private const string ETAG_ALGORITHM = 'xxh128';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param View           $view    The view to render.
     * @param HttpStatusCode $status  The HTTP status code.
     * @param list<Header>   $headers Extra headers, e.g. `Cache-Control:` on an authenticated
     *                                page. Same parameter {@link PlainTextResponse} takes, in the
     *                                same position, so the two responses are shaped alike.
     */
    public function __construct(
        private View           $view,
        private HttpStatusCode $status = HttpStatusCode::Ok,
        private array          $headers = [],
    ) {}

    /**
     * Sends the response; emits headers and rendered HTML.
     *
     * The body is rendered **before** any header goes out, which is the opposite of the order this
     * used to run in and is what makes an `ETag` possible at all: the validator is a hash of the
     * bytes, so the bytes have to exist first. Nothing is echoed until every header is sent, so
     * that reordering costs one string held in memory and nothing else.
     */
    public function send(Request $request): void
    {
        // The fragment leads with a <title> so Navigation can read the new page title out of it —
        // an element like any other, so the title is escaped by the same rule as everything else.
        $body = $request->isAjax()
            ? new Fragment(
                new Element(HtmlTag::Title)->containing($this->view->pageTitle()),
                $this->view->content(),
            )
            : Layout::wrap($this->view);

        $markup = $body->render();
        $cache  = $this->cacheHeaders($markup);

        // A validator the browser already holds means the copy it already holds is current. 304 and
        // nothing else — no Content-Type, because there is no content to describe.
        if ($cache !== [] && $request->ifNoneMatch() === self::etag($markup)) {
            http_response_code(HttpStatusCode::NotModified->value);
            self::sendAll($cache);

            return;
        }

        http_response_code($this->status->value);
        header(new Header(ResponseHeader::ContentType, MimeType::html()->render())->line());

        self::sendAll($cache);
        self::sendAll($this->headers);

        echo $markup;
    }

    /**
     * The headers that say whether this document may be reused, or `[]` if the caller already said.
     *
     * **`no-cache` is not `no-store`.** It means keep the copy and ask before reusing it, so a
     * return visit costs a round trip and no bytes — the 304 above. What it buys over a `max-age`
     * is that there is no window at all in which a visitor holds a stale document, and that matters
     * here more than it would elsewhere:
     *
     * - A document embeds every versioned asset URL — the stylesheet, the entry script and all
     *   forty-one preloads, straight out of {@link \NeuroSYS\AssetManifest}. A stale document
     *   therefore names *last build's* URLs, and `public/.htaccess` marked those `immutable` for a
     *   year, so the browser would serve the old JS out of its own cache against the new HTML.
     *   That is the mirror drift the parity tests exist to catch, arriving by the one route no test
     *   can see. A `max-age` of any size opens exactly that window.
     * - Hashing the body needs no coupling to the build stamp, because the stamp is already *in*
     *   the body. A rebuild changes the asset URLs, which changes the markup, which changes the
     *   validator. Nothing had to be wired together for that; it falls out.
     * - `data/releases.php` and `data/privacy.html` are read on every request and contribute
     *   nothing to the build stamp. Under `no-cache` an edit to either is live immediately, which
     *   keeps `docs/releases.md`'s "no cache to bust, no rebuild needed" true.
     *
     * `Vary` names `X-Requested-With` because one URL has two bodies here — see
     * {@link ResponseHeader::Vary}. The `ETag` is a second guard on the same hazard: the document
     * and the fragment are different bytes, so they cannot validate against each other even where
     * `Vary` is ignored.
     *
     * **A caller that supplied its own `Cache-Control` gets none of this**, and no 304 either.
     * That is {@link \NeuroSYS\Controller\StatsController}, which says `no-store, private` because
     * it sits behind a password; adding a validator to a response we just asked not to be stored
     * would be arguing with ourselves.
     *
     * The other responses are not this class's to answer for and deliberately carry nothing: the
     * 303 a download redirects with is logged per hit and must be re-asked every time, the 401
     * {@link \NeuroSYS\Service\Auth} exits with never becomes a `Response` at all, and the 405 and
     * 503 are {@link PlainTextResponse}.
     *
     * @return list<Header>
     */
    private function cacheHeaders(string $markup): array
    {
        foreach ($this->headers as $header) {
            if ($header->name === ResponseHeader::CacheControl) {
                return [];
            }
        }

        return [
            new Header(ResponseHeader::CacheControl, 'no-cache'),
            new Header(ResponseHeader::ETag, self::etag($markup)),
            new Header(ResponseHeader::Vary, RequestHeader::RequestedWith->value),
        ];
    }

    /** The validator for a body: a quoted hash of exactly the bytes about to be sent. */
    private static function etag(string $markup): string
    {
        return '"' . hash(self::ETAG_ALGORITHM, $markup) . '"';
    }

    /** @param list<Header> $headers */
    private static function sendAll(array $headers): void
    {
        foreach ($headers as $header) {
            header($header->line());
        }
    }
}
