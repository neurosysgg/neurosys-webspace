<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Tool\Command\ReleaseTrack;
use NeuroSYS\Tool\Command\StageRelease;
use Phpanta\Tool\Cli\Command;
use Phpanta\Tool\Cli\ExitCode;
use Phpanta\Tool\Cli\Output;
use Phpanta\Tool\Cli\Runner;
use Phpanta\Tool\Command\MergeCoverage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * This site's commands, as the CLI layer sees them.
 *
 * The layer itself — parsing, refusing, the two streams — is tested in the framework's own
 * `CliTest`. What stays here is what only this site has: the scripts under `tools/` that run a
 * command, and the usage of the commands only this site runs.
 */
final class CliTest extends TestCase
{
    /**
     * A malformed command line is answered with the usage line and {@link ExitCode::Usage}, not with
     * whatever the command would have reported.
     *
     * @return void
     */
    public function testRunnerAnswersABadCommandLineWithItsUsage(): void
    {
        $error = fopen('php://memory', 'rw+');

        $code = Runner::execute(
            new StageRelease(),
            ['somewhere', '--nonsense'],
            new Output(fopen('php://memory', 'rw+'), $error),
        );

        rewind($error);
        $written = (string) stream_get_contents($error);

        $this->assertSame(ExitCode::Usage, $code);
        $this->assertStringContainsString("unknown option '--nonsense'", $written);
        $this->assertStringContainsString('usage: php tools/stage-release.php <folder> [--check]', $written);
    }

    /**
     * A missing operand reads as a usage error to whoever typed it.
     *
     * @return void
     */
    public function testAMissingFolderIsAUsageError(): void
    {
        $error = fopen('php://memory', 'rw+');

        $code = Runner::execute(new StageRelease(), [], new Output(fopen('php://memory', 'rw+'), $error));

        $this->assertSame(ExitCode::Usage, $code);
    }

    /**
     * Every command this site runs is run by a script named after it, which is the name `Runner`
     * puts in a usage line.
     *
     * @param Command $command
     * @return void
     */
    #[DataProvider('commands')]
    public function testEveryCommandNamesItselfAfterTheScriptThatRunsIt(Command $command): void
    {
        $this->assertFileExists(sprintf('%s/tools/%s.php', NEUROSYS_ROOT, $command->name()));
        $this->assertStringEndsWith('.', $command->description());
        $this->assertStringContainsString($command->usage(), Runner::usage($command));
    }

    /**
     * @return array<string, array{Command}>
     */
    public static function commands(): array
    {
        return [
            'stage-release'  => [new StageRelease()],
            'release-track'  => [new ReleaseTrack()],
            'merge-coverage' => [new MergeCoverage(NEUROSYS_ROOT)],
        ];
    }
}
