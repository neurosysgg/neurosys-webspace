<?php

declare(strict_types=1);

namespace NeuroSYS\Support;

use NeuroSYS\Exception\RouteException;

/**
 * The SitePath enum. Every address this site answers on.
 *
 * The one vocabulary here that was written in **two languages with nothing between them**: the
 * router declared `/releases/{slug}` and nine views concatenated `'/releases/' . $slug`, and the
 * only thing keeping the two in step was that the same person had written both. That is the shape
 * of drift `RequestHeader` and `Tag` exist to stop, and it was the largest one left.
 *
 * It fails silently in the direction that matters. A view naming a path the router does not have
 * gets the site's own 404 — a page, with a 404 in a log nobody reads, from a link that looks
 * perfectly fine in the markup. Rename a route and every link to it keeps rendering.
 *
 * **The value is the pattern, placeholders and all**, so this enum is what {@link Route} matches
 * with *and* what a view builds from — one fact, read from one place, in both directions. That is
 * the whole point: a pattern that is only ever half of a pair cannot drift from its other half.
 *
 * `{slug}`, `{format}` and `{label}` are the three placeholders, and their syntax is
 * {@link Route::matches()}'s to interpret; this class only counts them. Note there is no `/demos`
 * case, and its absence is load-bearing — see {@link RouteInitialization::routes()}.
 */
enum SitePath: string
{
    /** The home page. */
    case Home = '/';

    /** The catalogue. */
    case Releases = '/releases';

    /** One release. */
    case Release = '/releases/{slug}';

    /** One release's download for one format — a 303 to the file host, or a 503 if unstaged. */
    case Download = '/releases/{slug}/{format}';

    /** One demo, behind its own password. */
    case Demo = '/demos/{slug}';

    /** One mix of one demo — the audio itself, which is why it is a route and not a file URL. */
    case DemoAudio = '/demos/{slug}/{label}';

    /** The download statistics, behind the admin password. */
    case Stats = '/admin/stats';

    /** The imprint. */
    case Imprint = '/imprint';

    /** The privacy policy. */
    case Privacy = '/privacy';

    /**
     * The signed deploy endpoint — the one address here that is not a page and the one that writes.
     *
     * **It is a case for the same reason every other address is**, even though no view links to it:
     * this enum is what the router matches against, so an address that lived as a literal would be
     * a route the table did not have. That it is unreachable from the site is a property of there
     * being no `<a>` to it, not of it being spelled differently.
     *
     * Answering on it is another matter. {@link \NeuroSYS\Controller\UpdateController} replies
     * exactly as the site replies for a path no route claims, unless the request carries a
     * signature `data/update.pub` verifies — and that file is absent by default, so on a fresh
     * clone this address is a 404 and nothing else. See {@link \NeuroSYS\DataFile::UpdateKey}.
     */
    case Update = '/update';

    /**
     * This path with its placeholders filled, in declaration order.
     *
     * `SitePath::Release->to($slug)` is `/releases/ill`; `SitePath::Home->to()` is `/`.
     *
     * **Refusing the wrong number of values is what the method is for.** A concatenation cannot
     * make that check — `'/releases/' . $slug . '/'` is a perfectly good string and a URL that
     * matches nothing — so this is the one thing typing the paths buys that naming them does not.
     * It throws rather than returning null because a caller cannot do anything useful with the
     * answer: the page is already being rendered, and a link to nowhere is not a fallback.
     *
     * Each value is `rawurlencode`d. That is a no-op for every slug, format and label in `data/`
     * today — all of them match patterns narrower than the encoding cares about — and it is the
     * right answer for the first one that is not, rather than a `%` appearing in a path segment
     * where the router will read it as content.
     *
     * @param string ...$values One per placeholder, left to right.
     * @return string
     * @throws RouteException if the count does not match the placeholders.
     */
    public function to(string ...$values): string
    {
        $expected = preg_match_all(Route::PLACEHOLDER_PATTERN, $this->value);

        // Counted before anything is substituted, so both mistakes are one message. Too few would
        // otherwise leave an empty path segment and too many would go unnoticed entirely, and both
        // mean the same thing: this call site and this pattern disagree about the route's shape.
        if ($expected !== count($values)) {
            throw new RouteException(sprintf(
                "SitePath::%s takes %d value(s) for '%s', got %d.",
                $this->name,
                $expected,
                $this->value,
                count($values),
            ));
        }

        // `function` and `use (&…)`, not an arrow function: `fn()` captures by value, so each call
        // would shift a fresh copy and every placeholder would be filled with the first value.
        // `/releases/ill/ill` — a URL that is well formed, matches a route, and is the wrong page.
        //
        // No null check on the result, the way Route::matches() does not check its own: the pattern
        // is a constant and the subject is a string, so there is no failure for one to report.
        return preg_replace_callback(
            Route::PLACEHOLDER_PATTERN,
            static function () use (&$values): string {
                return rawurlencode((string) array_shift($values));
            },
            $this->value,
        );
    }
}
