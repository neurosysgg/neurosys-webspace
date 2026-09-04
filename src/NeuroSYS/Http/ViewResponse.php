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
 * {@link MediaType}. It matters most for the fragment, which has no `<meta charset>` in it.
 */
readonly class ViewResponse implements Response
{
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

    /** Sends the response; emits headers and rendered HTML. */
    public function send(Request $request): void
    {
        http_response_code($this->status->value);
        header(new Header(ResponseHeader::ContentType, MediaType::Html->contentType())->line());

        foreach ($this->headers as $header) {
            header($header->line());
        }

        // The fragment leads with a <title> so Navigation can read the new page title out of it —
        // an element like any other, so the title is escaped by the same rule as everything else.
        $body = $request->isAjax()
            ? new Fragment(
                new Element(HtmlTag::Title)->containing($this->view->pageTitle()),
                $this->view->content(),
            )
            : Layout::wrap($this->view);

        echo $body->render();
    }
}
