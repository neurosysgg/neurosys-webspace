<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Command;

use NeuroSYS\Config;
use NeuroSYS\Tool\Cli\Command;
use NeuroSYS\Tool\Cli\ExitCode;
use NeuroSYS\Tool\Cli\Input;
use NeuroSYS\Tool\Cli\Output;
use NeuroSYS\Tool\Cli\Runner;
use NeuroSYS\Tool\Release\EntryWriter;
use NeuroSYS\Tool\Release\Fact;
use NeuroSYS\Tool\Release\Finding;
use NeuroSYS\Tool\Release\Level;
use NeuroSYS\Tool\Release\Preflight;
use NeuroSYS\Tool\Release\ReleaseFolder;

/**
 * The StageRelease command. Stages a `data/releases.php` entry from a prepared release folder.
 *
 * Reads everything the folder can say about itself — see {@link ReleaseFolder} for what that is and
 * where each fact comes from — checks it is ready to upload, and prints the entry.
 *
 * The report goes to **stderr** and the entry to **stdout**, so `> entry.php` keeps the block alone
 * and `2>&1 >/dev/null` keeps the report alone. Nothing is emitted while a check fails: a folder
 * that is not ready is not one to be minting HiDrive share links against, because a link is bound to
 * the bytes it was minted for.
 *
 * It **prints; it does not write `data/releases.php`.** That file is ordered by hand, newest first,
 * and carries the one field nothing can derive. Generating into it would leave it half-authored and
 * half-generated — the arrangement `tools/build-css.mjs` already refuses when it rejects a rule in a
 * manifest, since a file either orders parts or is one.
 */
final readonly class StageRelease implements Command
{
    /**
     * @return string
     */
    public function name(): string
    {
        return 'stage-release';
    }

    /**
     * @return string
     */
    public function usage(): string
    {
        return '<folder> [--check] [--project <file>]';
    }

    /**
     * @return string
     */
    public function description(): string
    {
        return 'Stage a data/releases.php entry from a prepared release folder.';
    }

    /**
     * @return list<StageReleaseOption>
     */
    public function options(): array
    {
        return StageReleaseOption::cases();
    }

    /**
     * @param Input  $input
     * @param Output $output
     * @return ExitCode
     */
    public function run(Input $input, Output $output): ExitCode
    {
        $path = $input->operand(0);

        if ($path === null) {
            $output->error(Runner::usage($this));

            return ExitCode::Usage;
        }

        if (!is_dir($path)) {
            $output->error(sprintf("%s: '%s' is not a folder\n", $this->name(), $path));

            return ExitCode::Usage;
        }

        $folder   = ReleaseFolder::at($path, $input->value(StageReleaseOption::Project));
        $findings = Preflight::check($folder);

        $output->error(sprintf("\n%s\n\n", $folder->path));
        $this->reportFacts($folder, $output);
        $this->reportFindings($findings, $output);

        $failed = count(array_filter($findings, static fn(Finding $f): bool => $f->level->isFailure()));

        if ($failed > 0) {
            $output->error(sprintf(
                "  %d check(s) failed — fix the folder before uploading anything or minting a share link.\n\n",
                $failed,
            ));

            return ExitCode::Failure;
        }

        if ($input->has(StageReleaseOption::Check)) {
            return ExitCode::Success;
        }

        $output->error("  paste into data/releases.php, newest first:\n\n");
        $this->reportImports($folder, $output);
        $output->out(EntryWriter::write($folder) . "\n");

        return ExitCode::Success;
    }

    /**
     * The classes the entry names, and whether `data/releases.php` already imports them.
     *
     * The entry is written with short names, because that is how every entry beside it is written —
     * so a class the file has never imported is a parse error rather than a missing feature. That
     * was not hypothetical: the arrangement and the time spent are `Model\Production` types, and no
     * entry written before them imports anything from there.
     *
     * @param ReleaseFolder $folder
     * @param Output        $output
     * @return void
     */
    private function reportImports(ReleaseFolder $folder, Output $output): void
    {
        $data    = @file_get_contents(Config::dataPath('releases.php')) ?: '';
        $missing = array_values(array_filter(
            EntryWriter::imports($folder),
            static fn(string $class): bool => !str_contains($data, 'use ' . $class . ';'),
        ));

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
     * Each fact, its value, and the column that makes the report worth reading — where it came from.
     *
     * @param ReleaseFolder $folder
     * @param Output        $output
     * @return void
     */
    private function reportFacts(ReleaseFolder $folder, Output $output): void
    {
        foreach (Fact::cases() as $fact) {
            // mb_str_pad rather than sprintf's width: a title is arbitrary text and the placeholder
            // is an em-dash, and sprintf counts bytes, so either would drag the column out of line.
            $output->error(sprintf(
                "  %s %s %s\n",
                mb_str_pad($fact->value, 8),
                mb_str_pad($this->display($folder, $fact) ?? '—', 28),
                $folder->sourceOf($fact)?->value ?? 'not found',
            ));
        }

        $output->error("\n");
    }

    /**
     * How a fact reads in the report.
     *
     * The two strings are `var_export`ed because that is how they will appear in the entry: seeing
     * `'ill.'` rather than `ill.` is what tells you the trailing dot is part of the title.
     *
     * @param ReleaseFolder $folder
     * @param Fact          $fact
     * @return string|null
     */
    private function display(ReleaseFolder $folder, Fact $fact): ?string
    {
        return match ($fact) {
            Fact::Title   => $folder->title !== null ? var_export($folder->title, true) : null,
            Fact::Slug    => $folder->slug() !== null ? var_export($folder->slug(), true) : null,
            Fact::Bpm     => $folder->bpm !== null ? (string) $folder->bpm : null,
            Fact::Key     => $folder->key?->value,
            Fact::Genre   => $folder->genre?->value,
            Fact::Formats => implode(', ', array_map(static fn($f): string => $f->name, $folder->formats())),
            Fact::Cover   => $folder->cover?->name(),
        };
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
}
