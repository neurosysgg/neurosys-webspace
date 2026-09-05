<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Tool\Cli\Command;
use NeuroSYS\Tool\Cli\ExitCode;
use NeuroSYS\Tool\Cli\Input;
use NeuroSYS\Tool\Cli\Option;
use NeuroSYS\Tool\Cli\Output;
use NeuroSYS\Tool\Cli\Runner;
use NeuroSYS\Tool\Cli\UsageException;
use NeuroSYS\Tool\Command\MergeCoverageOption;
use NeuroSYS\Tool\Command\StageRelease;
use NeuroSYS\Tool\Command\StageReleaseOption;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The CLI layer under `tools/lib/Cli/`.
 *
 * Argument parsing is the part worth pinning, because the two parsers this replaced agreed on the
 * one thing that was wrong: a flag neither of them recognised was dropped in silence. For
 * `merge-coverage` that meant a mistyped `--clover` reported success and wrote no report.
 */
final class CliTest extends TestCase
{
    /**
     * A stub accepting both kinds of option, so one parser covers both shapes.
     *
     * Anonymous, for two reasons: the real commands are `final`, as they should be, and `phpcs`
     * holds this file to one named class.
     *
     * @return Command
     */
    private function command(): Command
    {
        return new class () implements Command {
            /**
             * @return string
             */
            public function name(): string
            {
                return 'stub';
            }

            /**
             * @return string
             */
            public function usage(): string
            {
                return '<operand> [--check] [--clover <file>]';
            }

            /**
             * @return string
             */
            public function description(): string
            {
                return 'A command that exists to be parsed for.';
            }

            /**
             * @return list<Option>
             */
            public function options(): array
            {
                return [...StageReleaseOption::cases(), ...MergeCoverageOption::cases()];
            }

            /**
             * @param Input  $input
             * @param Output $output
             * @return ExitCode
             */
            public function run(Input $input, Output $output): ExitCode
            {
                return ExitCode::Success;
            }
        };
    }

    /**
     * @return void
     */
    public function testOperandsKeepTheirOrder(): void
    {
        $input = Input::parse(['first', 'second'], $this->command());

        $this->assertSame(2, $input->operandCount());
        $this->assertSame('first', $input->operand(0));
        $this->assertSame('second', $input->operand(1));
        $this->assertNull($input->operand(2));
    }

    /**
     * @return void
     */
    public function testABooleanFlagIsPresentOrAbsentAndCarriesNoValue(): void
    {
        $given = Input::parse(['x', '--check'], $this->command());

        $this->assertTrue($given->has(StageReleaseOption::Check));
        $this->assertNull($given->value(StageReleaseOption::Check));

        $this->assertFalse(Input::parse(['x'], $this->command())->has(StageReleaseOption::Check));
    }

    /**
     * Both spellings, because both get typed.
     *
     * @param list<string> $arguments
     * @return void
     */
    #[DataProvider('valueFlagProvider')]
    public function testAValueFlagIsReadEitherWayItIsWritten(array $arguments): void
    {
        $input = Input::parse($arguments, $this->command());

        $this->assertSame('build/clover.xml', $input->value(MergeCoverageOption::Clover));
        $this->assertTrue($input->has(MergeCoverageOption::Clover));
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function valueFlagProvider(): array
    {
        return [
            'separated by a space' => [['--clover', 'build/clover.xml']],
            'joined by an equals'  => [['--clover=build/clover.xml']],
        ];
    }

    /**
     * The case `getopt()` gets wrong, and the reason `merge-coverage.php` declined it: `getopt()`
     * stops at the first non-option argument, and `composer coverage` passes both paths first.
     *
     * @return void
     */
    public function testFlagsAreReadAfterOperandsToo(): void
    {
        $input = Input::parse(
            ['build/unit.cov', 'build/e2e', '--clover', 'c.xml', '--html', 'h'],
            $this->command(),
        );

        $this->assertSame('build/unit.cov', $input->operand(0));
        $this->assertSame('build/e2e', $input->operand(1));
        $this->assertSame(2, $input->operandCount());
        $this->assertSame('c.xml', $input->value(MergeCoverageOption::Clover));
        $this->assertSame('h', $input->value(MergeCoverageOption::Html));
    }

    /**
     * The whole point of `Command::options()`.
     *
     * @return void
     */
    public function testAnUndeclaredFlagIsRefusedRatherThanDropped(): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessage("unknown option '--clovr'");

        Input::parse(['x', '--clovr', 'c.xml'], $this->command());
    }

    /**
     * @return void
     */
    public function testAValueFlagWithNoValueIsRefused(): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessage("option '--clover' needs a value");

        Input::parse(['x', '--clover'], $this->command());
    }

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
     * The two streams stay separate, which is what lets `> entry.php` keep the entry alone.
     *
     * @return void
     */
    public function testOutputKeepsTheTwoStreamsApart(): void
    {
        $out   = fopen('php://memory', 'rw+');
        $error = fopen('php://memory', 'rw+');

        $output = new Output($out, $error);
        $output->out('product');
        $output->error('report');

        rewind($out);
        rewind($error);

        $this->assertSame('product', stream_get_contents($out));
        $this->assertSame('report', stream_get_contents($error));
    }

    /**
     * A missing operand is the command's own judgement, not a parse failure, but it reads the same
     * way to whoever typed it.
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
     * @return void
     */
    public function testExitCodesAreTheOnesAShellExpects(): void
    {
        $this->assertSame(0, ExitCode::Success->value);
        $this->assertSame(1, ExitCode::Failure->value);
        $this->assertSame(2, ExitCode::Usage->value);
    }
}
