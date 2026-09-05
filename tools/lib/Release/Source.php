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
 *
 * **The four FL rungs sit above them**, and that ordering is the point rather than a preference.
 * `docs/authoring.md` says it plainly — *FL Studio writes the tags this reads* — so a fact taken
 * from the project is not a rival answer to the one in the tag, it is the thing the tag was written
 * from. The corpus shows it exactly: `alien house.flp` carries the genre `bass house?`, which is
 * the same free text that ends up in `GENRE` and the same reason `Genre` has no fallback.
 *
 * One rung is deliberately missing. The notes of a project imply a key well enough to be worth
 * saying out loud — see `KeyEstimate` — but a value nothing wrote down is not a source, and this
 * enum is the record of where a fact *came from*. So the estimate reaches the report as a WARN a
 * person accepts, and never as a rung a fact can quietly arrive on.
 */
enum Source: string
{
    case FlpTitle         = 'FL project title';
    case FlpTempo         = 'FL project tempo';
    case FlpGenre         = 'FL project genre';
    case FlpKeyLock       = 'FL piano roll key lock';
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
