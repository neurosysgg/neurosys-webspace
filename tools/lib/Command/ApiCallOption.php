<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Command;

use NeuroSYS\Tool\Cli\Option;

/**
 * The ApiCallOption enum. The flags `api` accepts.
 *
 * Two, and both are the ones {@link PushUpdateOption} has for the same reasons — where to go and
 * which key to sign with. There is deliberately no flag that changes *what* is asked for: that is
 * the three operands, because a service, a version and an action are what the address is, and a
 * flag would let one of them be forgotten and defaulted.
 */
enum ApiCallOption: string implements Option
{
    /** Which deployment to ask. An origin, not an endpoint. Defaults to the live site. */
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
        return true;
    }
}
