<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Command;

use NeuroSYS\Model\Embed\SoundCloudEmbed;
use NeuroSYS\Tool\Cli\Command;
use NeuroSYS\Tool\Cli\Input;
use NeuroSYS\Tool\Cli\Option;
use NeuroSYS\Tool\Cli\Output;
use NeuroSYS\Tool\Cli\Runner;
use NeuroSYS\Tool\Release\EntryWriter;
use NeuroSYS\Tool\Release\Finding;
use NeuroSYS\Tool\Release\Level;
use NeuroSYS\Tool\Release\ReleaseFolder;
use NeuroSYS\Tool\Release\ReleasesFile;

/**
 * The FolderReport class. What both of the commands that read a release folder print about it.
 *
 * `stage-release` and `release-track` are the same command up to their last step — read a folder,
 * judge it, say what is wrong with it, and print an entry — so four blocks were written twice, and
 * `reportFindings()` was byte-identical in both files. This is the criterion {@link ReleasesFile}
 * already states and stopped one method short of: *"a class rather than the same six lines in two
 * commands, now that both `stage-release` and `release-track` print an entry"*.
 *
 * **A class and not a trait**, which is the same test {@link \NeuroSYS\Support\TypedItems} is on
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
     * Null rather than an {@link \NeuroSYS\Tool\Cli\ExitCode}, the same shape
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
     * The classes the entry names, and whether `data/releases.php` already imports them.
     *
     * The entry is written with short names, because that is how every entry beside it is written —
     * so a class the file has never imported is a parse error rather than a missing feature. That
     * was not hypothetical: the arrangement and the time spent are `Model\Production` types, and no
     * entry written before them imports anything from there.
     *
     * @param ReleaseFolder        $folder
     * @param SoundCloudEmbed|null $embed Null for an entry printed before the track was uploaded,
     *                                    which is what `stage-release` always prints.
     * @return void
     */
    public function imports(ReleaseFolder $folder, ?SoundCloudEmbed $embed = null): void
    {
        $missing = ReleasesFile::default()->missingImports(EntryWriter::imports($folder, $embed));

        if ($missing === []) {
            return;
        }

        $this->output->error("  data/releases.php does not import these yet:\n\n");

        foreach ($missing as $class) {
            $this->output->error(sprintf("      use %s;\n", $class));
        }

        $this->output->error("\n");
    }
}
