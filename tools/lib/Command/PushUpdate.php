<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Command;

use NeuroSYS\Http\Api\ApiService;
use NeuroSYS\Http\Api\ApiVersion;
use NeuroSYS\Http\Api\UpdateAction;
use NeuroSYS\Support\Collection;
use NeuroSYS\Support\Directory;
use NeuroSYS\Support\File;
use NeuroSYS\Tool\Api\PrivateKey;
use NeuroSYS\Tool\Api\SignedRequest;
use NeuroSYS\Tool\Cli\Command;
use NeuroSYS\Tool\Cli\ExitCode;
use NeuroSYS\Tool\Cli\Input;
use NeuroSYS\Tool\Cli\Option;
use NeuroSYS\Tool\Cli\Output;
use NeuroSYS\Tool\Cli\UsageException;
use NeuroSYS\Tool\Http\CurlTransport;
use NeuroSYS\Tool\Http\Transport;
use NeuroSYS\Tool\Http\TransportException;
use NeuroSYS\Tool\Http\Url;
use NeuroSYS\Tool\Update\PackedFile;
use NeuroSYS\Tool\Update\TarWriter;

/**
 * The PushUpdate command. Deploys `public/`, `src/` and `autoload.php` in one signed HTTPS request.
 *
 * **Why it exists is a measurement.** `deploy.sh` rsyncs over a GVFS SFTP mount where a single
 * `stat` costs 480 ms and walking `src/` alone costs 3.7 s; with `-c` it reads every one of 269
 * files on both sides. The same trees are 250 KB gzipped. So this is minutes against under a second,
 * and the difference is entirely round trips rather than bytes.
 *
 * **It does not replace `deploy.sh` and must not be made to.** That script still owns `data/` — 8.6
 * MB of demo audio, rsynced deliberately *without* `--delete` because `demos.php` and `demos/` are
 * gitignored and a mirror from a clone that has never staged a demo would take every demo off the
 * server. It is also the recovery path: a push that breaks `src/` breaks the endpoint that would fix
 * it, and the way back is the mount.
 *
 * It ships the **prod** tree, the same one `deploy.sh` does — `build/dist/public/` rather than
 * `public/` — so what lands is bundled and minified with no source maps, and the manifest goes with
 * it. Building is the caller's job: `npm run build:prod` first, exactly as the script does.
 */
