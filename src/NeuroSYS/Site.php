<?php

declare(strict_types=1);

namespace NeuroSYS;

use NeuroSYS\Controller\NotFoundController;
use NeuroSYS\Model\Embed\EmbedAttribute;
use NeuroSYS\Model\Embed\SoundCloudPlayerAttribute;
use NeuroSYS\Support\RouteInitialization;
use NeuroSYS\View\Html\ArrangementAttribute;
use NeuroSYS\View\Html\CardAttribute;
use NeuroSYS\View\Html\CoverArtAttribute;
use NeuroSYS\View\Html\Tag;
use NeuroSYS\View\Html\WaveformAttribute;
use NeuroSYS\View\Terminal\TerminalAttribute;
use Phpanta\App;
use Phpanta\DataFileName;
use Phpanta\Http\Request;
use Phpanta\Http\Response;
use Phpanta\Http\Security\CspDirective;
use Phpanta\Http\Security\CspHost;
use Phpanta\Http\Security\CspSource;
use Phpanta\Support\Collection;
use Phpanta\Support\Directory;
use Phpanta\Support\File;
use Phpanta\Support\Route;
use Phpanta\Text\Language;
use Phpanta\Text\Languages;
use Phpanta\View\Html\Vocabulary;
use Phpanta\View\Shell;

/**
 * The Site class. This site, as the {@link App} the framework runs, and the facts about it.
 *
 * A deliberately narrow file, because a central bag of constants is the opposite of how everything
 * else here is arranged: a fact normally lives with the thing it describes, so its docblock can say
 * why. A constant earns a place here only by being one of three things:
 *
 * 1. **identity** — the name, handle and address this site is (its tagline is words, and words
 *    are in the catalog — see {@link Text\LayoutText::Tagline});
 * 2. **environment** — which third-party origins are reachable, what is switched on;
 * 3. **already stated twice** — a fact two files had their own copy of.
 *
 * The third is what made this worth writing. `https://my.hidrive.com` was in {@link
 * Model\Link\HiDriveLink} and again in {@link Http\SecurityHeaders}; change one and the covers keep
 * loading right up until the CSP blocks them. `https://w.soundcloud.com` was in the CSP and again in
 * `SoundCloudPlayer.ts`, in a different language. The name `neuro.SYS` was in eleven places. The
 * `data/` directory was derived seven times, two of them by a different idiom.
 *
 * **Identity stays constant, not a method on the booted app**, so a `php -r` line and the
 * client's parity test can read `Site::NAME` without booting anything. Paths are methods, because
 * they hang off {@link self::above()}, which is where this file sits rather than a fact about it.
 *
 * Everything else stayed where it was, and should: {@link Http\Security\CspHost}'s origin pattern,
 * SoundCloud's accent and attribution styling, `Navigation`'s event name. Those mean nothing outside
 * the file that owns them, and moving them here would only make them reachable from everywhere.
 */
final class Site extends App
{
    // ───────────────────────────── identity ─────────────────────────────

    /** The artist, and the site. Also the Basic Auth realm and every page title's suffix. */
    public const string NAME = 'neuro.SYS';

    /** The handle every platform profile uses. Mirrored client-side for the player attribution. */
    public const string HANDLE = 'neurosysgg';

    /** The contact address, in the footer, the imprint and the stems licensing note. */
    public const string EMAIL = 'neuro.sys@neurosys.gg';

    /**
     * The user name on every demo gate.
     *
     * A constant rather than the demo's slug, and the difference is whoever is being sent the link:
     * they type this and paste the password, where a slug would have them retyping
     * `virtual-riot-were-not-alone-neuro-sys-bootleg` from an address bar. It is not a secret and
     * is not meant to be — the password is the whole credential. What keeps one demo's saved
     * credentials from being offered for another is the **realm**, which
     * {@link Service\DemoGate::requireAuth()} builds per slug.
     */
    public const string DEMO_USER = 'demo';

    /**
     * Where this site is served, as an origin. Where a signed call or a push goes unless told
     * otherwise, and the address a staged demo's link is written with.
     */
    public const string ORIGIN = 'https://neurosys.gg';

