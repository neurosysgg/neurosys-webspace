<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Exception\ReleaseVerificationException;
use NeuroSYS\Model\Format;
use NeuroSYS\Model\Genre;
use NeuroSYS\Model\Link\HiDriveLink;
use NeuroSYS\Model\MusicalKey;
use NeuroSYS\Model\Platform;
use NeuroSYS\Model\Release;
use NeuroSYS\Model\ReleaseFormat;
use NeuroSYS\Support\Collection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Release::class)]
#[CoversClass(Format::class)]
#[CoversClass(ReleaseFormat::class)]
#[CoversClass(MusicalKey::class)]
#[CoversClass(Genre::class)]
#[CoversClass(Platform::class)]
#[CoversClass(HiDriveLink::class)]
final class ModelTest extends TestCase
{
    private function release(Format ...$formats): Release
    {
        return new Release(
            title:       'ill.',
            bpm:         140,
            key:         MusicalKey::DSharpMinor,
            genre:       Genre::Dubstep,
            description: 'second single',
            cover:       null,
            formats:     new Collection(Format::class)->with(...$formats),
        );
    }

    // ───────────────────────────── Release ─────────────────────────────

    public function testFindFormatReturnsTheMatchingFormat(): void
    {
        $release = $this->release(
            new Format(ReleaseFormat::FLAC, new HiDriveLink('BXRsy9S7d')),
            new Format(ReleaseFormat::MP3, new HiDriveLink('CPJy7AVIu')),
        );

        self::assertSame(ReleaseFormat::MP3, $release->findFormat(ReleaseFormat::MP3)?->type);
    }

    public function testFindFormatReturnsNullForAFormatThisReleaseDoesNotHave(): void
    {
        self::assertNull($this->release(new Format(ReleaseFormat::FLAC))->findFormat(ReleaseFormat::OGG));
    }

    /**
     * The segment never reaches findFormat() as a string any more: DownloadController resolves it
     * with ReleaseFormat::tryFrom() first, so a path like this is a null before the lookup happens.
     */
    public function testAUrlSegmentThatIsNotAFormatResolvesToNothing(): void
    {
        self::assertNull(ReleaseFormat::tryFrom('../../etc/passwd'));
    }

    public function testRejectsANonPositiveBpm(): void
    {
        $this->expectException(ReleaseVerificationException::class);
        $this->expectExceptionMessage('bpm must be greater than 0');

        new Release('t', 0, MusicalKey::CMajor, Genre::Dubstep, 'd', null, new Collection(Format::class));
    }

    public function testRejectsACollectionOfTheWrongType(): void
    {
        $this->expectException(ReleaseVerificationException::class);

        new Release('t', 140, MusicalKey::CMajor, Genre::Dubstep, 'd', null, new Collection(Genre::class));
    }

    /**
     * A staged release declares the format but not its link yet; DownloadController keys
     * its 503 branch off exactly this being null.
     */
    public function testAFormatDeclaredWithoutALinkHasANullLink(): void
    {
        self::assertNull($this->release(new Format(ReleaseFormat::FLAC))->findFormat(ReleaseFormat::FLAC)?->link);
    }

    // ─────────────────────────── ReleaseFormat ───────────────────────────

    public static function losslessProvider(): iterable
    {
        yield [ReleaseFormat::FLAC, true];
        yield [ReleaseFormat::WAV, true];
        yield [ReleaseFormat::AIFF, true];
        yield [ReleaseFormat::STEMS, true];
        yield [ReleaseFormat::MP3, false];
        yield [ReleaseFormat::OGG, false];
    }

    #[DataProvider('losslessProvider')]
    public function testIsLossless(ReleaseFormat $format, bool $expected): void
    {
        self::assertSame($expected, $format->isLossless());
    }

    public function testEveryFormatHasANonEmptyLabelAndALowercaseUrlSafeValue(): void
    {
        foreach (ReleaseFormat::cases() as $format) {
            self::assertNotSame('', $format->label());
            self::assertMatchesRegularExpression('/^[a-z0-9]+$/', $format->value);
        }
    }

    // ───────────────────── MusicalKey / Genre ─────────────────────

    public function testThereAreExactlyTwentyFourKeys(): void
    {
        self::assertCount(24, MusicalKey::cases());
    }

    public function testKeyAndGenreValuesAreUniqueAndNonEmpty(): void
    {
        foreach ([MusicalKey::cases(), Genre::cases()] as $cases) {
            $values = array_map(static fn($c) => $c->value, $cases);
            self::assertSame($values, array_unique($values));
            self::assertNotContains('', $values);
        }
    }

    // ───────────────────────────── Platform ─────────────────────────────

    public function testEveryPlatformHasALabelDisplayNameAndVendoredIcon(): void
    {
        foreach (Platform::cases() as $platform) {
            self::assertNotSame('', $platform->label(), "{$platform->value} has no label");
            self::assertNotSame('', $platform->displayName(), "{$platform->value} has no display name");
            self::assertGreaterThan(0, $platform->iconHeight(), "{$platform->value} has no height");
        }
    }

    /**
     * Icons are vendored, never hot-linked — a remote URL here would fire a request to the
     * platform on page load and make us a joint controller (CJEU C-40/17, "Fashion ID").
     */
    public function testEveryPlatformIconIsALocalPathAndTheFileExists(): void
    {
        foreach (Platform::cases() as $platform) {
            $src = $platform->iconSrc();

            self::assertStringStartsWith('/assets/img/brand/', $src, "{$platform->value} icon is not vendored");
            self::assertStringNotContainsString('//', substr($src, 1), "{$platform->value} icon looks remote");
            self::assertFileExists(
                NEUROSYS_ROOT . '/public' . $src,
                "{$platform->value} icon is referenced but not committed",
            );
        }
    }

    // ──────────────────────────── HiDriveLink ────────────────────────────

    public function testBuildsTheDirectDownloadUrlFromSubjectShareId(): void
    {
        self::assertSame(
            'https://my.hidrive.com/api/sharelink/download?id=BXRsy9S7d',
            new HiDriveLink('BXRsy9S7d')->url(),
        );
    }

    public static function badShareIdProvider(): iterable
    {
        yield 'too short'      => ['BXRsy9S7'];
        yield 'too long'       => ['BXRsy9S7dd'];
        yield 'empty'          => [''];
        yield 'hyphen'         => ['BXRsy-9S7'];
        yield 'whole url'      => ['https://my.hidrive.com/x'];
        yield 'trailing space' => ['BXRsy9S7 '];
        yield 'newline'        => ["BXRsy9S7d\n"];
        yield 'query fragment' => ['?id=BXRsy9'];
        yield 'unicode'        => ['BXRsy9S7é'];
    }

    #[DataProvider('badShareIdProvider')]
    public function testRejectsAMalformedShareId(string $id): void
    {
        $this->expectException(ReleaseVerificationException::class);
        new HiDriveLink($id);
    }

    public function testAcceptsAnyNineAlphanumericId(): void
    {
        foreach (['BXRsy9S7d', '123456789', 'abcdefghi', 'ABCDEFGHI'] as $id) {
            self::assertStringEndsWith("id=$id", new HiDriveLink($id)->url());
        }
    }

    /** The id is url-encoded into the query rather than concatenated raw. */
    public function testTheUrlIsBuiltWithQueryEncoding(): void
    {
        self::assertSame(
            'https://my.hidrive.com/api/sharelink/download?id=abcdefghi',
            new HiDriveLink('abcdefghi')->url(),
        );
    }
}
