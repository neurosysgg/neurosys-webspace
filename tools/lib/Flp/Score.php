<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Flp;

use NeuroSYS\Support\Collection;
use NeuroSYS\Support\SearchableCollection;

/**
 * The Score class. The notes in a project, and where the playlist says each one plays.
 *
 * **The counterpart to {@link Project}, reading the same file for a different question.** That one
 * answers what a `data/releases.php` entry needs — tempo, key, genre, the arrangement's section
 * names — and is deliberately about the release. This answers what a MIDI file needs, which is the
 * music itself: which channels exist, what is written in each pattern, and which patterns play
 * where. Neither is a better version of the other and neither should absorb the other;
 * `Project::$patterns` is a list of *names* because a stem list is what a release entry wants, and
 * making it a list of notes would have made every reader of it pay for this.
 *
 * Three of the four things read here arrive under a **cursor rather than a filter**. A pattern's
 * notes and a channel's name carry no index of their own — the index is whatever
 * {@link EventId::PatternIndex} or {@link EventId::ChannelIndex} last announced — so these have to
 * be walked in file order, which is why {@link FlpFile::all()} is no use for them.
 *
 * **What this was checked against.** FL Studio's own *Export MIDI file* was run on two projects and
 * the output diffed note for note against {@link self::arrangement()}: 7,564 of 7,564 note-ons
 * identical on the first — position, pitch, velocity, and the grouping into channels — and 27 of
 * 29 tracks identical on the second. That is the only reason the rules below are stated as
 * measurements rather than as guesses.
 */
