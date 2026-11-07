<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Command;

use NeuroSYS\Model\Embed\SoundCloudEmbed;
use NeuroSYS\Model\Release;
use NeuroSYS\Tool\Data\EntryFile;
use NeuroSYS\Tool\Data\EntryWrite;
use NeuroSYS\Tool\Release\EntryWriter;
use NeuroSYS\Tool\Release\Finding;
use NeuroSYS\Tool\Release\Level;
use NeuroSYS\Tool\Release\ReleaseFolder;
use Phpanta\Tool\Cli\Command;
use Phpanta\Tool\Cli\Input;
use Phpanta\Tool\Cli\Option;
use Phpanta\Tool\Cli\Output;
use Phpanta\Tool\Cli\Runner;

/**
 * The FolderReport class. What both of the commands that read a release folder print about it.
 *
 * `stage-release` and `release-track` are the same command up to their last step — read a folder,
 * judge it, say what is wrong with it, and write an entry — so four blocks were written twice, and
 * `reportFindings()` was byte-identical in both files.
 *
 * **A class and not a trait**, which is the same test {@link \Phpanta\Support\TypedItems} is on
 * the other side of. That is a trait because nothing anywhere holds "either kind of collection";
 * these two *are* both {@link Command}s and {@link Runner} holds either one, so a shared parent or
 * a trait would be announcing a type the layer already has. What they share is not a kind of
 * command, it is a report — so the report is the object.
 *
 * **What is deliberately not here** is the sentence each command prints when a check fails. They
 * differ — one says *fix the folder before uploading anything*, the other adds *or minting a share
 * link* — and a `string $remedy` parameter would be the mistake {@link
 * \NeuroSYS\Tool\SoundCloud\Attempt} was written to end: a parameter that needs a sentence to
 * describe its own format is a parameter with a type missing. So {@link self::findings()} hands
 * back the count and each command writes its own line.
 */
final readonly class FolderReport
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Command $command The command this reports for — consulted for its name and its usage
     *                         line, which is the whole reason this takes one.
     * @param Output  $output  The report goes to stderr; the entry a command prints does not go
     *                         through here at all.
     */
    public function __construct(private Command $command, private Output $output) {}

    /**
     * The folder named on the command line, or null once it has said why there is none.
     *
     * Null rather than an {@link \Phpanta\Tool\Cli\ExitCode}, the same shape
     * {@link ReleaseTrack::client()} already has: the caller answers with `ExitCode::Usage` because
     * that is the caller's decision, and this one has already put the reason on the screen.
     *
     * The `$project` argument is the first thing to use {@link Option} as a type rather than as a
     * list of cases. Each command declares its own `--project` on its own enum — they are different
     * vocabularies that happen to share a flag — and the interface is exactly what says the two are
     * interchangeable here.
     *
     * @param Input  $input
     * @param Option $project The command's own `--project` case.
     * @return ReleaseFolder|null
     */
    public function folder(Input $input, Option $project): ?ReleaseFolder
    {
        $path = $input->operand(0);

        if ($path === null) {
            $this->output->error(Runner::usage($this->command));

            return null;
        }

        if (!is_dir($path)) {
            $this->output->error(sprintf("%s: '%s' is not a folder\n", $this->command->name(), $path));

            return null;
        }

        $folder = ReleaseFolder::at($path, $input->value($project));

        $this->output->error(sprintf("\n%s\n\n", $folder->directory->path));

        return $folder;
    }

    /**
     * Every finding, and how many of them stop the command.
     *
     * @param list<Finding> $findings
     * @return int The number at {@link Level::Fail}, which is what each command's own failure line
     *             counts — see the class docblock for why that line is not written here.
     */
    public function findings(array $findings): int
    {
        $failed = 0;

        foreach ($findings as $finding) {
            $this->output->error(sprintf("  %s %s\n", $finding->level->label(), $finding->message));

            $failed += $finding->level->isFailure() ? 1 : 0;
        }

        if ($findings === []) {
            $this->output->error(sprintf("  %s nothing to report\n", Level::Ok->label()));
        }

        $this->output->error("\n");

        return $failed;
    }

    /**
     * The entry: printed with `--print`, written into `data/releases.json` otherwise — and either
     * way, what it still wants by hand.
     *
     * `stage-release` writes a new entry and refuses a slug already there, since the entry there
     * carries a description and share ids typed in by hand. `release-track` after an upload is the
     * other case: it puts the player into the entry that is there, touching nothing else, and writes
     * the whole entry only where there is none yet.
     *
     * @param ReleaseFolder        $folder
     * @param EntryFile            $file
     * @param bool                 $print
     * @param SoundCloudEmbed|null $embed The uploaded track's, after `release-track --upload`.
     * @return bool Whether the entry is where it was meant to go.
     */
    public function entry(ReleaseFolder $folder, EntryFile $file, bool $print, ?SoundCloudEmbed $embed = null): bool
    {
        $slug = (string) $folder->slug();

        if ($print) {
            $this->output->out(EntryWriter::write($folder, $embed));
            $result = EntryWrite::Written;
        } elseif ($embed !== null && ($existing = $file->entries()->find($slug)) instanceof Release) {
            $result = $file->replace($slug, self::withEmbed($existing, $embed));
            $this->output->error(sprintf("  put the player into '%s' in %s\n\n", $slug, $file->file->path));

            return $this->said($result, $slug, $file);
        } else {
            $result = $file->prepend($slug, EntryWriter::release($folder, $embed));
            if ($result === EntryWrite::Written) {
                $this->output->error(sprintf("  wrote '%s' into %s, first\n\n", $slug, $file->file->path));
            }
        }

        if (!$this->said($result, $slug, $file)) {
            return false;
        }

        $this->output->error("  still to fill in by hand:\n\n");
        foreach (EntryWriter::notes($folder, $embed) as $note) {
            $this->output->error(sprintf("      %s\n", $note));
        }
        $this->output->error("\n");

        return true;
    }

    /**
     * Says what went wrong with a write, where something did.
     *
     * @param EntryWrite $result
     * @param string     $slug
     * @param EntryFile  $file
     * @return bool Whether it was written.
     */
    private function said(EntryWrite $result, string $slug, EntryFile $file): bool
    {
        $why = match ($result) {
            EntryWrite::Written    => null,
            EntryWrite::Exists     => sprintf(
                "'%s' is in %s already, with whatever was typed into it since — nothing was written."
                . ' Remove it first to stage it afresh, or --print the entry and merge it by hand',
                $slug,
                $file->file->path,
            ),
            EntryWrite::Missing    => sprintf("'%s' is not in %s — nothing was written", $slug, $file->file->path),
            EntryWrite::Unwritable => sprintf('%s could not be written; it is as it was', $file->file->path),
        };
        if ($why !== null) {
            $this->output->error(sprintf("  %s.\n\n", $why));
        }

        return $why === null;
    }

    /**
     * $release with $embed as its player, and everything else as it was.
     *
     * @param Release         $release
     * @param SoundCloudEmbed $embed
     * @return Release
     */
    private static function withEmbed(Release $release, SoundCloudEmbed $embed): Release
    {
        return new Release(
            title:       $release->title,
            bpm:         $release->bpm,
            key:         $release->key,
            genre:       $release->genre,
            description: $release->description,
            cover:       $release->cover,
            formats:     $release->formats,
            embed:       $embed,
            arrangement: $release->arrangement,
            timeSpent:   $release->timeSpent,
            madeWith:    $release->madeWith,
        );
    }
}
