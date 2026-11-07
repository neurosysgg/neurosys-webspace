<?php

declare(strict_types=1);

namespace NeuroSYS\Service\Data;

/**
 * The RecordKey enum. Every key the site's JSON data files are written with — `data/releases.json`
 * and `data/demos.json` — spelled once, for the codecs that read them and the tools that write them.
 *
 * A language's text inside a description is keyed by {@link \Phpanta\Text\Language}'s own value
 * instead, and a profile by {@link \NeuroSYS\Model\Platform}'s: both vocabularies exist already.
 * `app/views/demos.c` reads `data/demos.json` with the same names, and the demo half of this enum
 * is the contract between the two.
 */
enum RecordKey: string
{
    // ── a release ──
    case Title       = 'title';
    case Bpm         = 'bpm';
    case Key         = 'key';
    case Genre       = 'genre';
    case Description = 'description';
    case Cover       = 'cover';
    case Formats     = 'formats';
    case Embed       = 'embed';
    case Arrangement = 'arrangement';
    case TimeSpent   = 'timeSpent';
    case MadeWith    = 'madeWith';

    // ── a format ──
    case Type = 'type';
    case Link = 'link';

    // ── an embed ──
    case TrackId     = 'trackId';
    case Permalink   = 'permalink';
    case SecretToken = 'secretToken';
    case Style       = 'style';
    case Options     = 'options';

    // ── an arrangement and its sections ──
    case Ppq      = 'ppq';
    case Sections = 'sections';
    case Label    = 'label';
    case Tick     = 'tick';
    case Kind     = 'kind';

    // ── a demo and its tracks ──
    case Password = 'password';
    case Tracks   = 'tracks';
    case File     = 'file';
    case Seconds  = 'seconds';
}
