<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Release;

/**
 * The FlacTag enum. The Vorbis comments this tooling reads off a master.
 *
 * Backed by the comment's own name, upper-cased — which is how {@link Probe::tags()} normalises what
 * it reads, because the format does not guarantee the case and FL Studio has written both.
 *
 * `Artist` is here and unused by any {@link Fact}: the site has exactly one artist and takes it from
 * `Config::NAME` rather than from a file, so reading it would only create a second answer to a
 * question that already has one. It is named so the vocabulary is complete where it is defined.
 */
enum FlacTag: string
{
    case Title      = 'TITLE';
    case Genre      = 'GENRE';
    case Artist     = 'ARTIST';
    case Bpm        = 'BPM';
    case InitialKey = 'INITIALKEY';
    case Date       = 'DATE';
}
