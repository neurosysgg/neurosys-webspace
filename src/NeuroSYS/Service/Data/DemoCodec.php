<?php

declare(strict_types=1);

namespace NeuroSYS\Service\Data;

use NeuroSYS\Exception\ReleaseVerificationException;
use NeuroSYS\Model\Demo;
use NeuroSYS\Model\DemoTrack;
use Phpanta\Exception\InvalidValueException;
use Phpanta\Support\PasswordHash;
use Phpanta\Support\SearchableCollection;
use stdClass;

/**
 * The DemoCodec class. `data/demos.json` read into {@link Demo}s, and written back out of them.
 *
 * **Two readers hold this file to one shape**: this one, for PHP on Strato and for the tools, and
 * `app/views/demos.c`, for the native host — which refuses what this refuses (a blank title, a
 * password that is not a digest, a track label or file name that cannot be addressed), and reads a
 * description as the one string it is. A demo's description is never a catalog case and never more
 * than one language: `src/` is public, and so would the words be.
 */
final readonly class DemoCodec
{
    /**
     * Every demo $json holds, slug-keyed, in its order.
     *
     * @param string $json
     * @param string $where The file it came from, for a refusal.
     * @return SearchableCollection<Demo>
     * @throws ReleaseVerificationException if anything in it is not a demo.
     */
    public static function decode(string $json, string $where): SearchableCollection
    {
        $demos = new SearchableCollection(Demo::class);

        foreach (Record::parse($json, $where)->entries() as $slug => $record) {
            /** @var Record $record */
            $demos = $demos->with((string) $slug, self::demo($record));
        }

        return $demos;
    }

    /**
     * $demos as `data/demos.json` holds them.
     *
     * @param SearchableCollection<Demo> $demos
     * @return string
     * @throws ReleaseVerificationException
     */
    public static function encode(SearchableCollection $demos): string
    {
        $file = new stdClass();
        foreach ($demos as $slug => $demo) {
            /** @var Demo $demo */
            $file->{(string) $slug} = self::demoValue($demo);
        }

        return Record::encode($file);
    }

    /**
     * One demo, as the file holds it.
     *
     * @param Demo $demo
     * @return stdClass
     */
    public static function demoValue(Demo $demo): stdClass
    {
        $value = new stdClass();
        $value->{RecordKey::Title->value}       = $demo->title;
        $value->{RecordKey::Password->value}    = $demo->password->digest();
        $value->{RecordKey::Description->value} = $demo->description === null
            ? null
            : Record::textValue($demo->description);
        $value->{RecordKey::Tracks->value}      = $demo->tracks
            ->map(static function (DemoTrack $track): stdClass {
                $value = new stdClass();
                $value->{RecordKey::Label->value}   = $track->label;
                $value->{RecordKey::File->value}    = $track->file;
                $value->{RecordKey::Seconds->value} = $track->seconds;

                return $value;
            })
            ->toValues();

        return $value;
    }

    /**
     * @param Record $record
     * @return Demo
     * @throws ReleaseVerificationException
     */
    private static function demo(Record $record): Demo
    {
        $digest = $record->string(RecordKey::Password);
        try {
            $password = new PasswordHash($digest);
        } catch (InvalidValueException $e) {
            throw new ReleaseVerificationException(sprintf(
                '%s.%s: %s',
                $record->where,
                RecordKey::Password->value,
                $e->getMessage(),
            ));
        }

        return new Demo(
            title:       $record->string(RecordKey::Title),
            password:    $password,
            tracks:      $record->records(RecordKey::Tracks)->map(static fn(Record $track): DemoTrack => new DemoTrack(
                $track->string(RecordKey::Label),
                $track->string(RecordKey::File),
                $track->optionalInt(RecordKey::Seconds) ?? 0,
            ))->settled(),
            description: $record->optionalString(RecordKey::Description),
        );
    }
}
