<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Command;

use NeuroSYS\Exception\ReleaseVerificationException;
use NeuroSYS\Model\Embed\SoundCloudEmbed;
use NeuroSYS\Support\File;
use NeuroSYS\Tool\Cli\Command;
use NeuroSYS\Tool\Cli\ExitCode;
use NeuroSYS\Tool\Cli\Input;
use NeuroSYS\Tool\Cli\Output;
use NeuroSYS\Tool\Cli\Runner;
use NeuroSYS\Tool\Export\ExportException;
use NeuroSYS\Tool\Export\ExportedAudio;
use NeuroSYS\Tool\Export\Exporter;
use NeuroSYS\Tool\Export\PreparedExport;
use NeuroSYS\Tool\Export\RenderFormat;
use NeuroSYS\Tool\Http\CurlTransport;
use NeuroSYS\Tool\Http\FilePart;
use NeuroSYS\Tool\Http\TransportException;
use NeuroSYS\Tool\Release\EntryWriter;
use NeuroSYS\Tool\Release\Finding;
use NeuroSYS\Tool\Release\Level;
use NeuroSYS\Tool\Release\Preflight;
use NeuroSYS\Tool\Release\ReleaseFolder;
use NeuroSYS\Tool\Release\ReleasesFile;
use NeuroSYS\Tool\SoundCloud\Authorization;
use NeuroSYS\Tool\SoundCloud\Client;
use NeuroSYS\Tool\SoundCloud\CredentialVariable;
use NeuroSYS\Tool\SoundCloud\Credentials;
use NeuroSYS\Tool\SoundCloud\SoundCloudException;
use NeuroSYS\Tool\SoundCloud\TokenStore;
use NeuroSYS\Tool\SoundCloud\TrackUpload;
use NeuroSYS\Tool\SoundCloud\UploadedTrack;

/**
 * The ReleaseTrack command. Gets the audio, puts it on SoundCloud, and prints the finished entry.
 *
 * It is `stage-release` with the last hole filled. That command emits an entry whose `embed:` line
 * is commented out, because *"SoundCloud ids exist only once the track is uploaded"* — this is the
 * command that uploads it, so the ids come back from the API and the entry it prints has them in.
 *
 * Three steps, and each one refuses rather than guesses:
 *
 * 1. **Read and judge the folder**, exactly as `stage-release` does — same {@link ReleaseFolder},
 *    same {@link Preflight}. A folder that fails a check is not one to be uploading from: an upload
 *    is bound to the bytes it was made from, the same way a HiDrive share link is.
 * 2. **Get the audio** through an {@link Exporter}. Today that is {@link PreparedExport} — a file
 *    already on disk — and the report says so in its own column, because
 *    {@link \NeuroSYS\Tool\Export\FlStudioExport} cannot run yet and a report that stayed quiet
 *    about it would read as though it had.
 * 3. **Upload, but only when told to.** Everything up to this point reads files on this machine;
 *    `--upload` is the step that puts one on somebody else's, so it is a flag rather than a
 *    default. Without it the command prints every field it would send and sends nothing.
 *
 * The report goes to **stderr** and the entry to **stdout**, the same split `stage-release` has.
 *
 * The three constructor arguments are seams for the tests, and are null everywhere else — a
 * command's real collaborators are a network, a token file and a folder full of audio, and none of
 * those belongs in a unit test. Same reason {@link Output} takes its two streams.
 */