final readonly class PushUpdate implements Command
{
    /**
     * Which deployment a push goes to unless `--url` says otherwise.
     *
     * An **origin**, not an endpoint, which is what changed when `/update` became
     * `/api/update/v1/patch`. The path is {@link SignedRequest}'s to compute from the typed action,
     * so there is one place that knows the address and it is the same {@link \NeuroSYS\Support\SitePath}
     * case the router matches with. A full endpoint here would be that address written twice.
     */
    private const string DEFAULT_BASE = 'https://neurosys.gg';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param Transport|null $transport A test seam; production sends over curl.
     */
    public function __construct(private ?Transport $transport = null) {}

    /**
     * @return string
     */
    public function name(): string
    {
        return 'push-update';
    }

    /**
     * @return string
     */
    public function usage(): string
    {
        return '[--dry-run] [--no-mirror] [--url <origin>] [--key <file>]';
    }

    /**
     * @return string
     */
    public function description(): string
    {
        return 'Deploy public/, src/ and autoload.php in one signed request.';
    }

    /**
     * @return list<Option>
     */
    public function options(): array
    {
        return PushUpdateOption::cases();
    }

    /**
     * @param Input $input
     * @param Output $output
     * @return ExitCode
     */
    public function run(Input $input, Output $output): ExitCode
    {
        $dist = new Directory(dirname(__DIR__, 3) . '/build/dist');

        if (!$dist->directory('public')->exists()) {
            $output->error("build/dist/ is not there — run `npm run build:prod` first.\n");
            return ExitCode::Failure;
        }

        $dryRun = $input->has(PushUpdateOption::DryRun);
        $files  = $this->files($dist);
        $base   = $input->value(PushUpdateOption::Url) ?? self::DEFAULT_BASE;

        // A missing or unusable key is an ordinary mistake and reads as one. Runner only catches a
        // UsageException around argument parsing — by design, since a command's run() answers with
        // an ExitCode — so it is caught here rather than escaping as a stack trace.
        try {
            $archive = $this->archive($files);
            $request = SignedRequest::build(
                new Url($base),
                ApiService::Update,
                ApiVersion::V1,
                UpdateAction::Patch,
                $archive,
                // The flags are negative and the manifest's fields are positive, which is the one
                // inversion in this command: `--dry-run` means `apply: false`.
                ['apply' => !$dryRun, 'mirror' => !$input->has(PushUpdateOption::NoMirror)],
                PrivateKey::fromFile($this->key($input)),
            );
        } catch (UsageException $exception) {
            $output->error($this->name() . ': ' . $exception->getMessage() . "\n");

            return ExitCode::Usage;
        }

        $output->error(sprintf(
            "%s %d files, %s archive → %s\n",
            $dryRun ? 'dry run:' : 'pushing',
            $files->count(),
            $this->humanised(strlen($archive)),
            $request->url->render(),
        ));

        try {
            $response = ($this->transport ?? new CurlTransport())->send($request);
        } catch (TransportException $exception) {
            $output->error($this->name() . ': ' . $exception->getMessage() . "\n");

            return ExitCode::Failure;
        }

        $output->out($response->body);

        if (!$response->isOk()) {
            // 404 and 405 are the same answer wearing two faces: /api replies exactly as the site
            // replies for an address that does not exist, which is a 404 for a read method and a
            // 405 for a write one — and a push is a POST. Either means the request was not
            // verified, and the endpoint deliberately will not say which check failed.
            $unverified = $response->status === 404 || $response->status === 405;

            $output->error(sprintf(
                "\nrefused with %d.%s\n",
                $response->status,
                $unverified
                    ? "\n  That is what /api answers to anything it will not verify — it does not"
                    . " say which check failed, by design. In order of likelihood:\n"
                    . "    1. data/update.pub on the server does not match this private key\n"
                    . "    2. this machine's clock is more than five minutes from the server's\n"
                    . "    3. this exact payload was already applied (rebuild to mint a new serial)\n"
                    . "    4. the server is older than /api and still answers on /update"
                    : '',
            ));

            return ExitCode::Failure;
        }

        return ExitCode::Success;
    }

    /**
     * Everything a push carries, named as the server expects.
     *
     * @param Directory $dist
     * @return Collection<PackedFile>
     */
    private function files(Directory $dist): Collection
    {
        $repository = new Directory(dirname(__DIR__, 3));

        // src/ comes from build/dist where it differs and from the working tree otherwise, which is
        // the one file deploy.sh also overlays: AssetManifest.php is stamped for the minified bytes
        // rather than the readable ones. Taking dist's copy last is what makes it win.
        $files = TarWriter::tree($dist->directory('public'), 'public')
            ->with(...TarWriter::tree($repository->directory('src'), 'src')->toValues())
            ->with(...TarWriter::tree($dist->directory('src'), 'src')->toValues());

        return $files->with(new PackedFile(
            'autoload.php',
            (string) $repository->file('autoload.php')->read(),
        ));
    }

    /**
     * The payload: every file, tarred and gzipped.
     *
     * The body of the request and nothing else — where its predecessor framed a manifest and a
     * signature in front of these bytes, both of those now ride in the `Authorization` header and
     * the body is exactly what it claims to be. That is what lets a read be signed the same way
     * with no body at all.
     *
     * @param Collection<PackedFile> $files
     * @return string
     *
     * @throws UsageException if the archive cannot be compressed.
     */
    private function archive(Collection $files): string
    {
        $archive = gzencode(TarWriter::pack($files), 9);

        if ($archive === false) {
            throw new UsageException('could not gzip the archive');
        }

        return $archive;
    }

    /**
     * The private key, from `--key` or from the default under `$HOME`.
     *
     * @param Input $input
     * @return File
     *
     * @throws UsageException if `--key` was not given and `$HOME` is not set.
     */
    private function key(Input $input): File
    {
        $given = $input->value(PushUpdateOption::Key);
        if ($given !== null) {
            return new File($given);
        }

        $home = getenv('HOME');
        if (!is_string($home) || $home === '') {
            throw new UsageException('HOME is not set, so --key must name the private key.');
        }

        return new File($home . '/' . PrivateKey::DEFAULT_PATH);
    }

    /**
     * A byte count a person can read.
     *
     * @param int $bytes
     * @return string
     */
    private function humanised(int $bytes): string
    {
        return $bytes < 1024 ? $bytes . ' B' : sprintf('%.1f KB', $bytes / 1024);
    }
}
