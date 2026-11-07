<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Demo;

use NeuroSYS\Model\Demo;
use NeuroSYS\Model\DemoTrack;
use NeuroSYS\Service\Data\DemoCodec;
use NeuroSYS\Service\Data\Record;
use stdClass;

/**
 * The DemoEntryWriter class. The `data/demos.json` entry for a staged demo.
 *
 * The same arrangement as {@link \NeuroSYS\Tool\Release\EntryWriter}: the entry is a {@link Demo},
 * written by the codec the site reads it with. What differs is what it cannot know, which is almost
 * nothing: a demo has one editorial field, and it is optional — written out as the null it is, so
 * the file shows where it goes. A null renders as "work in progress", which is what a forgotten
 * description looks like on the page, so the command says so when it writes one.
 */
final readonly class DemoEntryWriter
{
    /**
     * The demo the stage makes.
     *
     * @param DemoStage $stage
     * @param Password  $password The one minted for this demo — its hash, never its plaintext.
     * @return Demo
     */
    public static function demo(DemoStage $stage, Password $password): Demo
    {
        return new Demo(
            title:    $stage->title,
            password: $password->hash,
            tracks:   $stage->sources->map(static fn(DemoSource $source): DemoTrack => new DemoTrack(
                $source->label,
                $source->target($stage->directory())->name(),
                $source->seconds(),
            )),
        );
    }

    /**
     * The entry as `data/demos.json` holds it, on its own: `{"slug": {…}}`, for `--print`.
     *
     * @param DemoStage $stage
     * @param Password  $password
     * @return string
     */
    public static function write(DemoStage $stage, Password $password): string
    {
        $entry = new stdClass();
        $entry->{$stage->slug} = DemoCodec::demoValue(self::demo($stage, $password));

        return Record::encode($entry);
    }

    /**
     * $demo with $password in place of its own, and everything else as it was — `--rotate`, which
     * is not restaging: the audio is where it was and the entry is right but for one field.
     *
     * @param Demo     $demo
     * @param Password $password
     * @return Demo
     */
    public static function rotated(Demo $demo, Password $password): Demo
    {
        return new Demo($demo->title, $password->hash, $demo->tracks, $demo->description);
    }
}