    /**
     * Where the update key's private half lives, relative to `$HOME` — outside the repository
     * entirely, the way the SoundCloud refresh token is. The public half is `data/update.pub`.
     */
    public const string UPDATE_KEY = '.config/neurosys/update.key';

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
     * Read by the CSP's `frame-src` and by `SoundCloudPlayer.ts` when it builds the iframe URL, so
     * it is mirrored in `assets/ts/Config.ts` and compared by the parity test. Note this is the
     * *widget* host: `soundcloud.com` itself is only ever a link target, never loaded, so it needs no
     * CSP entry and stays where it is used.
     */
    public const string PLAYER_HOST = 'https://w.soundcloud.com';

    // ───────────────────────────── assets ─────────────────────────────

    /**
     * Where the stylesheet and the entry script live, before the build stamps a version into them.
     *
     * Read by nothing that serves a page — {@link AssetManifest} is what `Layout` emits, stamp and
     * all. These are the other half of a mirror: the build tool writes `/assets/css` and
     * `/assets/js` into the manifest in a different language, and `ViewTest` holds the two to the
     * same paths, so a base changed on one side fails there rather than as a 404 on every page.
     */
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
     * privacy-policy decision before it is a code one: the policy makes no download-tracking
     * claim, so it would have to be amended first. See CLAUDE.md.
     */
    public const bool DOWNLOAD_LOGGING = false;

    // ───────────────────────────── the app ─────────────────────────────

    /**
     * @return string
     */
    public function name(): string
    {
        return self::NAME;
    }

    /**
     * Two levels above this file: `src/NeuroSYS/Site.php` → the repository root locally, `cgi-bin/`
     * on Strato. The same directory `autoload.php` sits in, which `AppTest` holds it to.
     *
     * @return Directory
     */
    public function above(): Directory
    {
        return new Directory(dirname(__DIR__, 2));
    }

    /**
     * @return Collection<Route>
     */
    public function routes(): Collection
    {
        return RouteInitialization::routes();
    }

    /**
     * English first — the site's own language, and the answer to a visitor who asks for neither —
     * then German, which the legal pages are obliged to be in.
     *
     * @return Languages
     */
    public function languages(): Languages
    {
        return new Languages(Language::English, Language::German);
    }

    /**
     * @return Shell
     */
    public function shell(): Shell
    {
        return new Layout();
    }

    /**
     * The standard vocabulary, this site's custom elements, and the attributes they read.
     *
     * @return Vocabulary
     */
    public function vocabulary(): Vocabulary
    {
        return Vocabulary::standard()
            ->withTags(Tag::class)
            ->withAttributes(
                ArrangementAttribute::class,
                CardAttribute::class,
                CoverArtAttribute::class,
                EmbedAttribute::class,
                SoundCloudPlayerAttribute::class,
                TerminalAttribute::class,
                WaveformAttribute::class,
            );
    }

    /**
     * The two third-party origins the site loads from: covers from the file host, and the player's
     * iframe from SoundCloud's widget host. Each is also read by the code that builds its URLs,
     * which is why they are constants above rather than written out here.
     *
     * @param CspDirective $directive
     * @return Collection<CspSource>
     */
    public function contentHosts(CspDirective $directive): Collection
    {
        return new Collection(CspSource::class)->with(...match ($directive) {
            CspDirective::ImgSrc   => [new CspHost(self::FILE_HOST)],
            CspDirective::FrameSrc => [new CspHost(self::PLAYER_HOST)],
            default                => [],
        });
    }

    /**
     * The entry script's stamped URL: the stamp is a hash over every module and the stylesheet, so
     * it changes exactly when a build does.
     *
     * @return string
     */
    public function buildId(): string
    {
        return AssetManifest::SCRIPT;
    }

    /**
     * The site's own 404 page, for the path that was asked.
     *
     * @param Request $request
     * @return Response
     */
    public function notFound(Request $request): Response
    {
        return new NotFoundController($request->path())->handle($request);
    }

    /**
     * @return Collection<DataFileName>
     */
    protected function ownDataFiles(): Collection
    {
        return new Collection(DataFileName::class)->with(...DataFile::cases());
    }

    // ───────────────────────────── the site's own paths ─────────────────────────────

    /**
     * The downloads log, named once because two classes reach for it.
     *
     * @return File
     */
    public function downloadLog(): File
    {
        return $this->dataFile(DataFile::DownloadLog);
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
    public function demoDir(string $slug): Directory
    {
        return $this->data()->directory('demos')->directory($slug);
    }
}