final readonly class ReleaseTrack implements Command
{
    /**
     * What a track is uploaded as.
     *
     * WAV rather than MP3 because SoundCloud transcodes what it is given and does it once; handing
     * it a lossy file means transcoding a transcode. {@link PreparedExport} falls back to the FLAC
     * master where a folder has no WAV, which is lossless too.
     */
    private const RenderFormat FORMAT = RenderFormat::Wav;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param Client|null   $client   Built from the environment when null.
     * @param Exporter|null $exporter Falls back to {@link PreparedExport}, honouring `--audio`.
     * @param resource|null $stdin    Where `--authorize` reads the redirected URL from.
     */
    public function __construct(
        private ?Client   $client = null,
        private ?Exporter $exporter = null,
        private mixed     $stdin = null,
    ) {}

    /**
     * @return string
     */
    public function name(): string
    {
        return 'release-track';
    }

    /**
     * @return string
     */
    public function usage(): string
    {
        return '<folder> [--audio <file>] [--project <file>] [--upload] | --authorize';
    }

    /**
     * @return string
     */
    public function description(): string
    {
        return 'Upload a release to SoundCloud and print its entry with the ids filled in.';
    }

    /**
     * @return list<ReleaseTrackOption>
     */
    public function options(): array
    {
        return ReleaseTrackOption::cases();
    }

    /**
     * @param Input  $input
     * @param Output $output
     * @return ExitCode
     */
    public function run(Input $input, Output $output): ExitCode
    {
        // Before the folder, because authorizing is about the account and not about a release.
        if ($input->has(ReleaseTrackOption::Authorize)) {
            return $this->authorize($output);
        }

        $path = $input->operand(0);

        if ($path === null) {
            $output->error(Runner::usage($this));

            return ExitCode::Usage;
        }

        if (!is_dir($path)) {
            $output->error(sprintf("%s: '%s' is not a folder\n", $this->name(), $path));

            return ExitCode::Usage;
        }

        $folder   = ReleaseFolder::at($path, $input->value(ReleaseTrackOption::Project));
        $findings = Preflight::check($folder);

        $output->error(sprintf("\n%s\n\n", $folder->directory->path));
        $this->reportFindings($findings, $output);

        $failed = count(array_filter($findings, static fn(Finding $f): bool => $f->level->isFailure()));

        if ($failed > 0) {
            $output->error(sprintf(
                "  %d check(s) failed — fix the folder before uploading anything.\n\n",
                $failed,
            ));

            return ExitCode::Failure;
        }

        try {
            $audio  = $this->exporter($input)->export($folder, self::FORMAT);
            $upload = TrackUpload::forRelease($folder, $audio->part());
        } catch (ExportException | SoundCloudException $exception) {
            $output->error(sprintf("  %s\n\n", $exception->getMessage()));

            return ExitCode::Failure;
        }

        $this->reportAudio($audio, $output);
        $this->reportFields($upload, $output);

        if (!$input->has(ReleaseTrackOption::Upload)) {
            $output->error("  nothing was sent — add --upload to do that.\n\n");

            return ExitCode::Success;
        }

        return $this->upload($folder, $upload, $output);
    }

    /**
     * Sends it, and prints the entry the answer makes possible.
     *
     * @param ReleaseFolder $folder
     * @param TrackUpload   $upload
     * @param Output        $output
     * @return ExitCode
     */
    private function upload(ReleaseFolder $folder, TrackUpload $upload, Output $output): ExitCode
    {
        $client = $this->client($output);

        if ($client === null) {
            return ExitCode::Failure;
        }

        try {
            $output->error(sprintf("  uploading %s …\n\n", $upload->audio->filename));

            $track = $client->upload($upload);
            // Built here, inside the try, because this is where a response read wrongly stops being
            // silent: SoundCloudEmbed refuses an id that is not positive and a permalink that is
            // empty. Better a command that failed than an entry with a plausible-looking hole.
            $embed = $track->embed();
        } catch (SoundCloudException | TransportException $exception) {
            $output->error(sprintf("  %s\n\n", $exception->getMessage()));

            return ExitCode::Failure;
        } catch (ReleaseVerificationException $exception) {
            $output->error(sprintf(
                "  The upload was accepted but the answer does not describe a usable track: %s\n"
                . "  Nothing was written. Look the track up on SoundCloud before uploading again.\n\n",
                $exception->getMessage(),
            ));

            return ExitCode::Failure;
        }

        $this->reportTrack($track, $output);

        $output->error("  paste into data/releases.php, newest first:\n\n");
        $this->reportImports($folder, $embed, $output);
        $output->out(EntryWriter::write($folder, $embed) . "\n");

        return ExitCode::Success;
    }

    /**
     * The one browser round trip, and the token it leaves behind.
     *
     * @param Output $output
     * @return ExitCode
     */
    private function authorize(Output $output): ExitCode
    {
        $credentials = Credentials::fromEnvironment();

        if ($credentials === null) {
            return $this->reportMissingCredentials($output);
        }

        $authorization = Authorization::begin();
        $store         = TokenStore::default();

        $output->error("\n  open this, approve it, then paste back the address the browser ends on:\n\n");
        $output->error(sprintf("  %s\n\n", $authorization->url($credentials)));
        $output->error('  > ');

        $redirected = fgets($this->stdin ?? STDIN);

        if (!is_string($redirected) || trim($redirected) === '') {
            $output->error("\n  nothing pasted; nothing stored.\n\n");

            return ExitCode::Failure;
        }

        try {
            new Client(new CurlTransport(), $credentials, $store)->exchange($authorization, trim($redirected));
        } catch (SoundCloudException | TransportException $exception) {
            $output->error(sprintf("\n  %s\n\n", $exception->getMessage()));

            return ExitCode::Failure;
        }

        $output->error(sprintf("\n  stored in %s\n\n", $store->file->path));

        return ExitCode::Success;
    }

    /**
     * The client to upload with, or null once it has said why there is none.
     *
     * @param Output $output
     * @return Client|null
     */
    private function client(Output $output): ?Client
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $credentials = Credentials::fromEnvironment();

        if ($credentials === null) {
            $this->reportMissingCredentials($output);

            return null;
        }

        return new Client(new CurlTransport(), $credentials, TokenStore::default());
    }

    /**
     * Where the audio is coming from.
     *
     * @param Input $input
     * @return Exporter
     */
    private function exporter(Input $input): Exporter
    {
        $named = $input->value(ReleaseTrackOption::Audio);

        // argv is where a path is still a string; it becomes a File here and stays one.
        return $this->exporter ?? new PreparedExport($named !== null ? new File($named) : null);
    }

    /**
     * @param Output $output
     * @return ExitCode
     */
    private function reportMissingCredentials(Output $output): ExitCode
    {
        $output->error(
            "\n  No SoundCloud app is configured. Register one at developers.soundcloud.com and\n"
            . "  put its three values in the environment:\n\n",
        );

        foreach (CredentialVariable::cases() as $variable) {
            $output->error(sprintf("      %s\n", $variable->value));
        }

        $output->error("\n  They are deliberately not read from data/, which deploy.sh uploads.\n\n");

        return ExitCode::Failure;
    }

    /**
     * The file being uploaded, and — the column that matters — how it came to exist.
     *
     * @param ExportedAudio $audio
     * @param Output        $output
     * @return void
     */
    private function reportAudio(ExportedAudio $audio, Output $output): void
    {
        $output->error(sprintf(
            "  audio    %s %s %s\n\n",
            mb_str_pad($audio->name(), 30),
            mb_str_pad(self::size($audio->size()), 10),
            $audio->source->value,
        ));
    }

    /**
     * Every field the request would carry, by the name it would carry it under.
     *
     * The point of printing these is that they are the half of an upload nothing else can show:
     * once it is sent, a field that went out misspelled is a track with something missing and no
     * error anywhere. See {@link \NeuroSYS\Tool\SoundCloud\TrackField}.
     *
     * @param TrackUpload $upload
     * @param Output      $output
     * @return void
     */
    private function reportFields(TrackUpload $upload, Output $output): void
    {
        foreach ($upload->fields() as $field) {
            $output->error(sprintf(
                "  %s %s\n",
                mb_str_pad($field->name, 22),
                $field->value instanceof FilePart
                    ? sprintf('%s (%s)', $field->value->filename, self::size($field->value->file->size()))
                    : $field->value,
            ));
        }

        $output->error("\n");
    }

    /**
     * What now exists on SoundCloud.
     *
     * @param UploadedTrack $track
     * @param Output        $output
     * @return void
     */
    private function reportTrack(UploadedTrack $track, Output $output): void
    {
        $output->error(sprintf("  %s uploaded as %s\n", Level::Ok->label(), $track->url));
        $output->error(sprintf(
            "  %s it is %s — publish it in SoundCloud on the day, not from here\n\n",
            Level::Ok->label(),
            $track->sharing->value,
        ));
    }

    /**
     * The `use` lines the printed entry needs and `data/releases.php` does not have.
     *
     * @param ReleaseFolder    $folder
     * @param SoundCloudEmbed  $embed
     * @param Output           $output
     * @return void
     */
    private function reportImports(ReleaseFolder $folder, SoundCloudEmbed $embed, Output $output): void
    {
        $missing = ReleasesFile::default()->missingImports(EntryWriter::imports($folder, $embed));

        if ($missing === []) {
            return;
        }

        $output->error("  data/releases.php does not import these yet:\n\n");

        foreach ($missing as $class) {
            $output->error(sprintf("      use %s;\n", $class));
        }

        $output->error("\n");
    }

    /**
     * @param list<Finding> $findings
     * @param Output        $output
     * @return void
     */
    private function reportFindings(array $findings, Output $output): void
    {
        foreach ($findings as $finding) {
            $output->error(sprintf("  %s %s\n", $finding->level->label(), $finding->message));
        }

        if ($findings === []) {
            $output->error(sprintf("  %s nothing to report\n", Level::Ok->label()));
        }

        $output->error("\n");
    }

    /**
     * A byte count, in the unit a person would say it in.
     *
     * @param int $bytes
     * @return string
     */
    private static function size(int $bytes): string
    {
        return $bytes >= 1_048_576
            ? sprintf('%.1f MB', $bytes / 1_048_576)
            : sprintf('%.1f KB', $bytes / 1024);
    }
}
