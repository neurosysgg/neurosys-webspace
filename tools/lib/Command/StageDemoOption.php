<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Command;

use NeuroSYS\Tool\Cli\Option;

/**
 * The StageDemoOption enum. What `tools/stage-demo.php` accepts.
 *
 * @see StageDemo
 */
enum StageDemoOption: string implements Option
{
    /**
     * Report only. Nothing is written, no password is minted, no entry is printed.
     *
     * Worth having for the same reason `--check` is on `stage-release`, and for one more: minting a
     * password is not free to get wrong. Every run without this produces a new one and a new hash,
     * so a run made only to look at the report would leave a password on the screen that the entry
     * in `data/demos.php` does not match.
     */
    case Check = 'check';

    /**
     * Mint a new password for a demo that already exists, and print only the line that changes.
     *
     * The one form of this command that takes no files. The audio is where it was and the entry
     * beside it is right except for one argument — so restaging to change a password would rewrite
     * every file and lose any description written by hand since.
     *
     * Use it when a password has been given to somebody it should not have been, or when the
     * plaintext is lost. There is no way to recover the old one, by design: the hash is all that
     * was ever kept.
     */
    case Rotate = 'rotate';

    /**
     * Re-read the staged audio of demos that already exist and write their waveforms.
     *
     * The second form that takes no files, and it is beside {@link self::Rotate} for the same
     * reason that one exists: it changes something about a demo that is already staged, without
     * restaging it — which would mint a new password and lose any description written by hand.
     *
     * It reads what is in `data/demos/{slug}/` rather than the masters those were made from. That
     * is the right way round twice over: it is the audio the page actually serves, and the masters
     * live on one machine and may be long gone by the time a waveform is wanted.
     *
     * Give it slugs to do only those; give it nothing and it does every demo in `data/demos.php`.
     * ~10 seconds a mix — see {@link \NeuroSYS\Tool\Demo\WaveformScan}.
     */
    case Waveforms = 'waveforms';

    /**
     * What the demo is called, where the file name and its tags do not say.
     *
     * Not a rare case here. `alien house v4.flac` carries an empty `TITLE` comment, and the file
     * name is a working name with a version on the end.
     */
    case Title = 'title';

    /**
     * The URL, where the one derived from the title is unwieldy.
     *
     * `Virtual Riot - We're Not Alone [neuro.SYS Bootleg]` slugs to something nobody wants to read
     * out over a message, and the slug is half of what gets sent.
     */
    case Slug = 'slug';

    /**
     * @return string
     */
    public function flag(): string
    {
        return $this->value;
    }

    /**
     * @return bool
     */
    public function takesValue(): bool
    {
        return $this === self::Title || $this === self::Slug;
    }
}
