<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Command;

use NeuroSYS\Support\Collection;
use NeuroSYS\Support\File;
use NeuroSYS\Tool\Cli\Command;
use NeuroSYS\Tool\Cli\ExitCode;
use NeuroSYS\Tool\Cli\Input;
use NeuroSYS\Tool\Cli\Output;
use NeuroSYS\Tool\Flp\PlacedNote;
use NeuroSYS\Tool\Flp\Score;
use NeuroSYS\Tool\Midi\MidiFile;
use NeuroSYS\Tool\Midi\MidiNote;
use NeuroSYS\Tool\Midi\MidiTrack;
use NeuroSYS\Tool\Midi\TimeSignature;
use NeuroSYS\Tool\Release\ProjectFile;

/**
 * The ExtractMidi command. Writes a `.flp`'s notes out as a standard MIDI file.
 *
 * **Why this is a command and not a manual step.** A remix package wants the MIDI, and the only way
 * to produce one was to open FL Studio — which lives in a Windows VM — and use its export dialog.
 * `hello world!`'s package has one for that reason; `ill`'s has none for the same reason. Reading
 * it out of the project is the whole job, and the project is just bytes.
 *
 * **It was built against FL's own export rather than against a specification.** Both were run over
 * the same two projects and diffed note for note — 7,522 of 7,564 notes identical on the first and
 * 3,975 of 3,977 on the second, with every note's position, pitch, velocity and channel grouping
 * agreeing. Where the two still differ is written down rather than smoothed over: the one-tick
 * question in {@link self::DIVERGENCE}, the one-shot rule in {@link Score::sounded()}, and this.
 *
 * **FL omits two tracks this writes**, both on the second project, and no single rule was found
 * that covers them. `FILL BASS`'s clips carry `0.0` in the clip's gain field where every other clip
 * carries `1.0`, which would explain a silenced clip — but `BS_LIQUID_178_B_WOB.wav`'s carry `1.0`
 * and are dropped anyway, and FL keeps the first as a named empty track while dropping the second
 * outright. Two behaviours, one guess, so no rule is written. Emitting a track too many is the
 * direction to be wrong in: a remixer can delete one, and cannot recover one that was never there.
 *
 * The report goes to **stderr**, the way `stage-release` puts its report there. The reason is
 * firmer here: this command's product is binary, so anything on stdout would be corruption.
 */
