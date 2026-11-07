<?php

declare(strict_types=1);

namespace NeuroSYS\Service\Data;

use NeuroSYS\Exception\ReleaseVerificationException;
use NeuroSYS\Model\Embed\Embed;
use NeuroSYS\Model\Embed\SoundCloudEmbed;
use NeuroSYS\Model\Embed\SoundCloudOption;
use NeuroSYS\Model\Embed\SoundCloudPlayerStyle;
use NeuroSYS\Model\Format;
use NeuroSYS\Model\Genre;
use NeuroSYS\Model\Link\FileLink;
use NeuroSYS\Model\Link\HiDriveLink;
use NeuroSYS\Model\MusicalKey;
use NeuroSYS\Model\Production\Arrangement;
use NeuroSYS\Model\Production\Plugin;
use NeuroSYS\Model\Production\ProductionTime;
use NeuroSYS\Model\Production\Section;
use NeuroSYS\Model\Production\SectionKind;
use NeuroSYS\Model\Release;
use NeuroSYS\Model\ReleaseFormat;
use Phpanta\Support\SearchableCollection;
use stdClass;

/**
 * The ReleaseCodec class. `data/releases.json` read into {@link Release}s, and written back out of them.
 *
 * **The file holds what a release is made of, never what is worked out from it**: a HiDrive share
 * id rather than its URL, a section's tick rather than its timestamp, the time spent in seconds
 * rather than as it is shown. Enums are written as their values — `"D# Minor"`, `"Dubstep"`,
 * `"flac"` — and a description as its text in each language, or as one string that reads the same
 * in all of them.
 *
 * A field written at its default is left out — a section whose kind its label already gives, an
 * embed's player style and options — so a release reads the way it was meant rather than the way
 * the model fills it in, and {@link self::encode()} of what {@link self::decode()} read is the file
 * it read, byte for byte.
 */
