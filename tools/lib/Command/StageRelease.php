<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Command;

use NeuroSYS\Model\ReleaseFormat;
use NeuroSYS\Tool\Cli\Command;
use NeuroSYS\Tool\Cli\ExitCode;
use NeuroSYS\Tool\Cli\Input;
use NeuroSYS\Tool\Cli\Output;
use NeuroSYS\Tool\Release\EntryWriter;
use NeuroSYS\Tool\Release\Fact;
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
        $report = new FolderReport($this, $output);
        $folder = $report->folder($input, StageReleaseOption::Project);

        if ($folder === null) {
            return ExitCode::Usage;
        }

        $this->reportFacts($folder, $output);

        $failed = $report->findings(Preflight::check($folder));

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
        $report->imports($folder);
        $output->out(EntryWriter::write($folder) . "\n");

        return ExitCode::Success;
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
            Fact::Formats => $folder->formats()->map(static fn(ReleaseFormat $f): string => $f->name)->join(', '),
            Fact::Cover   => $folder->cover?->name(),
        };
    }
}
