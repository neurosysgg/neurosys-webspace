<?php
declare(strict_types=1);

namespace NeuroSYS\Http;

use NeuroSYS\Layout;
use NeuroSYS\View\View;

/**
 * The ViewResponse class. Renders a {@link View} as an HTTP response.
 *
 * On AJAX requests, emits only the content fragment prefixed by a title tag.
 * On full-page requests, wraps the content in the site {@link Layout}.
 */
readonly class ViewResponse implements Response
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param View           $view   The view to render.
     * @param HttpStatusCode $status The HTTP status code.
     */
    public function __construct(
        private View           $view,
        private HttpStatusCode $status = HttpStatusCode::Ok,
    ) {}

    /** Sends the response; emits headers and rendered HTML. */
    public function send(Request $request): void
    {
        http_response_code($this->status->value);

        if ($request->isAjax()) {
            echo '<title>' . htmlspecialchars($this->view->pageTitle()) . '</title>';
            echo $this->view->content();
        } else {
            echo Layout::wrap($this->view);
        }
    }
}
