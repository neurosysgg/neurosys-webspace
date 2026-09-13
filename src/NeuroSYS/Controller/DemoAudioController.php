<?php

declare(strict_types=1);

namespace NeuroSYS\Controller;

use NeuroSYS\Service\DemoGate;
use NeuroSYS\Service\DemoRepository;
use NeuroSYS\Site;
use NeuroSYS\View\NotFoundView;
use Phpanta\Controller\Controller;
use Phpanta\Http\FileResponse;
use Phpanta\Http\Header;
use Phpanta\Http\HttpStatusCode;
use Phpanta\Http\MimeType;
use Phpanta\Http\Request;
use Phpanta\Http\Response;
use Phpanta\Http\ResponseHeader;
use Phpanta\Http\RobotsPolicy;
use Phpanta\Http\ViewResponse;
use Phpanta\Support\Collection;

/**
 * The DemoAudioController class. Serves one mix of one demo, behind that demo's password.
 *
 * This is where the demo gate stops being about a page. A release's audio is on HiDrive and a
 * download is a 303 to a share URL that anyone can forward and that outlives any password change; a
 * demo's audio is under `data/`, which Apache does not serve, so **this controller is the only path
 * to those bytes and it asks for the password first**. That is the whole difference between the two
 * halves of the catalogue, and it is why {@link FileResponse} exists at all.
 *
 * **Nothing here builds a path out of the request.** The URL's last segment is matched against the
 * labels the demo already declares; a segment naming no label is a null and a 404. So there is no
 * arrangement of dots and slashes in a URL that names a file — {@link \NeuroSYS\Model\DemoTrack}'s
 * check on its own file name is the second guard on that, for a typo in `data/demos.php` rather
 * than for anything a visitor can send.
 */
readonly class DemoAudioController implements Controller
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $slug  The demo's slug.
     * @param string $label The track label from the URL, which is whatever was requested and not
     *                      necessarily one this demo has.
     * @param DemoRepository|null $demos The catalogue to read, or null for the canonical one.
     *                                   Only tests pass this.
     */
    public function __construct(
        private string $slug,
        private string $label,
        private ?DemoRepository $demos = null,
    ) {}

    /**
     * @param Request $request
     * @return Response
     */
    public function handle(Request $request): Response
    {
        $demo = DemoGate::enter(
            $request,
            $this->slug,
            ($this->demos ?? new DemoRepository())->find($this->slug),
        );

        if ($demo instanceof Response) {
            return $demo;
        }

        $track = $demo->findTrack($this->label);

        // Past the gate, so a 404 here reveals nothing a listener does not already have: they hold
        // this demo's password and can read every label off its page.
        if ($track === null) {
            return new ViewResponse(new NotFoundView($request->path()), HttpStatusCode::NotFound);
        }

        $file = Site::current()->demoDir($this->slug)->file($track->file);

        // A track declared with no file behind it is a staging mistake rather than a half-state the
        // model is meant to carry — unlike a release Format, which is deliberately allowed to exist
        // before its upload does. `tools/stage-demo.php` writes the entry and the audio in one go,
        // so the two disagreeing means something was moved by hand.
        if (!$file->exists()) {
            return new ViewResponse(new NotFoundView($request->path()), HttpStatusCode::NotFound);
        }

        return new FileResponse(
            $file,
            MimeType::forAudio($file->extension()),
            new Collection(Header::class)->with(new Header(ResponseHeader::Robots, RobotsPolicy::hide())),
        );
    }
}
