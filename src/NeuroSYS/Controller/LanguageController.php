<?php

declare(strict_types=1);

namespace NeuroSYS\Controller;

use NeuroSYS\Http\CacheControl;
use NeuroSYS\Http\Header;
use NeuroSYS\Http\HttpStatusCode;
use NeuroSYS\Http\RedirectResponse;
use NeuroSYS\Http\Request;
use NeuroSYS\Http\Response;
use NeuroSYS\Http\ResponseHeader;
use NeuroSYS\Http\SetCookie;
use NeuroSYS\Support\Collection;
use NeuroSYS\Support\SitePath;
use NeuroSYS\Text\Language;
use NeuroSYS\View\Html\Element;
use Uri\WhatWg\Url;

/**
 * The LanguageController class. The language switch: remembers a visitor's choice, and sends them
 * back to the page they were on.
 *
 * **A GET, so a plain link can do it.** The site is read-only and has no forms; `/language/de` is an
 * address like any other, reached from the footer's switch with `data-no-spa`, so the browser loads
 * it whole and the header and footer come back in the new language as well. A third party can link
 * somebody here and switch their language, which costs them one click to switch back — and is why
 * nothing here does more than that.
 *
 * **Back is the `Referer`'s path, and only its path.** The host is dropped whatever it was, so the
 * redirect cannot leave this site; the path is then still put to
 * {@link Element::staysOnThisOrigin()}, because a path can name another host — `//evil.example`
 * resolves to one. A switch reached with no referrer, or with one refused here, goes home.
 *
 * {@link self::back()} is the decision, public so it can be asserted; {@link self::handle()} ends
 * the request with it.
 */
final readonly class LanguageController implements Controller
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $language The URL segment, which is whatever was requested and not necessarily
     *                         a {@link Language}.
     */
    public function __construct(private string $language) {}

    /**
     * @param Request $request
     * @return Response
     */
    public function handle(Request $request): Response
    {
        $language = Language::tryFrom($this->language);

        if ($language === null) {
            return new NotFoundController($request->path())->handle($request);
        }

        return new RedirectResponse(
            self::back($request->referer()),
            HttpStatusCode::SeeOther,
            new Collection(Header::class)->with(
                new Header(ResponseHeader::SetCookie, SetCookie::language($language)),
                new Header(ResponseHeader::CacheControl, CacheControl::doNotStore()),
            ),
        );
    }

    /**
     * Where a switch sends the visitor: the path of the page they came from, or home.
     *
     * Home, too, for a referrer that was itself a switch: sent back there, the browser would switch
     * again, to whichever language that one named.
     *
     * @param string $referer The raw `Referer`, or `''`.
     * @return string A path on this site.
     */
    public static function back(string $referer): string
    {
        $path = Url::parse($referer)?->getPath() ?? '';

        if ($path === '' || !Element::staysOnThisOrigin($path)) {
            return SitePath::Home->to();
        }

        foreach (Language::cases() as $language) {
            if ($path === SitePath::Language->to($language->value)) {
                return SitePath::Home->to();
            }
        }

        return $path;
    }
}
