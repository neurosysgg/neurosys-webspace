<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Flp;

/**
 * The EventId enum. The events this reader actually reads.
 *
 * A `.flp` holds a few thousand events of about 125 distinct ids; these are the ones with a
 * question behind them. Everything else is walked past — {@link FlpFile} still has to size every
 * event correctly to find the next one, but only these have a name here, on the same grounds
 * `SecurityHeader` and `ResponseHeader` split: a case earns its place by being read.
 *
 * The ids are grouped by the band they fall in, because in this format an id's number **is** its
 * width — see {@link EventWidth}.
 *
 * Three ids are deliberately absent, and their absence is the point. `200` is the FL registration
 * id, `202` the author's data path, and `196` every sample's absolute path — which in these
 * projects reads `C:\Users\W10LTS~1\AppData\Local\Temp\Image-Line\{GUID}\…`. None of them is a
 * fact about the music, all three identify a machine and a person, and this repo switches download
 * logging off for smaller reasons than that. Naming them here would make them reachable from
 * {@link Project}, so they are not named.
 */
enum EventId: int
{
    /* Byte band — the marker fields, written once per marker. */

    /** A marker's root note as a pitch class, `0` = C. Meaningful only on a scale marker. */
    case MarkerRoot = 46;

    /* Word band. */

    /** Opens a pattern block; every pattern event after it belongs to this index. */
    case PatternIndex = 65;

    /* Dword band. */

    /** A time marker: its tick position in the low three bytes, its {@link MarkerType} in the top. */
    case Marker = 148;

    /** Tempo in millibeats — `140000` is 140 BPM. Present in every project tested. */
    case Tempo = 156;

    /** The FL Studio build the project was last saved by, e.g. `5530`. */
    case Build = 159;

    /* Variable band — text and structs. */

    /** A pattern's name. */
    case PatternName = 193;

    /** The project title, as typed into FL's project info. Empty when never filled in. */
    case Title = 194;

    /** Free text from project info; free enough that `Genre::tryFrom()` is right to refuse it. */
    case Genre = 206;

    /** The author from project info — `neuro.SYS` in every project here. */
    case Artists = 207;

    /** The version string, ASCII rather than UTF-16, e.g. `26.1.0.5530`. */
    case Version = 199;

    /** A mixer insert's name. The closest thing the project has to a stem list. */
    case InsertName = 204;

    /** A marker's name: `4/4`, `DROP`, or `D# Minor Natural (Aeolian)`. */
    case MarkerName = 205;

    /** A plugin's internal FL name — `Fruity Wrapper` for anything hosting a VST. */
    case PluginInternalName = 201;

    /** A plugin's wrapper blob, which is where a hosted VST's own name and vendor are buried. */
    case PluginData = 213;

    /** A pattern's notes: 24 bytes each, the MIDI key at offset 12 and the length at offset 8. */
    case PatternNotes = 224;

    /** Two doubles: the project's creation date, then the time spent on it, both in days. */
    case Timestamp = 237;
}
