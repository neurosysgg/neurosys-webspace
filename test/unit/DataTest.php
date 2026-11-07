<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\DataFile;
use NeuroSYS\Exception\ReleaseVerificationException;
use NeuroSYS\Model\Demo;
use NeuroSYS\Model\DemoTrack;
use NeuroSYS\Model\Embed\Embed;
use NeuroSYS\Model\Embed\SoundCloudEmbed;
use NeuroSYS\Model\Embed\SoundCloudOption;
use NeuroSYS\Model\Embed\SoundCloudPlayerStyle;
use NeuroSYS\Model\Genre;
use NeuroSYS\Model\Link\FileLink;
use NeuroSYS\Model\MusicalKey;
use NeuroSYS\Model\Platform;
use NeuroSYS\Model\Production\Arrangement;
use NeuroSYS\Model\Production\Section;
use NeuroSYS\Model\Production\SectionKind;
use NeuroSYS\Model\Release;
use NeuroSYS\Model\ReleaseFormat;
use NeuroSYS\Service\Data\DemoCodec;
use NeuroSYS\Service\Data\Record;
use NeuroSYS\Service\Data\RecordKey;
use NeuroSYS\Service\Data\ReleaseCodec;
use NeuroSYS\Service\DemoRepository;
use NeuroSYS\Service\ProfileRepository;
use NeuroSYS\Service\ReleaseRepository;
use NeuroSYS\Site;
use Phpanta\Support\Collection;
use Phpanta\Support\Directory;
use Phpanta\Support\File;
use Phpanta\Support\PasswordHash;
use Phpanta\Support\SearchableCollection;
use Phpanta\Text\Language;
use Phpanta\Text\Translation;
use Phpanta\View\Html\Element;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The data files as JSON: what the codecs read, what they refuse — always naming where — and that
 * what they write is what they read.
 */
#[CoversClass(Record::class)]
#[CoversClass(RecordKey::class)]
#[CoversClass(ReleaseCodec::class)]
#[CoversClass(DemoCodec::class)]
#[CoversClass(ReleaseRepository::class)]
#[CoversClass(DemoRepository::class)]
#[CoversClass(ProfileRepository::class)]
final class DataTest extends TestCase
{
    /** The smallest release the file can hold. */
    private const string MINIMAL = '{"x": {"title": "x", "bpm": 140, "key": "C Major", "genre": "Dubstep",'
        . ' "description": "", "cover": null, "formats": []}}';

    /**
     * The catalogue the site ships reads, and writes back as itself, byte for byte.
     *
     * @return void
     */
    public function testTheShippedCatalogueRoundTrips(): void
    {
        $file = Site::current()->dataFile(DataFile::Releases);
        $json = (string) $file->read();

        self::assertSame($json, ReleaseCodec::encode(ReleaseCodec::decode($json, $file->path)));
    }

    /**
     * Every field the codec knows, at a value other than its default, survives the trip.
     *
     * @return void
     */
    public function testEveryFieldAwayFromItsDefaultRoundTrips(): void
    {
        $json = <<<'JSON'
            {"x": {"title": "x", "bpm": 128, "key": "F# Major", "genre": "Future Bass",
              "description": {"en": "only English"}, "cover": "J2FXbB70A",
              "formats": [{"type": "flac", "link": "BXRsy9S7d"}, {"type": "stems", "link": null}],
              "embed": {"trackId": 7, "permalink": "x", "style": "classic", "options": ["hide_related"]},
              "arrangement": {"ppq": 192, "sections": [{"label": "INTRO", "tick": 0},
                {"label": "INTRO", "tick": 10, "kind": "drop"}, {"label": "DROP", "tick": 20, "kind": null}]},
              "timeSpent": 61, "madeWith": ["Serum 2"]}}
            JSON;

        $release  = self::release($json);
        $again    = self::release(ReleaseCodec::encode(ReleaseCodec::decode($json, 'x')));
        $sections = $again->arrangement?->sections->toValues() ?? [];

        self::assertSame(MusicalKey::FSharpMajor, $again->key);
        self::assertSame(Genre::FutureBass, $again->genre);
        self::assertSame('only English', $again->description->in(Language::German), 'German falls back');
        self::assertNull($again->findFormat(ReleaseFormat::STEMS)?->link);
        self::assertInstanceOf(SoundCloudEmbed::class, $again->embed);
        self::assertSame(SoundCloudPlayerStyle::Classic, $again->embed->style);
        self::assertSame([SoundCloudOption::HideRelated], $again->embed->options->toValues());
        self::assertSame('', $again->embed->secretToken);
        self::assertSame(192, $again->arrangement?->ppq);
        self::assertSame(SectionKind::Intro, $sections[0]->kind);
        self::assertSame(SectionKind::Drop, $sections[1]->kind, 'a kind written down is the kind');
        self::assertNull($sections[2]->kind, 'and so is a null written down');
        self::assertSame(61, $again->timeSpent?->seconds);
        self::assertSame('Serum 2', $again->madeWith->first()?->name);
        self::assertEquals($release, $again);
    }

