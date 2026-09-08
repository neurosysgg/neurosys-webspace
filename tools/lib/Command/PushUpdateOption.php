<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Command;

use NeuroSYS\Tool\Cli\Option;

/**
 * The PushUpdateOption enum. The flags `push-update` accepts.
 *
 * Declared so {@link \NeuroSYS\Tool\Cli\Input} can refuse one this command never named — which for
 * this command is worth more than for the others here. A mistyped `--dry-run` that were silently
 * dropped would not print a plan; it would deploy.
 */
enum PushUpdateOption: string implements Option
{
    /** Validate and report on the far side, writing nothing and advancing no serial. */
    case DryRun = 'dry-run';

    /**
     * Leave alone whatever the payload does not mention.
     *
     * The default is to mirror, matching `deploy.sh`'s `--delete` on the two trees this ships. This
     * flag is the escape hatch for a push that is deliberately partial.
     */
    case NoMirror = 'no-mirror';

    /** Where to push. Defaults to the live site. */
    case Url = 'url';

    /** The private key. Defaults to `~/.config/neurosys/update.key`. */
    case Key = 'key';

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
        return $this === self::Url || $this === self::Key;
    }
}
