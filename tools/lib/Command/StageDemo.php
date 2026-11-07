<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Command;

use NeuroSYS\Model\Demo;
use NeuroSYS\Model\Waveform;
use NeuroSYS\Site;
use NeuroSYS\Tool\Data\EntryFile;
use NeuroSYS\Tool\Data\EntryWrite;
use NeuroSYS\Tool\Demo\DemoEntryWriter;
use NeuroSYS\Tool\Demo\DemoPreflight;
use NeuroSYS\Tool\Demo\DemoSource;
use NeuroSYS\Tool\Demo\DemoStage;
use NeuroSYS\Tool\Demo\Password;
use NeuroSYS\Tool\Demo\WaveformScan;
use NeuroSYS\Tool\Release\Finding;
use NeuroSYS\Tool\Release\Level;
use Phpanta\Support\Directory;
use Phpanta\Support\File;
use Phpanta\Tool\Cli\Arity;
use Phpanta\Tool\Cli\Command;
use Phpanta\Tool\Cli\ExitCode;
use Phpanta\Tool\Cli\Input;
use Phpanta\Tool\Cli\Output;
use Phpanta\Tool\Cli\Runner;

/**
 * The StageDemo command. Puts an unreleased mix behind a password at `/demos/<slug>`.
 *
 * The other half of {@link StageRelease}: that one prepares something finished for a public page,
 * this one prepares something unfinished for exactly one listener. Three things follow from that
 * difference and they are what this command is.
 *
 * - **Nothing is discovered.** Every file on the page is named on the command line, in the order it
 *   should appear. `~/Music/neuro.SYS/demos/` is a working directory — eight bounces of one
 *   bootleg, four release candidates, a mastering export and a zero-byte file — and no rule over it
 *   picks the two mixes worth sending. See {@link DemoSource}.
 * - **It writes audio.** `stage-release` only ever prints; this transcodes each master into
 *   `data/demos/{slug}/`, which is outside the webroot, because the gate has to cover the bytes and
 *   not just the page. That is the whole reason a demo is not a HiDrive link.
 * - **It mints a password and shows it once.** Only the PBKDF2 digest is kept, in the entry it writes
 *   into `data/demos.json`. There is no way to recover the plaintext afterwards and
 *   `--rotate <slug>` is what to do instead: it re-mints the one field in place.
 * - **It analyses what it staged.** Each mix gets a {@link Waveform} beside it, which is what
 *   `<demo-waveform>` draws behind the player. `--waveforms` does that half on its own, for a demo
 *   whose sidecar is missing — and, like `--rotate`, without touching a password or an entry.
 *
 * The report and the password go to **stderr**. `--print` puts the entry (or, with `--rotate`, the
 * new digest) on **stdout** instead of into the file, and the password still goes to stderr, so
 * `> entry.json` cannot write it into a file.
 */
