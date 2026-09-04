<?php

declare(strict_types=1);

namespace NeuroSYS;

/**
 * The Config class. The facts about this particular site, rather than about any of its code.
 *
 * A deliberately narrow file, because a central bag of constants is the opposite of how everything
 * else here is arranged: a fact normally lives with the thing it describes, so its docblock can say
 * why. A constant earns a place here only by being one of three things:
 *
 * 1. **identity** — the name, handle, address and tagline this site is;
 * 2. **environment** — where the data lives, which third-party origins are reachable, what is
 *    switched on;
 * 3. **already stated twice** — a fact two files had their own copy of.
 *
 * The third is what made this worth writing. `https://my.hidrive.com` was in {@link
 * Model\Link\HiDriveLink} and again in {@link Http\SecurityHeaders}; change one and the covers keep
 * loading right up until the CSP blocks them. `https://w.soundcloud.com` was in the CSP and again in
 * `SoundCloudPlayer.ts`, in a different language. The name `neuro.SYS` was in eleven places. The
 * `data/` directory was derived seven times, two of them by a different idiom.
 *
 * Everything else stayed where it was, and should: {@link Http\Security\CspHost}'s origin pattern,
 * SoundCloud's accent and attribution styling, `Navigation`'s event name. Those mean nothing outside
 * the file that owns them, and moving them here would only make them reachable from everywhere.
 */
final class Config
{
    // ───────────────────────────── identity ─────────────────────────────

    /** The artist, and the site. Also the Basic Auth realm and every page title's suffix. */
    public const string NAME = 'neuro.SYS';

    /** The handle every platform profile uses. Mirrored client-side for the player attribution. */
    public const string HANDLE = 'neurosysgg';

    /** The contact address, in the footer, the imprint and the stems licensing note. */
    public const string EMAIL = 'neuro.sys@neurosys.gg';

    /** What the site is, in three words. The home page headline and the meta description. */
    public const string TAGLINE = 'electronic music.';

    // ───────────────────────── third-party origins ─────────────────────────

    /**
     * Where release files and cover art are hosted.
     *
     * Named once because it is a fact with two readers that fail apart: {@link
     * Model\Link\HiDriveLink} builds download URLs from it, and the CSP's `img-src` has to allow the
     * same origin or every cover is blocked with the links still perfectly valid.
     */
    public const string FILE_HOST = 'https://my.hidrive.com';

    /**
     * The SoundCloud widget origin.
     *
     * Read by the CSP's `frame-src` here and by `SoundCloudPlayer.ts` when it builds the iframe URL,
     * so it is mirrored in `assets/ts/Config.ts` and compared by the parity test. Note this is the
     * *widget* host: `soundcloud.com` itself is only ever a link target, never loaded, so it needs no
     * CSP entry and stays where it is used.
     */
    public const string PLAYER_HOST = 'https://w.soundcloud.com';

    // ───────────────────────────── assets ─────────────────────────────

    public const string STYLESHEET = '/assets/css/style.css';
    public const string SCRIPT     = '/assets/js/main.js';

    /** Shown when a release has no cover link, and as the fallback for one that fails to load. */
    public const string COVER_PLACEHOLDER = '/assets/img/cover-placeholder.svg';

    // ───────────────────────────── switches ─────────────────────────────

    /**
     * Master switch for download logging. **Deliberately off — nothing about a download is recorded.**
     *
     * The early return in {@link Service\DownloadLogger::log()} happens before the {@link
     * Service\DownloadLogEntry} is built, so the referrer is never even read. Turning this on is a
     * privacy-policy decision before it is a code one: `data/privacy.html` makes no download-tracking
     * claim, so it would have to be amended first. See CLAUDE.md.
     */
    public const bool DOWNLOAD_LOGGING = false;

    // ───────────────────────────── paths ─────────────────────────────

    /**
     * Resolves a file inside `data/`, which lives outside the webroot.
     *
     * One derivation of that path instead of seven. It is where the credentials live, so a file that
     * resolves somewhere unexpected is not a small mistake — and `PrivacyController` was reaching
     * for it with `__DIR__ . '/../../../data/'` while everything else used `dirname(__DIR__, 3)`.
     *
     * @param string $file A path relative to `data/`, e.g. `releases.php` or `logs/downloads.log`.
     */
    public static function dataPath(string $file): string
    {
        return dirname(__DIR__, 2) . '/data/' . $file;
    }

    /** The downloads log, named once because two classes reach for it. */
    public static function downloadLog(): string
    {
        return self::dataPath('logs/downloads.log');
    }

    /** The site's meta description: `neuro.SYS — electronic music.` */
    public static function description(): string
    {
        return self::NAME . ' — ' . self::TAGLINE;
    }
}