final readonly class ReleaseCodec
{
    /**
     * Every release $json holds, slug-keyed, in its order.
     *
     * @param string $json
     * @param string $where The file it came from, for a refusal.
     * @return SearchableCollection<Release>
     * @throws ReleaseVerificationException if anything in it is not a release.
     */
    public static function decode(string $json, string $where): SearchableCollection
    {
        $releases = new SearchableCollection(Release::class);

        foreach (Record::parse($json, $where)->entries() as $slug => $record) {
            /** @var Record $record */
            $releases = $releases->with((string) $slug, self::release($record));
        }

        return $releases;
    }

    /**
     * $releases as `data/releases.json` holds them.
     *
     * @param SearchableCollection<Release> $releases
     * @return string
     * @throws ReleaseVerificationException if a release holds something the file cannot.
     */
    public static function encode(SearchableCollection $releases): string
    {
        $file = new stdClass();
        foreach ($releases as $slug => $release) {
            /** @var Release $release */
            $file->{(string) $slug} = self::releaseValue($release);
        }

        return Record::encode($file);
    }

    /**
     * One release, as the file holds it.
     *
     * @param Release $release
     * @return stdClass
     * @throws ReleaseVerificationException if it holds a link or an embed the file has no shape for.
     */
    public static function releaseValue(Release $release): stdClass
    {
        $value = new stdClass();
        $value->{RecordKey::Title->value}       = $release->title;
        $value->{RecordKey::Bpm->value}         = $release->bpm;
        $value->{RecordKey::Key->value}         = $release->key->value;
        $value->{RecordKey::Genre->value}       = $release->genre->value;
        $value->{RecordKey::Description->value} = Record::textValue($release->description);
        $value->{RecordKey::Cover->value}       = self::linkValue($release->cover);
        $value->{RecordKey::Formats->value}     = $release->formats
            ->map(static function (Format $format): stdClass {
                $value = new stdClass();
                $value->{RecordKey::Type->value} = $format->type->value;
                $value->{RecordKey::Link->value} = self::linkValue($format->link);

                return $value;
            })
            ->toValues();
        if ($release->embed !== null) {
            $value->{RecordKey::Embed->value} = self::embedValue($release->embed);
        }
        if ($release->arrangement !== null) {
            $value->{RecordKey::Arrangement->value} = self::arrangementValue($release->arrangement);
        }
        if ($release->timeSpent !== null) {
            $value->{RecordKey::TimeSpent->value} = $release->timeSpent->seconds;
        }
        if (!$release->madeWith->isEmpty()) {
            $value->{RecordKey::MadeWith->value} = $release->madeWith
                ->map(static fn(Plugin $plugin): string => $plugin->name)
                ->toValues();
        }

        return $value;
    }

    /**
     * @param Record $record
     * @return Release
     * @throws ReleaseVerificationException
     */
    private static function release(Record $record): Release
    {
        $timeSpent = $record->optionalInt(RecordKey::TimeSpent);

        return new Release(
            title:       $record->string(RecordKey::Title),
            bpm:         $record->int(RecordKey::Bpm),
            key:         $record->enum(RecordKey::Key, MusicalKey::class),
            genre:       $record->enum(RecordKey::Genre, Genre::class),
            description: $record->text(RecordKey::Description) ?? '',
            cover:       self::link($record->optionalString(RecordKey::Cover)),
            formats:     $record->records(RecordKey::Formats)->map(static fn(Record $format): Format => new Format(
                $format->enum(RecordKey::Type, ReleaseFormat::class),
                self::link($format->optionalString(RecordKey::Link)),
            ))->settled(),
            embed:       self::embed($record->optionalRecord(RecordKey::Embed)),
            arrangement: self::arrangement($record->optionalRecord(RecordKey::Arrangement)),
            timeSpent:   $timeSpent === null ? null : new ProductionTime($timeSpent),
            madeWith:    $record->strings(
                RecordKey::MadeWith,
                Plugin::class,
                static fn(string $name): Plugin => new Plugin($name),
            ),
        );
    }

    /**
     * @param string|null $shareId
     * @return FileLink|null
     * @throws ReleaseVerificationException if it is not a share id.
     */
    private static function link(?string $shareId): ?FileLink
    {
        return $shareId === null ? null : new HiDriveLink($shareId);
    }

    /**
     * @param FileLink|null $link
     * @return string|null
     * @throws ReleaseVerificationException if it is a link the file has no shape for.
     */
    private static function linkValue(?FileLink $link): ?string
    {
        return match (true) {
            $link === null                => null,
            $link instanceof HiDriveLink  => $link->shareId,
            default => throw new ReleaseVerificationException(sprintf(
                'A %s cannot be written to the releases file, which holds HiDrive share ids.',
                get_debug_type($link),
            )),
        };
    }

    /**
     * @param Record|null $record
     * @return Embed|null
     * @throws ReleaseVerificationException
     */
    private static function embed(?Record $record): ?Embed
    {
        if ($record === null) {
            return null;
        }

        return new SoundCloudEmbed(
            trackId:     $record->int(RecordKey::TrackId),
            permalink:   $record->string(RecordKey::Permalink),
            secretToken: $record->optionalString(RecordKey::SecretToken) ?? '',
            style:       $record->optionalEnum(RecordKey::Style, SoundCloudPlayerStyle::class)
                ?? SoundCloudPlayerStyle::Visual,
            options:     $record->present(RecordKey::Options)
                ? $record->strings(
                    RecordKey::Options,
                    SoundCloudOption::class,
                    static fn(string $option): SoundCloudOption => SoundCloudOption::tryFrom($option)
                        ?? throw new ReleaseVerificationException(sprintf(
                            "%s.%s: names no SoundCloudOption ('%s').",
                            $record->where,
                            RecordKey::Options->value,
                            $option,
                        )),
                )
                : null,
        );
    }

    /**
     * @param Embed $embed
     * @return stdClass
     * @throws ReleaseVerificationException if it is an embed the file has no shape for.
     */
    private static function embedValue(Embed $embed): stdClass
    {
        if (!$embed instanceof SoundCloudEmbed) {
            throw new ReleaseVerificationException(sprintf(
                'A %s cannot be written to the releases file, which holds SoundCloud tracks.',
                get_debug_type($embed),
            ));
        }

        $value = new stdClass();
        $value->{RecordKey::TrackId->value}   = $embed->trackId;
        $value->{RecordKey::Permalink->value} = $embed->permalink;
        if ($embed->secretToken !== '') {
            $value->{RecordKey::SecretToken->value} = $embed->secretToken;
        }
        if ($embed->style !== SoundCloudPlayerStyle::Visual) {
            $value->{RecordKey::Style->value} = $embed->style->value;
        }
        $valueOf = static fn(SoundCloudOption $option): string => $option->value;
        $options = $embed->options->map($valueOf)->toValues();
        $default = SoundCloudEmbed::defaultOptions()->map($valueOf)->toValues();
        if ($options !== $default) {
            $value->{RecordKey::Options->value} = $options;
        }

        return $value;
    }

    /**
     * @param Record|null $record
     * @return Arrangement|null
     * @throws ReleaseVerificationException
     */
    private static function arrangement(?Record $record): ?Arrangement
    {
        if ($record === null) {
            return null;
        }

        return new Arrangement(
            $record->records(RecordKey::Sections)->map(static function (Record $section): Section {
                $label = $section->string(RecordKey::Label);
                $tick  = $section->int(RecordKey::Tick);

                // A kind written down, null included, is the kind; none written is the label's.
                return $section->present(RecordKey::Kind)
                    ? new Section($label, $tick, $section->optionalEnum(RecordKey::Kind, SectionKind::class))
                    : Section::named($label, $tick);
            })->settled(),
            $record->int(RecordKey::Ppq),
        );
    }

    /**
     * @param Arrangement $arrangement
     * @return stdClass
     */
    private static function arrangementValue(Arrangement $arrangement): stdClass
    {
        $value = new stdClass();
        $value->{RecordKey::Ppq->value}      = $arrangement->ppq;
        $value->{RecordKey::Sections->value} = $arrangement->sections
            ->map(static function (Section $section): stdClass {
                $value = new stdClass();
                $value->{RecordKey::Label->value} = $section->label;
                $value->{RecordKey::Tick->value}  = $section->tick;
                if ($section->kind !== SectionKind::classify($section->label)) {
                    $value->{RecordKey::Kind->value} = $section->kind?->value;
                }

                return $value;
            })
            ->toValues();

        return $value;
    }
}
