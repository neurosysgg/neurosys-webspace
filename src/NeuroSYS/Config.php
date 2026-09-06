<?php

declare(strict_types=1);

namespace NeuroSYS;

use NeuroSYS\Support\Directory;
use NeuroSYS\Support\File;

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

    /**
     * The user name on every demo gate.
     *
     * A constant rather than the demo's slug, and the difference is whoever is being sent the link:
     * they type this and paste the password, where a slug would have them retyping
     * `virtual-riot-were-not-alone-neuro-sys-bootleg` from an address bar. It is not a secret and
     * is not meant to be — the password is the whole credential. What keeps one demo's saved
     * credentials from being offered for another is the **realm**, which
     * {@link Service\Auth::requireDemoAuth()} builds per slug.
     */
    public const string DEMO_USER = 'demo';

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
     * The `data/` directory, which lives outside the webroot.
     *
     * One derivation of that path instead of seven. It is where the credentials live, so a
     * directory that resolves somewhere unexpected is not a small mistake — and
     * `PrivacyController` was reaching for it with `__DIR__ . '/../../../data/'` while everything
     * else used `dirname(__DIR__, 3)`.
     *
     * @return Directory
     */
    public static function data(): Directory
    {
        return new Directory(dirname(__DIR__, 2) . '/data');
    }

    /**
     * Resolves a file inside `data/`.
     *
     * It hands back a {@link File} rather than the string it used to, because every one of its
     * callers immediately asked the same two questions of that string — is it there, and what is in
     * it — and each of them answered in its own words. The path is still on the object, for the two
     * places that need the string itself: `require` is a language construct and takes a path, not a
     * file.
     *
     * @param string $file A path relative to `data/`, e.g. `releases.php` or `logs/downloads.log`.
     * @return File
     */
    public static function dataFile(string $file): File
    {
        return self::data()->file($file);
    }

    /**
     * The downloads log, named once because two classes reach for it.
     *
     * @return File
     */
    public static function downloadLog(): File
    {
        return self::dataFile('logs/downloads.log');
    }

    /**
     * Where one demo's audio lives: `data/demos/{slug}/`.
     *
     * **Outside the webroot, and that is the feature.** Apache serves `public/`; these files are
     * not under it, so the only route to them is {@link Controller\DemoAudioController} and the
     * only way past that is the demo's password. A release's files are on HiDrive behind a share
     * URL that outlives any password; a demo's are here.
     *
     * Derived in one place because two very different callers want it — the controller resolving a
     * request, and `tools/stage-demo.php` writing the files in the first place — and a tool that
     * staged into a directory the site does not read would be a demo page of missing audio.
     *
     * The slug is not sanitised here. It comes from a route segment, and what makes that safe is
     * that {@link Service\DemoRepository} has already matched it against a declared demo: a slug
     * that reaches this is one `data/demos.php` names.
     *
     * @param string $slug
     * @return Directory
     */
    public static function demoDir(string $slug): Directory
    {
        return self::data()->directory('demos')->directory($slug);
    }

    /**
     * The site's meta description: `neuro.SYS — electronic music.`
     *
     * @return string
     */
    public static function description(): string
    {
        return self::NAME . ' — ' . self::TAGLINE;
    }
}
