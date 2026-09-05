<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Export;

use NeuroSYS\Tool\Release\ReleaseFolder;

/**
 * The FlStudioExport class. Rendering the project, once there is a way to reach the machine that
 * can.
 *
 * **This refuses, and the refusal is the feature.** A class that guessed at the mechanism and half
 * worked would be worse than one that says the decision is open, because the failure would show up
 * as a release with the wrong audio rather than as a command that stopped.
 *
 * **Two halves, and only one of them is undecided.**
 *
 * The half that is known is the command: FL Studio has documented command-line rendering, and
 * {@link self::commandLine()} builds it — `/R` renders, `/E` names the format. That much is from
 * Image-Line's manual and is written down here so it stops being something to look up. It has
 * **not been run**, which is the difference between this and everything else in `tools/`; the
 * manual documents the switches against `FL.exe` and the 64-bit build takes the same ones.
 *
 * The half that is open is *where it runs*. The FL Studio install this project is made in lives in
 * a **Windows VM**, not on this machine — so `export()` is not a process to start, it is a message
 * to another computer, and each way of sending one is a different set of moving parts:
 *
 * - a guest-side agent listening on a socket, which means something to install and keep running;
 * - SSH into the guest, which means a server in Windows and a key that reaches it;
 * - a shared folder with a watched drop directory, which means no daemon but a polling loop and a
 *   protocol for saying "finished";
 * - the hypervisor's own guest-exec channel, which means the VM has to be up with a session.
 *
 * Three things constrain all four, and they are why none of them is obviously right:
 *
 * 1. **The project has to render where it lives.** A `.flp` references its samples by absolute
 *    path — the reason `StageReleaseOption::Project` exists at all — so copying the file to this
 *    host and rendering it here would render silence where a sample used to be, quietly.
 * 2. **The render uses the project's own saved export settings.** Bit depth, tail length and
 *    format are decisions already taken in the project; a `/E` that disagrees with them is a
 *    second answer, and this tool has no business having an opinion about the first.
 * 3. **FL opens its interface during a command-line render.** So the guest needs a logged-in
 *    session — a VM that boots on demand, headless, is not enough on its own.
 *
 * There is a wine prefix on this machine with FL Studio 2025 in it, and it is deliberately not a
 * shortcut: it is not the install the projects were made in, so a render out of it is a different
 * plugin set and a different result. Anything that looked like it worked would be the worst
 * outcome available.
 */
final readonly class FlStudioExport implements Exporter
{
    /** The 64-bit build's executable, as it is named inside the Windows install. */
    public const string EXECUTABLE = 'FL64.exe';

    /** Renders the project. The manual's optional argument names the output file. */
    private const string RENDER = '/R';

    /** Picks the format, appended without a space — `/Ewav`. Takes a comma-separated list. */
    private const string FORMAT = '/E';

    /**
     * **`never`, not `ExportedAudio`.** A return type may be narrower than the interface's, and
     * `never` is the narrowest there is — so the signature itself says that this hands nothing back,
     * and a caller that treats the result as audio is a type error rather than a surprise at run
     * time. It goes back to `ExportedAudio` on the day the method returns one.
     *
     * @param ReleaseFolder $folder
     * @param RenderFormat  $format
     * @return never
     * @throws ExportException always — see the class docblock for what has to be decided first.
     */
    public function export(ReleaseFolder $folder, RenderFormat $format): never
    {
        throw new ExportException(sprintf(
            "Rendering %s is not wired up: FL Studio lives in a Windows VM, and how a command here "
            . "reaches a render there is undecided — see %s.\n"
            . "  It would run: %s\n"
            . '  For now, export from FL by hand and use the file in the folder, or name one with '
            . '--audio.',
            $folder->projectFile?->name ?? 'the project',
            self::class,
            implode(' ', self::commandLine($folder->projectFile?->name ?? 'project.flp', $format)),
        ));
    }

    /**
     * The command FL Studio would be given, as an argument list.
     *
     * Separate from {@link self::export()} and public because it is the half that is settled: it
     * can be read, printed and asserted against without anything being decided about the machine
     * boundary. An argument list rather than a string, for the reason
     * {@link \NeuroSYS\Tool\Release\Probe::run()} takes one — a path with a space in it is the
     * normal case here, not the exception.
     *
     * @param string       $project The project, at the path the *rendering* machine knows it by.
     * @param RenderFormat $format
     * @return list<string>
     */
    public static function commandLine(string $project, RenderFormat $format): array
    {
        return [self::EXECUTABLE, self::RENDER, self::FORMAT . $format->value, $project];
    }
}
