<?php

declare(strict_types=1);

namespace NeuroSYS\View\Terminal;

/**
 * The TerminalCommand class. The command line above a terminal's output, declared rather than typed.
 *
 * Replaces two string concatenations, and the second is the one that made this worth writing:
 * `ReleaseView` built `'./release --track "' . $release->title . '"'` and `NotFoundView` built
 * `'find ' . $this->path` — where the path is **the one string on this site a visitor writes in
 * full**. Neither could quote what it interpolated, because a quote written into a literal is just
 * a character; a title or a path carrying a space or a `"` produced a command line that read wrong.
 *
 * So the quoting is the whole job, and it is the same job {@link \NeuroSYS\Model\Link\HiDriveLink}
 * does for a share id and {@link Terminal} does for a row: the caller states the parts, and the
 * object decides how they go together.
 *
 * **This is not a security boundary and must not be read as one.** The result is assigned to
 * `textContent` by `<terminal-window>` — see {@link TerminalAttribute::isUrl()} — so it is text all
 * the way down and was never at risk of being anything else. What quoting buys is that the line
 * *reads* as the shell transcript it is dressed as, including when what it is quoting is hostile.
 */
final readonly class TerminalCommand
{
    /** @var list<string> The arguments, in order. */
    private array $arguments;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param string $program      What is being run — `./release`, `find`. Never quoted: a program
     *                             name with a space in it is not a thing this site displays, and
     *                             quoting it would put quotes around every command line on the site.
     * @param string ...$arguments Flags and values, in the order they should read. A flag is
     *                             recognised by its leading `-` and left alone; everything else is
     *                             a value and is quoted. See {@link self::render()}.
     */
    public function __construct(
        public string $program,
        string ...$arguments,
    ) {
        $this->arguments = array_values($arguments);
    }

    /**
     * The command line as one string, for {@link TerminalAttribute::Command}.
     *
     * `render()` rather than `__toString()`, because that is what every other object here that
     * produces a wire form is called — {@link \NeuroSYS\Http\Security\ContentSecurityPolicy},
     * {@link \NeuroSYS\Http\Security\PermissionsPolicy}, {@link \NeuroSYS\Http\MimeType}. It also
     * has to be a real method rather than a `Stringable`: {@link \NeuroSYS\View\Html\Element::attr()}
     * takes `string|int|bool|BackedEnum|null` and would refuse the object.
     *
     * @return string
     */
    public function render(): string
    {
        return implode(' ', [
            $this->program,
            ...array_map(self::token(...), $this->arguments),
        ]);
    }

    /**
     * One argument, quoted if it is a value and bare if it is a flag.
     *
     * The rule is the leading `-`, which is how a shell reads one and how a reader will too. It is
     * deliberately about *shape* rather than position — `--track` before a title and `-l` after one
     * both work, and a value that happens to start with a dash is a problem this site does not have
     * and would notice immediately if it did.
     *
     * A value is quoted **always**, not only when it contains a space. Sometimes-quoting would mean
     * the release terminal read `--track "ill."` for one title and `--track something` for the
     * next, and a line whose punctuation depends on its content is harder to read than one whose
     * punctuation is a rule. An embedded `"` is escaped the way a shell escapes it.
     *
     * @param string $argument
     * @return string
     */
    private static function token(string $argument): string
    {
        if (str_starts_with($argument, '-')) {
            return $argument;
        }

        return '"' . str_replace(['\\', '"'], ['\\\\', '\"'], $argument) . '"';
    }
}