    /**
     * A description with the same text in every language reads back as one string.
     *
     * @return void
     */
    public function testAPlainDescriptionStaysAString(): void
    {
        $json = ReleaseCodec::encode(ReleaseCodec::decode(self::MINIMAL, 'x'));

        self::assertStringContainsString('"description": "",', $json);
        self::assertStringNotContainsString('"embed"', $json);
        self::assertStringNotContainsString('"madeWith"', $json);
    }

    /**
     * A description left out is the empty one the staging tool writes, and writes back as that.
     *
     * @return void
     */
    public function testAMissingDescriptionIsAnEmptyOne(): void
    {
        $release = self::release(str_replace(' "description": "",', '', self::MINIMAL));

        self::assertSame('', $release->description->in(Language::English));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedReleaseProvider(): iterable
    {
        $with = static fn(string $field): string => str_replace('"formats": []', $field, self::MINIMAL);
        $swap = static fn(string $from, string $to): string => str_replace($from, $to, self::MINIMAL);

        yield 'not JSON'               => ['{', 'x: not JSON'];
        yield 'not an object'          => ['[]', 'x: must be an object'];
        yield 'an entry not an object' => ['{"x": 1}', 'x: x: must be an object'];
        yield 'a missing title'        => [$swap('"title": "x", ', ''), 'x: x.title: is required'];
        yield 'a missing genre'        => [$swap(' "genre": "Dubstep",', ''), 'x: x.genre: is required'];
        yield 'a bpm as a string'      => [$swap('140', '"140"'), 'x: x.bpm: must be a whole number'];
        yield 'a title as a number'    => [$swap('"title": "x"', '"title": 1'), 'x: x.title: must be a string'];
        yield 'a key that is none'     => [$swap('C Major', 'C Minor-ish'), "names no MusicalKey ('C Minor-ish')"];
        yield 'formats not a list'     => [$with('"formats": {}'), 'x: x.formats: must be a list'];
        yield 'a format not an object' => [$with('"formats": [1]'), 'x: x.formats[0]: must be an object'];
        yield 'a format of no type'    => [
            $with('"formats": [{"type": "flacc"}]'),
            'x: x.formats[0].type: names no ReleaseFormat',
        ];
        yield 'a credit not a string'  => [
            $with('"formats": [], "madeWith": [1]'),
            'x: x.madeWith[0]: must be a string',
        ];
        yield 'an embed not an object'  => [$with('"formats": [], "embed": "x"'), 'x: x.embed: must be an object'];
        yield 'an option that is none'  => [
            $with('"formats": [], "embed": {"trackId": 1, "permalink": "x", "options": ["loud"]}'),
            "x: x.embed.options: names no SoundCloudOption ('loud')",
        ];
        yield 'a description of a number' => [
            str_replace('"description": ""', '"description": 1', self::MINIMAL),
            'x: x.description: must be a string, or an object with a text per language',
        ];
        yield 'a description with no English' => [
            str_replace('"description": ""', '"description": {"de": "nur deutsch"}', self::MINIMAL),
            'x: x.description.en: is required',
        ];
        yield 'a description in a language not offered' => [
            str_replace('"description": ""', '"description": {"en": "a", "fr": "b"}', self::MINIMAL),
            "x: x.description: 'fr' is not a language the site offers",
        ];
    }

    /**
     * Each refusal names the file, the entry and the field, so a hand edit is found where it is.
     *
     * @param string $json
     * @param string $message
     * @return void
     */
    #[DataProvider('refusedReleaseProvider')]
    public function testAReleaseFileThatIsWrongIsRefusedSayingWhere(string $json, string $message): void
    {
        $this->expectException(ReleaseVerificationException::class);
        $this->expectExceptionMessage($message);

        (void) ReleaseCodec::decode($json, 'x');
    }

    /**
     * The file holds HiDrive share ids and SoundCloud tracks; a release made of anything else
     * cannot be written to it, and says so rather than writing something that would not read.
     *
     * @return void
     */
    public function testALinkOrAPlayerTheFileHasNoShapeForIsRefusedOnTheWayOut(): void
    {
        $link = new class () implements FileLink {
            /** @return string */
            public function url(): string
            {
                return 'https://example.com/x';
            }
        };
        $base = self::release(self::MINIMAL);

        try {
            (void) ReleaseCodec::releaseValue(new Release(
                $base->title,
                $base->bpm,
                $base->key,
                $base->genre,
                '',
                $link,
                $base->formats,
            ));
            self::fail('a link that is not a share id was written');
        } catch (ReleaseVerificationException $e) {
            self::assertStringContainsString('holds HiDrive share ids', $e->getMessage());
        }

        $embed = new class () implements Embed {
            /** @return Platform */
            public function platform(): Platform
            {
                return Platform::YouTube;
            }

            /** @return int */
            public function height(): int
            {
                return 1;
            }

            /**
             * @param string $title
             * @return Element
             */
            public function toElement(string $title): Element
            {
                return new SoundCloudEmbed(1, 'x')->toElement($title);
            }
        };

        $this->expectException(ReleaseVerificationException::class);
        $this->expectExceptionMessage('holds SoundCloud tracks');

        (void) ReleaseCodec::releaseValue(new Release(
            $base->title,
            $base->bpm,
            $base->key,
            $base->genre,
            '',
            null,
            $base->formats,
            $embed,
        ));
    }

    /**
     * A section's kind is written only where its label does not already give it.
     *
     * @return void
     */
    public function testASectionsKindIsWrittenOnlyWhereItIsNotItsLabels(): void
    {
        $base     = self::release(self::MINIMAL);
        $sections = new Collection(Section::class)->with(
            Section::named('DROP', 0),
            new Section('DROP', 1, SectionKind::Break),
        );
        $json     = ReleaseCodec::encode(new SearchableCollection(Release::class)->with('x', new Release(
            $base->title,
            $base->bpm,
            $base->key,
            $base->genre,
            new Translation(en: 'a', de: 'b'),
            null,
            $base->formats,
            arrangement: new Arrangement($sections),
        )));

        self::assertSame(1, substr_count($json, '"kind"'));
        self::assertStringContainsString('"kind": "break"', $json);
        self::assertStringContainsString("\"en\": \"a\",\n", $json);
    }

    /**
     * Something that cannot be JSON — bytes that are not UTF-8 — is refused rather than written.
     *
     * @return void
     */
    public function testWhatCannotBeJsonIsRefused(): void
    {
        $this->expectException(ReleaseVerificationException::class);
        $this->expectExceptionMessage('cannot be written as JSON');

        (void) Record::encode("\xff");
    }

    /**
     * A demo reads, writes back as itself, and its description stays the one string it is.
     *
     * @return void
     */
    public function testADemoRoundTrips(): void
    {
        $digest = PasswordHash::mint('x', 100000)->digest();
        $json   = DemoCodec::encode(new SearchableCollection(Demo::class)->with('d', new Demo(
            'd',
            new PasswordHash($digest),
            new Collection(DemoTrack::class)->with(new DemoTrack('v1', 'v1.mp3', 3)),
            'listen to the drop',
        )));
        $demo   = DemoCodec::decode($json, 'x')->find('d');

        self::assertInstanceOf(Demo::class, $demo);
        self::assertSame($digest, $demo->password->digest());
        self::assertSame('listen to the drop', $demo->description?->in(Language::German));
        self::assertSame(3, $demo->findTrack('v1')?->seconds);
        self::assertSame($json, DemoCodec::encode(DemoCodec::decode($json, 'x')));
    }

    /**
     * A password that is not a digest is refused naming the field, before any gate meets it.
     *
     * @return void
     */
    public function testADemoWhosePasswordIsNotADigestIsRefusedSayingWhere(): void
    {
        $this->expectException(ReleaseVerificationException::class);
        $this->expectExceptionMessage('x: d.password: ');

        (void) DemoCodec::decode(
            '{"d": {"title": "d", "password": "hunter2", "tracks": [{"label": "v1", "file": "v1.mp3"}]}}',
            'x',
        );
    }

    /**
     * The catalogue refuses to be missing — an empty site would look like a working one — where the
     * demos file is gitignored and absent means none, and a demos file that does not read is an
     * error rather than none.
     *
     * @return void
     */
    public function testEachRepositoryMeetsAMissingOrBrokenFileAsItShould(): void
    {
        $fixtures = Directory::temporary('neurosys-data-');

        try {
            self::assertTrue(new DemoRepository($fixtures->file('demos.json'))->all()->isEmpty());

            try {
                (void) new ReleaseRepository($fixtures->file('releases.json'))->all();
                self::fail('a missing catalogue was taken for an empty one');
            } catch (ReleaseVerificationException $e) {
                self::assertStringContainsString('the catalogue is missing', $e->getMessage());
            }

            $broken = $fixtures->file('broken.json');
            $broken->write('{"d": ');

            $this->expectException(ReleaseVerificationException::class);
            $this->expectExceptionMessage('not JSON');

            (void) new DemoRepository($broken)->all();
        } finally {
            $fixtures->remove();
        }
    }

    /**
     * A profile's URL that is not a string is refused naming the platform.
     *
     * @return void
     */
    public function testAProfileThatIsNotAStringIsRefusedSayingWhere(): void
    {
        $fixtures = Directory::temporary('neurosys-data-');
        $file     = $fixtures->file('profiles.json');
        $file->write('{"github": 1}');

        try {
            $this->expectException(ReleaseVerificationException::class);
            $this->expectExceptionMessage('profiles.json.github: must be a string');

            (void) new ProfileRepository($file)->all();
        } finally {
            $fixtures->remove();
        }
    }

    /**
     * The one release in $json.
     *
     * @param string $json
     * @return Release
     */
    private static function release(string $json): Release
    {
        $release = ReleaseCodec::decode($json, 'x')->first();
        self::assertInstanceOf(Release::class, $release);

        return $release;
    }
}
