<?php

declare(strict_types=1);

use NeuroSYS\Model\Embed\SoundCloudEmbed;
use NeuroSYS\Model\Format;
use NeuroSYS\Model\Genre;
use NeuroSYS\Model\Link\HiDriveLink;
use NeuroSYS\Model\MusicalKey;
use NeuroSYS\Model\Production\Arrangement;
use NeuroSYS\Model\Production\Plugin;
use NeuroSYS\Model\Production\ProductionTime;
use NeuroSYS\Model\Production\Section;
use NeuroSYS\Model\Release;
use NeuroSYS\Model\ReleaseFormat;
use NeuroSYS\Support\Collection;

return [
    'ill' => new Release(
        title:       'ill.',
        bpm:         140,
        key:         MusicalKey::DSharpMinor,
        genre:       Genre::Dubstep,
        description: 'wub wub',
        cover:       new HiDriveLink('J2FXbB70A'),
        formats: new Collection(Format::class)->with(
            new Format(ReleaseFormat::FLAC, new HiDriveLink('BXRsy9S7d')),
            new Format(ReleaseFormat::WAV, new HiDriveLink('RVg8LBS4A')),
            new Format(ReleaseFormat::MP3, new HiDriveLink('CPJy7AVIu')),
            new Format(ReleaseFormat::STEMS, new HiDriveLink('D2PUDjoII')),
        ),
        embed: new SoundCloudEmbed(
            trackId:     2394077313,
            permalink:   'ill',
            secretToken: 's-dIMAqki109G',
        ),
        arrangement: new Arrangement(new Collection(Section::class)->with(
            Section::named('INTRO', 0),
            Section::named('BUILDUP', 6144),
            Section::named('DROP', 12288),
            Section::named('SWITCH 1', 15360),
            Section::named('SWITCH 2', 18432),
            Section::named('BRIDGE', 21504),
            Section::named('DROP', 27648),
            Section::named('SWITCH', 30720),
            Section::named('BUILDDOWN', 33792),
            Section::named('OUTRO', 36864),
        )),
        timeSpent: ProductionTime::of(60, 7),
        madeWith: new Collection(Plugin::class)->with(
            new Plugin('Serum 2'),
            new Plugin('Vocodex'),
            new Plugin('Patcher'),
            new Plugin('LuxeVerb'),
            new Plugin('Ozone Imager 2'),
        ),
    ),
    'hello-world' => new Release(
        title:       'hello world!',
        bpm:         140,
        key:         MusicalKey::FSharpMajor,
        genre:       Genre::FutureBass,
        description: 'debut single',
        cover:       new HiDriveLink('PFGaSOmtM'),
        formats: new Collection(Format::class)->with(
            new Format(ReleaseFormat::FLAC, new HiDriveLink('ebiFGBt52')),
            new Format(ReleaseFormat::WAV, new HiDriveLink('QX98AVCDz')),
            new Format(ReleaseFormat::MP3, new HiDriveLink('GATvNadI8')),
            new Format(ReleaseFormat::STEMS, new HiDriveLink('O6PtraldH')),
        ),
        embed: new SoundCloudEmbed(
            trackId:     2340758756,
            permalink:   'hello-world',
            secretToken: 's-D3cfG0dzbeB',
        ),
        arrangement: new Arrangement(new Collection(Section::class)->with(
            Section::named('INTRO', 0),
            Section::named('BUILDUP', 6144),
            Section::named('DROP', 12288),
            Section::named('BREAK', 18432),
            Section::named('DROP', 21504),
            Section::named('BUILDDOWN', 27648),
            Section::named('OUTRO', 30720),
        )),
        timeSpent: ProductionTime::of(42, 48),
        madeWith: new Collection(Plugin::class)->with(
            new Plugin('Serum 2'),
            new Plugin('Ozone Imager 2'),
            new Plugin('LFOTool'),
            new Plugin('Disperser'),
        ),
    ),
];
