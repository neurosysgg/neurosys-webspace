<?php

declare(strict_types=1);

namespace NeuroSYS\Http;

use NeuroSYS\Config;
use NeuroSYS\Http\Security\ContentSecurityPolicy;
use NeuroSYS\Http\Security\ContentTypeOptions;
use NeuroSYS\Http\Security\CspDirective;
use NeuroSYS\Http\Security\CspHost;
use NeuroSYS\Http\Security\CspKeyword;
use NeuroSYS\Http\Security\CspScheme;
use NeuroSYS\Http\Security\PermissionsPolicy;
use NeuroSYS\Http\Security\PermissionsPolicyFeature;
use NeuroSYS\Http\Security\ReferrerPolicy;
use NeuroSYS\Http\Security\StrictTransportSecurity;

/**
 * The SecurityHeaders class. Emits the site's response security headers.
 *
 * Sent from `public/index.php` before anything is dispatched, so they cover every response the
 * application produces — including the 401 {@link \NeuroSYS\Service\Auth} exits with, the 405
 * {@link \NeuroSYS\Router} refuses a write method with, and the 303 a download redirects with.
 *
 * Every value here is a typed object rather than a header string: see {@link CspDirective},
 * {@link CspSource}, {@link ReferrerPolicy} and {@link PermissionsPolicyFeature}. A misspelled
 * directive or an unquoted `'self'` is a parse error now, not a header the browser drops.
 *
 * Static assets are served straight by Apache and never reach PHP, so they don't get these.
 * That is fine for what `public/assets/` holds; if it ever holds something user-supplied, add
 * the headers to `public/.htaccess` behind an `<IfModule mod_headers.c>` guard instead — an
 * unguarded `Header` directive 500s the whole site where mod_headers isn't loaded.
 */
final class SecurityHeaders
{
    /** Sends every security header. Safe to call before any output. */
    public static function send(): void
    {
        foreach (self::headers() as $name => $value) {
            header(new Header(SecurityHeader::from($name), $value)->line());
        }
    }

    /**
     * Returns every header this class sends, keyed by header name.
     *
     * Public because it is the honest answer to "what does the site send?" — {@link self::send()}
     * is only the `header()` loop over it, and the test suite asserts against this rather than
     * reaching through reflection.
     *
     * @return array<string, string>
     */
    public static function headers(): array
    {
        return [
            SecurityHeader::StrictTransportSecurity->value => self::strictTransportSecurity()->render(),
            SecurityHeader::ContentSecurityPolicy->value => self::contentSecurityPolicy()->render(),
            SecurityHeader::ReferrerPolicy->value        => self::referrerPolicy()->value,
            SecurityHeader::ContentTypeOptions->value    => ContentTypeOptions::NoSniff->value,
            SecurityHeader::PermissionsPolicy->value     => self::permissionsPolicy()->render(),
        ];
    }

    /**
     * Builds the Strict-Transport-Security policy.
     *
     * The site is read-only and sets no cookie, so the thing this protects is the credentials on
     * the two Basic Auth gates — the admin one, and the pre-launch one that runs on every single
     * request. Basic is base64. Over plaintext it is readable, and the `.htaccess` redirect cannot
     * help the request that carried it. See {@link StrictTransportSecurity}, and note the ramp
     * documented on its ONE_DAY constant before raising this on an estate you have not checked.
     */
    public static function strictTransportSecurity(): StrictTransportSecurity
    {
        return new StrictTransportSecurity();
    }

    /**
     * Builds the Content-Security-Policy.
     *
     * `script-src` is strict — there are no inline handlers or inline scripts left in any view,
     * which is the directive that actually blocks XSS.
     *
     * `style-src` is strict too. It carried {@link CspKeyword::UnsafeInline} for as long as
     * SoundCloud's attribution block was reproduced as HTML with inline `style` attributes. That
     * block is built by `<soundcloud-player>` now, which sets the same properties through the
     * CSSOM — element.style, which CSP does not govern — so the styling is unchanged and the
     * allowance has nothing left to cover. Nothing else emits an inline style; a test enforces it.
     */
    public static function contentSecurityPolicy(): ContentSecurityPolicy
    {
        return new ContentSecurityPolicy()
            ->allow(CspDirective::DefaultSrc, CspKeyword::SelfOrigin)
            ->allow(CspDirective::ScriptSrc, CspKeyword::SelfOrigin)
            ->allow(CspDirective::StyleSrc, CspKeyword::SelfOrigin)
            ->allow(
                CspDirective::ImgSrc,
                CspKeyword::SelfOrigin,
                CspScheme::Data,
                new CspHost(Config::FILE_HOST),
            )
            ->allow(CspDirective::FrameSrc, new CspHost(Config::PLAYER_HOST))
            ->allow(CspDirective::BaseUri, CspKeyword::SelfOrigin)
            ->allow(CspDirective::FormAction, CspKeyword::SelfOrigin)
            ->allow(CspDirective::FrameAncestors, CspKeyword::None)
            ->allow(CspDirective::ObjectSrc, CspKeyword::None);
    }

    /**
     * A download 303 hands the release URL to HiDrive as a `Referer` otherwise, and the framed
     * player receives the full page URL once it loads. Same-origin navigation keeps the path, so
     * SPA links still work as expected.
     */
    private static function referrerPolicy(): ReferrerPolicy
    {
        return ReferrerPolicy::StrictOriginWhenCrossOrigin;
    }

    /**
     * The site asks for none of these, so all of them are denied to everyone.
     *
     * Denies every {@link PermissionsPolicyFeature} case, which makes that enum the list of
     * things the site refuses rather than a catalogue of what exists — see its docblock before
     * adding a case, because `Permissions-Policy` also applies to the SoundCloud iframe.
     */
    private static function permissionsPolicy(): PermissionsPolicy
    {
        return PermissionsPolicy::denyAll();
    }
}
