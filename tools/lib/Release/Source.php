<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Release;

/**
 * The Source enum. Where a {@link Fact} was read from.
 *
 * The report's third column, and the reason it is worth reading: a bpm taken from a tag and a bpm
 * taken from a filename are not equally trustworthy, and only one of them survives the file being
 * renamed.
 *
 * It is an enum rather than the strings it replaces because these values get **compared**, not just
 * printed — `$cover->source !== Source::WebExport` decides whether the cover check warns. As a
 * string literal on both sides of that comparison, a typo in either half turned the check off
 * silently, which is the failure this codebase is arranged to prevent everywhere else.
 *
 * The four FLAC rungs are separate cases rather than one carrying a tag name, because they *are*
 * four different answers to "where did this come from".
 */
enum Source: string
{
    case FlacTitleTag     = 'FLAC TITLE tag';
    case FlacBpmTag       = 'FLAC BPM tag';
    case FlacKeyTag       = 'FLAC INITIALKEY tag';
    case FlacGenreTag     = 'FLAC GENRE tag';
    case Filename         = 'filename';
    case WebExport        = 'web/ export';
    case FolderRoot       = 'folder root';
    case EmbeddedPicture  = 'embedded in the FLAC';
    case FilesPresent     = 'files present';
    case DerivedFromTitle = 'derived from the title';
}
