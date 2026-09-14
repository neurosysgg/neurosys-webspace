<?php

/**
 * Entry point for the `authenticator` command — see {@link \Phpanta\Tool\Command\Authenticator}.
 *
 * Usage: php -d curl.cainfo=/etc/httpd/conf/neurosys.localhost.crt tools/authenticator.php
 *            --url https://neurosys.localhost [--device <file>] [--name <name>] [--key <file>]
 *
 * Plays a passkey device against the local Apache's copy of the admin: the first run registers it
 * and enrols it with the local signing key, every run checks that an unlock, a write's tap and a
 * lock each happen once. `curl.cainfo` is how PHP's curl trusts the local vhost's self-signed
 * certificate; the command verifies certificates like every other.
 */

declare(strict_types=1);

use NeuroSYS\Site;
use Phpanta\Tool\Cli\Runner;
use Phpanta\Tool\Command\Authenticator;

require __DIR__ . '/../autoload.php';
require __DIR__ . '/autoload.php';

Runner::run(new Authenticator(Site::ORIGIN, Site::UPDATE_KEY), $argv);
