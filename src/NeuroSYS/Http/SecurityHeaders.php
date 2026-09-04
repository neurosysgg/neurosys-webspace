<?php

declare(strict_types=1);

namespace NeuroSYS\Http;

/**
 * The SecurityHeaders class. Emits the site's response headers.
 *
 * Sent from `public/index.php` before anything is dispatched, so they cover every response
 * the application produces — including the 401 {@link \NeuroSYS\Service\Auth} exits with and
 * the 303 a download redirects with.
 *
 * Static assets are served straight by Apache and never reach PHP, so they don't get these.
 * That is fine for what `public/assets/` holds; if it ever holds something user-supplied,
 * add the headers to `public/.htaccess` behind an `<IfModule mod_headers.c>` guard instead —
 * an unguarded `Header` directive 500s the whole site where mod_headers isn't loaded.
 */
final class SecurityHeaders
{
    /**
     * The hosts the site is allowed to pull from, beyond its own origin.
     *
     * Cover art is served by HiDrive; the SoundCloud player is framed, but only after the
     * visitor clicks the consent gate. Nothing else may load, so a stray CDN reference in a
     * future edit fails visibly in the console rather than quietly phoning home.
     */
    private const string COVER_HOST  = 'https://my.hidrive.com';
    private const string PLAYER_HOST = 'https://w.soundcloud.com';

    /** Sends every security header. Safe to call before any output. */
    public static function send(): void
    {
        // A download redirect leaks the release URL to the file host as a Referer otherwise,
        // and the framed player would receive the full page URL once it loads.
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Content-Type-Options: nosniff');
        header('Permissions-Policy: geolocation=(), camera=(), microphone=(), payment=(), interest-cohort=()');
        header('Content-Security-Policy: ' . self::policy());
    }

    /**
     * Builds the Content-Security-Policy.
     *
     * `script-src 'self'` is strict — there are no inline handlers or inline scripts left in
     * any view, which is the directive that actually blocks XSS.
     *
     * `style-src` keeps `'unsafe-inline'`, deliberately: {@link \NeuroSYS\Model\Embed\SoundCloudEmbed}
     * reproduces SoundCloud's attribution markup verbatim, inline `style` attributes and all, and
     * that markup is injected once the consent gate is clicked. Dropping the allowance would mean
     * rewriting their furniture, which is exactly what that class exists not to do. Our own markup
     * carries no inline styles, so this covers only the reproduced block.
     */
    private static function policy(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: " . self::COVER_HOST,
            'frame-src ' . self::PLAYER_HOST,
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "object-src 'none'",
        ]);
    }
}