final readonly class ExtractMidi implements Command
{
    /**
     * The one place this knowingly disagrees with FL Studio, kept as prose because it is a
     * judgement rather than a rule.
     *
     * A note that ends exactly on its clip's boundary is shortened by one tick in FL's export on
     * some channels and not others — same tick, same clip end, no derivable difference between
     * them. 51 notes of the 7,564 measured end on a boundary; FL shortens 42 and leaves 9. Writing
     * the project's own length disagrees with it on the 42; the rule that would fix those is wrong
     * on the other 9, so it is not written. A file that is the project beats an imitation of
     * another exporter's rounding, and one tick at 96 ppq is a thousandth of a bar.
     */
    public const string DIVERGENCE = 'notes ending on a clip boundary keep their full length';

    /**
     * @return string
     */
    public function name(): string
    {
        return 'extract-midi';
    }

    /**
     * @return string
     */
    public function usage(): string
    {
        return '<folder|.flp|.zip> [--patterns] [--out <file>]';
    }

    /**
     * @return string
     */
    public function description(): string
    {
        return 'Write an FL Studio project\'s notes out as a standard MIDI file.';
    }

    /**
     * @return list<ExtractMidiOption>
     */
    public function options(): array
    {
        return ExtractMidiOption::cases();
    }

    /**
     * @param Input  $input
     * @param Output $output
     * @return ExitCode
     */
    public function run(Input $input, Output $output): ExitCode
    {
        $path = $input->operand(0);

        if ($path === null) {
            $output->error("extract-midi: name a project — a folder, a .flp, or a zip holding one\n");

            return ExitCode::Usage;
        }

        $found = ProjectFile::at($path);

        if ($found === null) {
            $output->error(sprintf("extract-midi: no FL Studio project at %s\n", $path));

            return ExitCode::Failure;
        }

        if ($found->project === null || $found->flp === null) {
            $output->error(sprintf("extract-midi: %s did not parse — %s\n", $found->name, $found->error ?? 'unknown'));

            return ExitCode::Failure;
        }

        $score   = Score::of($found->flp);
        $project = $found->project;

        $output->error(sprintf("\n  %s\n", $found->name));
        $output->error(sprintf(
            "  %s · %d ppq · %s · %d channels · %d patterns\n",
            $project->version ?? 'unknown FL Studio',
            $score->ppq,
            $project->tempo !== null ? sprintf('%s BPM', self::bpm($project->tempo)) : 'no tempo',
            count($score->channels),
            count($score->patterns),
        ));

        // A project with no tempo is a bad read rather than a song without one — FlpFile's docblock
        // names this as the symptom of a desynchronised walk, so it is said out loud rather than
        // quietly becoming MIDI's default 120.
        if ($project->tempo === null) {
            $output->error("  WARN  no tempo — the file may have been read at the wrong offset\n");
        }

        $patterns = $input->has(ExtractMidiOption::Patterns);
        $tracks   = $patterns ? $this->fromPatterns($score, $output) : $this->fromArrangement($score, $output);

        if ($tracks === null) {
            return ExitCode::Failure;
        }

        return $this->write($input, $output, $found->name, new MidiFile(
            division:      $score->ppq,
            tracks:        $tracks,
            tempo:         $project->tempo,
            timeSignature: $this->timeSignature($project->timeSignature(), $output),
        ));
    }

    /**
     * A tempo as a person writes it: 140 rather than 140.000, and 128.5 kept.
     *
     * @param float $tempo
     * @return string
     */
    private static function bpm(float $tempo): string
    {
        return rtrim(rtrim(number_format($tempo, 3, '.', ''), '0'), '.');
    }

    /**
     * The arrangement — one track per rack channel, in rack order.
     *
     * @param Score  $score
     * @param Output $output
     * @return Collection<MidiTrack>|null null where there is nothing to write.
     */
    private function fromArrangement(Score $score, Output $output): ?Collection
    {
        if ($score->playlist === null) {
            $output->error("  no playlist in this project — try --patterns for the patterns alone\n\n");

            return null;
        }

        $output->error(sprintf(
            "  playlist  %d clips at a stride of %d bytes\n",
            count($score->playlist->clips),
            $score->playlist->stride,
        ));

        $tracks = new Collection(MidiTrack::class);
        $total  = 0;
        $lines  = [];

        foreach ($score->arrangement() as $channel => $placed) {
            $name    = $score->channels->find((string) $channel)?->label() ?? sprintf('Channel %d', $channel);
            $notes   = $this->notes($placed, $output, $name);
            $total  += count($notes);
            $tracks  = $tracks->with(new MidiTrack($name, $channel % MidiTrack::CHANNELS, $notes));
            $lines[] = [$name, count($notes)];
        }

        return $this->reported($tracks, $lines, $total, 'arrangement', $output);
    }

    /**
     * Every pattern, at the ticks the pattern holds — the writing rather than the performance.
     *
     * @param Score  $score
     * @param Output $output
     * @return Collection<MidiTrack>|null
     */
    private function fromPatterns(Score $score, Output $output): ?Collection
    {
        $tracks = new Collection(MidiTrack::class);
        $total  = 0;
        $lines  = [];

        foreach ($score->patterns as $pattern) {
            $name    = $pattern->label();
            $notes   = $this->notes($score->written($pattern), $output, $name);
            $total  += count($notes);
            $tracks  = $tracks->with(new MidiTrack($name, $pattern->index % MidiTrack::CHANNELS, $notes));
            $lines[] = [$name, count($notes)];
        }

        return $this->reported($tracks, $lines, $total, 'patterns', $output);
    }

    /**
     * Prints the track list and refuses a file with nothing in it.
     *
     * @param Collection<MidiTrack>   $tracks
     * @param list<array{string, int}> $lines
     * @param int                     $total
     * @param string                  $what
     * @param Output                  $output
     * @return Collection<MidiTrack>|null
     */
    private function reported(Collection $tracks, array $lines, int $total, string $what, Output $output): ?Collection
    {
        if ($total === 0) {
            $output->error(sprintf("  no notes in the %s — nothing to write\n\n", $what));

            return null;
        }

        $output->error(sprintf("  %s  %d tracks · %d notes\n\n", $what, count($tracks), $total));

        foreach ($lines as [$name, $count]) {
            $output->error(sprintf("    %s %5d\n", mb_str_pad($name, 42), $count));
        }

        return $tracks;
    }

    /**
     * The notes of one track, with the values MIDI cannot hold dealt with out loud.
     *
     * FL counts velocity to 128 and keys to 131; MIDI stops at 127 for both. A key above it is
     * dropped rather than folded down an octave, because a note moved an octave is a wrong note
     * where a note missing is a missing note — and every count below is printed, so a file that is
     * not quite the project says so.
     *
     * **Velocity goes wrong at both ends and the report says which.** 128 comes down to 127, and
     * FL's 0 — a note it plays silently — goes *up* to 1, because a note-on of velocity zero is a
     * note-off in this format and writing it literally would delete the note rather than quieten
     * it. Both used to increment one counter under a line reading "clamped to 127", which is the
     * wrong sentence about half of them.
     *
     * @param list<PlacedNote> $placed
     * @param Output           $output
     * @param string           $track
     * @return Collection<MidiNote>
     */
    private function notes(array $placed, Output $output, string $track): Collection
    {
        $notes   = new Collection(MidiNote::class);
        $dropped = 0;
        $lowered = 0;
        $raised  = 0;

        foreach ($placed as $note) {
            if (!$note->note->isPlayable()) {
                $dropped++;
                continue;
            }

            $velocity = min(MidiNote::MAX_VELOCITY, max(MidiNote::MIN_VELOCITY, $note->note->velocity));

            // **Counted in two directions, because they are two different facts.** One clamp does
            // both — FL counts velocity to 128 where MIDI stops at 127, and FL also writes 0 for a
            // note it plays silently, which MIDI reads as a note-off rather than as a quiet note.
            // Reporting them together said "clamped to 127" about a note that had been raised to 1,
            // which is a report describing the opposite of what happened to it.
            $lowered += $velocity < $note->note->velocity ? 1 : 0;
            $raised  += $velocity > $note->note->velocity ? 1 : 0;

            $notes = $notes->with(new MidiNote($note->tick, $note->note->key, $velocity, $note->length));
        }

        if ($dropped > 0) {
            $output->error(sprintf("  WARN  %s: %d note(s) above MIDI's key range, dropped\n", $track, $dropped));
        }

        if ($lowered > 0) {
            $output->error(sprintf(
                "  note  %s: %d velocity(s) lowered to %d\n",
                $track,
                $lowered,
                MidiNote::MAX_VELOCITY,
            ));
        }

        if ($raised > 0) {
            $output->error(sprintf(
                "  note  %s: %d silent note(s) raised to velocity %d — a velocity of nothing is a "
                . "note-off, not a quiet note\n",
                $track,
                $raised,
                MidiNote::MIN_VELOCITY,
            ));
        }

        return $notes;
    }

    /**
     * The project's time signature, saying so when it has one this cannot read.
     *
     * @param string|null $marker
     * @param Output      $output
     * @return TimeSignature|null
     */
    private function timeSignature(?string $marker, Output $output): ?TimeSignature
    {
        if ($marker === null) {
            return null;
        }

        $signature = TimeSignature::parse($marker);

        if ($signature === null) {
            $output->error(sprintf("  WARN  time signature %s not understood — writing 4/4\n", $marker));
        }

        return $signature;
    }

    /**
     * Writes the file, and says where it went.
     *
     * @param Input    $input
     * @param Output   $output
     * @param string   $project
     * @param MidiFile $midi
     * @return ExitCode
     */
    private function write(Input $input, Output $output, string $project, MidiFile $midi): ExitCode
    {
        $named = $input->value(ExtractMidiOption::Out);
        $target = $named ?? sprintf('%s.mid', pathinfo($project, PATHINFO_FILENAME));

        // File takes an absolute path on purpose — a relative one means something different
        // depending on where the command was run — so the working directory is applied here,
        // which is the one place that knows a person typed it.
        $file = new File(str_starts_with($target, '/') ? $target : sprintf('%s/%s', getcwd(), $target));

        if (!$midi->write($file)) {
            $output->error(sprintf(
                "\n  could not write %s — does its directory exist?\n\n",
                $file->path,
            ));

            return ExitCode::Failure;
        }

        $output->error(sprintf("\n  → %s  (%s bytes)\n", $file->path, number_format($file->size())));
        $output->error(sprintf("     %s\n\n", self::DIVERGENCE));

        return ExitCode::Success;
    }
}