final readonly class StageDemo implements Command
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param File|null $demos A test seam: the demos file, or null for `data/demos.json`.
     */
    public function __construct(private ?File $demos = null) {}

    /**
     * @return string
     */
    public function name(): string
    {
        return 'stage-demo';
    }

    /**
     * @return string
     */
    public function usage(): string
    {
        return '<file>… [--title <title>] [--slug <slug>] [--check] [--print]'
            . "\n                                 --waveforms [<slug>…]   |   --rotate <slug> [--print]";
    }

    /**
     * @return string
     */
    public function description(): string
    {
        return 'Stage a password-protected demo page from the mixes you name.';
    }

    /**
     * @return list<StageDemoOption>
     */
    public function options(): array
    {
        return StageDemoOption::cases();
    }

    /**
     * Any number, because what they are depends on the mode — files to stage, slugs for
     * `--waveforms`, none for `--rotate` — and {@link self::run()} holds each mode to its own.
     *
     * @return Arity
     */
    public function operands(): Arity
    {
        return Arity::atLeast(0);
    }

    /**
     * @param Input  $input
     * @param Output $output
     * @return ExitCode
     */
    public function run(Input $input, Output $output): ExitCode
    {
        $paths    = self::mixes($input);
        $rotating = $input->has(StageDemoOption::Rotate);

        // **Dispatched on the flags, and never on how many operands came with them.** Dispatched on
        // the count, `--rotate v4.flac` would match neither branch and fall through to a full
        // staging run — transcoding every mix, minting a *new* password and printing a whole new
        // entry, which is the exact pair of things --rotate exists not to do. A flag this command
        // declares must never be one it quietly ignores; that is what Input refusing an undeclared
        // flag buys, and reading one and dropping it gives back.
        //
        // Waveforms is asked first because it reads the operands as slugs rather than as files —
        // the only mode that does.
        if ($input->has(StageDemoOption::Waveforms)) {
            return $rotating
                ? $this->refuse($output, '--waveforms and --rotate are separate jobs — one analyses '
                    . 'audio that is already staged and the other replaces a password; run them one '
                    . 'at a time')
                : $this->waveforms($paths, $output);
        }

        // --rotate takes one slug and no files: it changes one field of an entry that already
        // exists, and restaging to do that would rewrite every file and lose whatever description
        // was written by hand since. With --print it writes nothing, so it needs no slug.
        if ($rotating) {
            $printing = $input->has(StageDemoOption::Print);

            return match (true) {
                $printing && $paths === []  => $this->rotate(null, true, $output),
                count($paths) === 1         => $this->rotate($paths[0], $printing, $output),
                default                     => $this->refuse($output, sprintf(
                    '--rotate takes the slug of a demo that is staged, and nothing else — %d '
                    . 'operands were given. It replaces one field of an entry that already exists, '
                    . 'where staging would rewrite every mix',
                    count($paths),
                )),
            };
        }

        if ($paths === []) {
            $output->error(Runner::usage($this));

            return ExitCode::Usage;
        }

        $stage = DemoStage::of(
            $paths,
            $input->value(StageDemoOption::Title),
            $input->value(StageDemoOption::Slug),
        );

        $output->error(sprintf("\n%s\n\n", $stage->directory()->path));

        if (self::report(DemoPreflight::check($stage), $output) > 0) {
            $output->error(
                "  fix the above before staging — a demo sent with a dead player\n"
                . "  is worse than one not sent at all.\n\n",
            );

            return ExitCode::Failure;
        }

        if ($input->has(StageDemoOption::Check)) {
            // Deliberately before the password is minted. A run made only to read the report should
            // not leave a password on the screen that no entry anywhere matches.
            return ExitCode::Success;
        }

        $printing = $input->has(StageDemoOption::Print);
        $file     = EntryFile::demos($this->demos);

        // Asked before a byte of audio is written: a slug that is taken is one whose entry carries
        // a password somebody holds, and transcoding over its files would break the page they have.
        if (!$printing && $file->has($stage->slug)) {
            return $this->refuse($output, sprintf(
                "'%s' is in %s already — nothing was staged. Pick another --slug, or remove "
                . 'that demo first',
                $stage->slug,
                $file->file->path,
            ));
        }

        if (!($failed = $stage->write())->isEmpty()) {
            foreach ($failed as $source) {
                $output->error(sprintf(
                    "  could not stage %s — is ffmpeg installed?\n",
                    $source->file->name(),
                ));
            }

            return ExitCode::Failure;
        }

        $directory = $stage->directory();

        foreach ($stage->sources as $source) {
            self::analyse($directory, $source->target($directory)->name(), $source->label, $output);
        }

        return $this->emit($stage, $printing ? null : $file, $output);
    }

    /**
     * The entry, written or printed, and the password.
     *
     * @param DemoStage      $stage
     * @param EntryFile|null $file   Where it goes; null to print it instead.
     * @param Output         $output
     * @return ExitCode
     */
    private function emit(DemoStage $stage, ?EntryFile $file, Output $output): ExitCode
    {
        $password = Password::mint();

        $output->error(sprintf("  staged %d file(s) into %s\n\n", $stage->sources->count(), $stage->directory()->path));

        if ($file === null) {
            $output->out(DemoEntryWriter::write($stage, $password));
        } elseif ($file->prepend($stage->slug, DemoEntryWriter::demo($stage, $password)) !== EntryWrite::Written) {
            $output->error(sprintf(
                "  %s could not be written — the audio is staged, the entry is not. Run with --print\n"
                . "  to have the entry to put in by hand.\n\n",
                $file->file->path,
            ));

            return ExitCode::Failure;
        } else {
            $output->error(sprintf(
                "  wrote '%s' into %s, first. Its description is null, which the page shows as\n"
                . "  \"work in progress\" — write what you are asking them for.\n",
                $stage->slug,
                $file->file->path,
            ));
        }

        self::credentials($stage->slug, $password, $output);

        return ExitCode::Success;
    }

    /**
     * A new password for a demo that is already staged.
     *
     * @param string|null $slug     The demo, or null with `--print`, where nothing is looked up.
     * @param bool        $printing Print the digest rather than write it.
     * @param Output      $output
     * @return ExitCode
     */
    private function rotate(?string $slug, bool $printing, Output $output): ExitCode
    {
        $file = EntryFile::demos($this->demos);
        $demo = $slug === null ? null : $file->entries()->find($slug);

        if ($slug !== null && !$demo instanceof Demo) {
            $output->error(sprintf("\n  no demo called %s in %s.\n\n", $slug, $file->file->path));

            return ExitCode::Failure;
        }

        $password = Password::mint();

        if ($printing || $demo === null) {
            $output->error("\n  the new digest, for the entry's password field:\n\n");
            $output->out($password->hash->digest() . "\n");
        } elseif ($file->replace((string) $slug, DemoEntryWriter::rotated($demo, $password)) !== EntryWrite::Written) {
            $output->error(sprintf(
                "\n  %s could not be written; the old password still opens it.\n\n",
                $file->file->path,
            ));

            return ExitCode::Failure;
        } else {
            $output->error(sprintf("\n  replaced the password of '%s' in %s.\n", $slug, $file->file->path));
        }

        self::credentials($slug, $password, $output);

        return ExitCode::Success;
    }

    /**
     * Waveforms for demos that are already staged, without restaging any of them.
     *
     * It reads `data/demos.json` rather than a directory listing, so the demos it knows about are
     * exactly the ones the site serves — a folder left behind under `data/demos/` by a demo that
     * was deleted from the entry file is not one, and analysing it would be ten seconds spent on
     * something no page can reach.
     *
     * A slug that names nothing is a failure rather than a shrug, because the only way to type one
     * is to mean it.
     *
     * @param list<string> $slugs The demos to do, or none for all of them.
     * @param Output       $output
     * @return ExitCode
     */
    private function waveforms(array $slugs, Output $output): ExitCode
    {
        $demos   = EntryFile::demos($this->demos)->entries();
        $unknown = array_values(array_diff($slugs, $demos->toKeys()));

        if ($unknown !== []) {
            $output->error(sprintf(
                "\n  no demo called %s in data/demos.json.\n\n",
                implode(' or ', $unknown),
            ));

            return ExitCode::Failure;
        }

        if ($slugs !== []) {
            $demos = $demos->where(static fn(Demo $demo, string $slug): bool => in_array($slug, $slugs, true));
        }

        if ($demos->isEmpty()) {
            $output->error("\n  no demos to analyse — data/demos.json is empty or absent.\n\n");

            return ExitCode::Failure;
        }

        $output->error("\n");
        $written = 0;

        foreach ($demos as $slug => $demo) {
            $directory = Site::current()->demoDir($slug);

            foreach ($demo->tracks as $track) {
                $written += self::analyse($directory, $track->file, $track->label, $output) ? 1 : 0;
            }
        }

        $output->error(sprintf("\n  wrote %d waveform(s). data/demos.json unchanged.\n\n", $written));

        return $written > 0 ? ExitCode::Success : ExitCode::Failure;
    }

    /**
     * Analyses one staged mix and writes its sidecar beside it.
     *
     * The failure is reported and not fatal, and that is the same judgement `DownloadLogger` makes
     * about its log: a waveform is what the card is drawn on, not what it plays, so a mix that
     * could not be analysed should still be a mix on a page. {@link \NeuroSYS\Model\Waveform}'s
     * reader answers null for the sidecar that is then not there, which is the same state as a mix
     * staged without one.
     *
     * @param Directory $directory The demo's own, which is where both files live.
     * @param string    $audio     The staged audio's file name.
     * @param string    $label     The mix's label, which is what the sidecar is named for.
     * @param Output    $output
     * @return bool
     */
    private static function analyse(Directory $directory, string $audio, string $label, Output $output): bool
    {
        $file = $directory->file($audio);

        // Asked separately from the decode below because ffmpeg answers both the same way, and
        // these are not the same problem: a zero-byte file is a staging run that went wrong, and
        // `data/demos/alien-house/v3.mp3` is one. A message naming ffmpeg would send whoever reads
        // it to check an installation that is fine.
        if (!$file->exists() || $file->size() < 1) {
            $output->error(sprintf("  %-28s is empty or missing — restage it\n", $audio));

            return false;
        }

        $started  = hrtime(true);
        $waveform = WaveformScan::of($file);
        $seconds  = (hrtime(true) - $started) / 1e9;

        if ($waveform === null) {
            $output->error(sprintf(
                "  %-28s could not be decoded — is it really audio, and is ffmpeg installed?\n",
                $audio,
            ));

            return false;
        }

        if (!Waveform::fileIn($directory, $label)->write($waveform->bytes())) {
            $output->error(sprintf("  %-28s analysed, but the waveform could not be written\n", $audio));

            return false;
        }

        $output->error(sprintf(
            "  %-28s %d columns   %.1fs\n",
            $audio,
            Waveform::COLUMNS,
            $seconds,
        ));

        return true;
    }

    /**
     * Refuses a combination of flags this command has no single meaning for.
     *
     * A usage error rather than a warning-and-carry-on, because every mode here writes something a
     * run cannot take back — audio into `data/demos/`, or a password that only exists on the
     * screen it was printed to. Guessing which of two modes was meant would be guessing about
     * that, so it says what is wrong and does nothing. The usage line goes out under it, since a
     * person who typed two modes at once is a person reading the usage line next.
     *
     * @param Output $output
     * @param string $why    What is wrong, without the trailing full stop.
     * @return ExitCode Always {@link ExitCode::Usage} — nothing was written and nothing was minted.
     */
    private function refuse(Output $output, string $why): ExitCode
    {
        $output->error(sprintf("\n  %s.\n\n", $why));
        $output->error(Runner::usage($this));

        return ExitCode::Usage;
    }

    /**
     * What to send, printed once and kept nowhere.
     *
     * **On stderr, with the report.** `--print` puts the entry on stdout so `> entry.json` works; a
     * password following it into that file would be the one place a plaintext gets written down,
     * which is the single thing this whole arrangement is careful not to do.
     *
     * @param string|null $slug Null after `--rotate --print`, where the command was told no slug.
     * @param Password    $password
     * @param Output      $output
     * @return void
     */
    private static function credentials(?string $slug, Password $password, Output $output): void
    {
        $output->error(sprintf(
            "\n  send these, and nothing else:\n\n"
            . "      url:       %s\n"
            . "      user:      %s\n"
            . "      password:  %s\n\n"
            . "  shown once. Only the hash is stored, so this cannot be looked up later —\n"
            . "  losing it means `php tools/stage-demo.php --rotate`.\n\n",
            Site::ORIGIN . '/demos/' . ($slug ?? '<slug>'),
            Site::DEMO_USER,
            $password->plaintext,
        ));
    }

    /**
     * The positional arguments, which are the mixes.
     *
     * @param Input $input
     * @return list<string>
     */
    private static function mixes(Input $input): array
    {
        $paths = [];

        for ($i = 0; $i < $input->operandCount(); $i++) {
            $paths[] = (string) $input->operand($i);
        }

        return $paths;
    }

    /**
     * Every finding, and how many of them stop the command.
     *
     * Not {@link FolderReport}, which is what `stage-release` and `release-track` share: that class
     * is built around a {@link \NeuroSYS\Tool\Release\ReleaseFolder} — it takes one path, checks it
     * is a directory, and reconciles imports against `data/releases.json`. None of those three is
     * true here. What is left in common is one `foreach` printing a level and a message, and a
     * shared parent for that would be announcing a kind these two are not.
     *
     * @param list<Finding> $findings
     * @param Output       $output
     * @return int The number at {@link Level::Fail}.
     */
    private static function report(array $findings, Output $output): int
    {
        $failed = 0;

        foreach ($findings as $finding) {
            $output->error(sprintf("  %s %s\n", $finding->level->label(), $finding->message));

            $failed += $finding->level->isFailure() ? 1 : 0;
        }

        $output->error("\n");

        return $failed;
    }
}
