<?php

declare(strict_types=1);

namespace NeuroSYS\Support;

use Phpanta\Support\FillsPlaceholders;
use Phpanta\Support\Path;

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
 * `{slug}`, `{format}`, `{label}` and `{language}` are the placeholders, and their syntax is
 * {@link Route::matches()}'s to interpret; {@link FillsPlaceholders} only counts them. Note there
 * is no `/demos` case, and its absence is load-bearing — see {@link RouteInitialization::routes()}.
 *
 * There is no API case either: `/api/{service}/{version}/{action}` is the framework's address, and
 * {@link ApiPath} holds it.
 */
enum SitePath: string implements Path
{
    use FillsPlaceholders;

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
     * The language switch: remembers a visitor's choice in a cookie and sends them back to the
     * page they were on. A read, so a plain link can do it — see
     * {@link \NeuroSYS\Controller\LanguageController}.
     */
    case Language = '/language/{language}';
}
