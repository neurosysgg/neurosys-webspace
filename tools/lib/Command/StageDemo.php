<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Command;

use NeuroSYS\Config;
use NeuroSYS\Tool\Cli\Command;
use NeuroSYS\Tool\Cli\ExitCode;
use NeuroSYS\Tool\Cli\Input;
use NeuroSYS\Tool\Cli\Output;
use NeuroSYS\Tool\Cli\Runner;
use NeuroSYS\Tool\Demo\DemoEntryWriter;
use NeuroSYS\Tool\Demo\DemoPreflight;
use NeuroSYS\Tool\Demo\DemoSource;
use NeuroSYS\Tool\Demo\DemoStage;
use NeuroSYS\Tool\Demo\Password;
use NeuroSYS\Tool\Release\Finding;
use NeuroSYS\Tool\Release\Level;

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
 * - **It mints a password and shows it once.** Only the bcrypt hash is kept, in the entry. There is
 *   no way to recover the plaintext afterwards and `--rotate` is what to do instead.
 *
 * The report goes to **stderr** and the entry to **stdout**, the same split `stage-release` uses —
 * except the password, which goes to stderr with the report so `> entry.php` cannot accidentally
 * write it into a file.
 */
final readonly class StageDemo implements Command
{
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
        return '<file>… [--title <title>] [--slug <slug>] [--check]   |   --rotate';
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
     * @param Input  $input
     * @param Output $output
     * @return ExitCode
     */
    public function run(Input $input, Output $output): ExitCode
    {
        $paths = self::operands($input);

        // --rotate on its own is the one form that takes no files: it changes one line of an entry
        // that already exists, and restaging to do that would rewrite every file and lose whatever
        // description was written by hand since.
        if ($paths === [] && $input->has(StageDemoOption::Rotate)) {
            return $this->rotate($output);
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

        if (($failed = $stage->write()) !== []) {
            foreach ($failed as $source) {
                $output->error(sprintf(
                    "  could not stage %s — is ffmpeg installed?\n",
                    $source->file->name(),
                ));
            }

            return ExitCode::Failure;
        }

        return $this->emit($stage, $output);
    }

    /**
     * The entry, the password, and what `data/demos.php` still has to import.
     *
     * @param DemoStage $stage
     * @param Output    $output
     * @return ExitCode
     */
    private function emit(DemoStage $stage, Output $output): ExitCode
    {
        $password = Password::mint();

        $output->error(sprintf(
            "  staged %d file(s) into %s\n\n"
            . "  paste into data/demos.php — it is gitignored, so create it if it is not there:\n\n"
            . "      <?php\n"
            . "      declare(strict_types=1);\n\n"
            . "%s\n"
            . "      return [ … ];\n\n",
            count($stage->sources),
            $stage->directory()->path,
            self::importLines(DemoEntryWriter::imports($stage, $password)),
        ));

        $output->out(DemoEntryWriter::write($stage, $password) . "\n");

        self::credentials($stage->slug, $password, $output);

        return ExitCode::Success;
    }

    /**
     * A new password for a demo that is already staged.
     *
     * @param Output $output
     * @return ExitCode
     */
    private function rotate(Output $output): ExitCode
    {
        $password = Password::mint();

        $output->error("\n  replace the password: argument of the entry in data/demos.php with:\n\n");
        $output->out('    password:    ' . DemoEntryWriter::passwordArgument($password) . ",\n");

        self::credentials(null, $password, $output);

        return ExitCode::Success;
    }

    /**
     * What to send, printed once and kept nowhere.
     *
     * **On stderr, with the report.** The entry goes to stdout so `> entry.php` works; a password
     * following it into that file would be the one place a plaintext gets written down, which is
     * the single thing this whole arrangement is careful not to do.
     *
     * @param string|null $slug Null after `--rotate`, where the command was told no slug.
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
            $slug === null ? 'https://neurosys.gg/demos/<slug>' : 'https://neurosys.gg/demos/' . $slug,
            Config::DEMO_USER,
            $password->plaintext,
        ));
    }

    /**
     * The positional arguments, which are the mixes.
     *
     * @param Input $input
     * @return list<string>
     */
    private static function operands(Input $input): array
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
     * is a directory, and reconciles imports against `data/releases.php`. None of those three is
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

    /**
     * The `use` lines the entry needs, indented into the block above it.
     *
     * `data/demos.php` is gitignored, so unlike `data/releases.php` there is often no file to
     * reconcile against — which is why this prints the imports outright rather than asking which
     * are missing the way {@link FolderReport::imports()} does.
     *
     * @param list<string> $imports
     * @return string
     */
    private static function importLines(array $imports): string
    {
        return implode('', array_map(
            static fn(string $class): string => sprintf("      use %s;\n", $class),
            $imports,
        ));
    }
}