final readonly class Score
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param SearchableCollection<Channel> $channels Keyed by rack index, as a string.
     * @param SearchableCollection<Pattern> $patterns Keyed by pattern index, as a string.
     * @param Playlist|null                 $playlist Null where the project holds no arrangement.
     * @param int                           $ppq      Ticks per quarter note, off the header.
     */
    private function __construct(
        public SearchableCollection $channels,
        public SearchableCollection $patterns,
        public ?Playlist $playlist,
        public int $ppq,
    ) {}

    /**
     * Reads a parsed project.
     *
     * @param FlpFile $flp
     * @return self
     */
    public static function of(FlpFile $flp): self
    {
        $channels = new SearchableCollection(Channel::class);
        $patterns = new SearchableCollection(Pattern::class);
        $notes    = [];
        $names    = [];
        $channel  = null;
        $pattern  = null;

        // True from a channel's opening event until its name has been taken. FL writes the name
        // first inside a channel's block, and writes the same event id again for every mixer
        // effect further down the file — where the cursor is still pointing at the last channel.
        // Taking only the first is what keeps an insert's plugin from renaming a rack channel.
        $naming = false;

        foreach ($flp->events as $event) {
            if ($event->is(EventId::ChannelIndex)) {
                $channel = $event->number();
                $naming  = true;
                continue;
            }

            if ($event->is(EventId::PatternIndex)) {
                $pattern = $event->number();
                continue;
            }

            if ($event->is(EventId::ChannelName) && $naming && $channel !== null) {
                $channels = $channels->with((string) $channel, new Channel($channel, trim($event->text())));
                $naming   = false;
                continue;
            }

            if ($event->is(EventId::PatternName) && $pattern !== null) {
                $names[$pattern] ??= trim($event->text());
                continue;
            }

            if ($event->is(EventId::PatternNotes) && $pattern !== null && is_string($event->value)) {
                // Merged rather than replaced: nothing forbids a pattern writing its notes across
                // more than one event, and dropping the earlier one would lose notes in silence.
                $notes[$pattern] = [...($notes[$pattern] ?? []), ...Note::decode($event->value)];
            }
        }

        foreach ($notes as $index => $written) {
            $name     = ($names[$index] ?? '') !== '' ? $names[$index] : null;
            $patterns = $patterns->with(
                (string) $index,
                new Pattern($index, $name, new Collection(Note::class)->with(...$written)),
            );
        }

        return new self($channels, $patterns, Playlist::of($flp), $flp->ppq);
    }

    /**
     * The arrangement: every note the playlist places, grouped by the channel that plays it.
     *
     * Grouped by channel because that is what FL's own export does and what a person opening the
     * file expects — one track per instrument, named for it. A pattern is a unit of writing rather
     * than of listening, and a project's patterns routinely hold several instruments at once.
     *
     * A clip **trims** rather than loops: a note whose position falls outside the clip's length
     * does not play. That was checked rather than assumed — placing each pattern once and placing
     * it repeatedly until the clip is filled produce byte-identical output on the corpus, because
     * FL sizes a pattern clip to its content.
     *
     * @return array<int, list<PlacedNote>> Channel index => its notes, in playing order. A plain
     *                                      array because a map of lists is neither shape a
     *                                      collection has, the way `Preflight`'s findings are.
     */
    public function arrangement(): array
    {
        if ($this->playlist === null) {
            return [];
        }

        $placed = [];
        $ends   = [];

        foreach ($this->playlist->clips as $clip) {
            $index   = $clip->pattern();
            $pattern = $index !== null ? $this->patterns->find((string) $index) : null;

            if ($pattern === null) {
                continue;
            }

            $from = $clip->readFrom();

            foreach ($pattern->notes as $note) {
                $offset = $note->position - $from;

                if ($offset < 0 || $offset >= $clip->length) {
                    continue;
                }

                $tick = $clip->position + $offset;

                $placed[$note->channel][] = new PlacedNote($tick, $note->length, $note);
                $ends[$note->channel][]   = $clip->position + $clip->length;
            }
        }

        foreach ($placed as $channel => $notes) {
            $placed[$channel] = self::sounded($notes, $ends[$channel]);
        }

        ksort($placed);

        return $placed;
    }

    /**
     * One pattern's notes, at the ticks the pattern itself writes them.
     *
     * What `--patterns` exports. The playlist is not consulted at all, so this is the writing
     * rather than the arrangement — every pattern once, whether or not it was ever placed.
     *
     * @param Pattern $pattern
     * @return list<PlacedNote>
     */
    public function written(Pattern $pattern): array
    {
        $notes = [];
        $ends  = [];
        $end   = 0;

        foreach ($pattern->notes as $note) {
            $end = max($end, $note->position + max($note->length, 1));
        }

        foreach ($pattern->notes as $note) {
            $notes[] = new PlacedNote($note->position, $note->length, $note);
            $ends[]  = $end;
        }

        return self::sounded($notes, $ends);
    }

    /**
     * Gives every one-shot a duration, and puts the notes in playing order.
     *
     * **A length of zero is normal and a note of zero length is not.** 1,398 notes of the project
     * this was measured on — every hat and every foley hit — carry `length = 0`, because FL plays a
     * sample-triggering channel for as long as the sample lasts and has no reason to write down a
     * duration it does not use. MIDI has no such note: written literally, its note-off lands on its
     * note-on and 1,398 hits are silent.
     *
     * FL's own exporter resolves them, and the rule it uses was recovered by diffing against it:
     * **a one-shot lasts until the next note on its channel, or to the end of its clip when it is
     * the last.** Verified on all 1,398, the five last-note cases included. Note that the next note
     * is the next note *of any pitch* — these channels play one sample, so the question is when the
     * channel next speaks rather than when this pitch does.
     *
     * @param list<PlacedNote> $notes A channel's notes, in no particular order.
     * @param list<int>        $ends  The end tick of the clip each note came from, index for index.
     * @return list<PlacedNote>
     */
    private static function sounded(array $notes, array $ends): array
    {
        $order = array_keys($notes);

        usort($order, static fn(int $a, int $b): int => [$notes[$a]->tick, $notes[$a]->note->key]
            <=> [$notes[$b]->tick, $notes[$b]->note->key]);

        $sorted = [];

        foreach ($order as $position => $index) {
            $note = $notes[$index];

            if ($note->length > 0) {
                $sorted[] = $note;
                continue;
            }

            $next = null;

            for ($ahead = $position + 1; $ahead < count($order); $ahead++) {
                if ($notes[$order[$ahead]]->tick > $note->tick) {
                    $next = $notes[$order[$ahead]]->tick;
                    break;
                }
            }

            $sorted[] = $note->lasting(max(1, ($next ?? $ends[$index]) - $note->tick));
        }

        return $sorted;
    }
}
