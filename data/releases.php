<?php
declare(strict_types=1);

use NeuroSYS\Model\Embed\SoundCloudEmbed;
use NeuroSYS\Model\Format;
use NeuroSYS\Model\Genre;
use NeuroSYS\Model\Link\HiDriveLink;
use NeuroSYS\Model\MusicalKey;
use NeuroSYS\Model\Release;
use NeuroSYS\Model\ReleaseFormat;
use NeuroSYS\Support\Collection;

/** @noinspection PhpUnhandledExceptionInspection -> no need to overcomplicate handling for now; test it and it will work.*/
return [
    'ill' => new Release(
        title:       'ill.',
        bpm:         140,
        key:         MusicalKey::DSharpMinor,
        genre:       Genre::Dubstep,
        description: 'second single',
        cover:       new HiDriveLink('J2FXbB70A'),
        formats: new Collection(Format::class)->with(
            new Format(ReleaseFormat::FLAC,  new HiDriveLink('BXRsy9S7d')),
            new Format(ReleaseFormat::WAV,   new HiDriveLink('RVg8LBS4A')),
            new Format(ReleaseFormat::MP3,   new HiDriveLink('CPJy7AVIu')),
            new Format(ReleaseFormat::STEMS, new HiDriveLink('D2PUDjoII')),
        ),
        embed: new SoundCloudEmbed(
            trackId:     2394077313,
            permalink:   'ill',
            secretToken: 's-dIMAqki109G',
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
            new Format(ReleaseFormat::FLAC,  new HiDriveLink('ebiFGBt52')),
            new Format(ReleaseFormat::WAV,   new HiDriveLink('QX98AVCDz')),
            new Format(ReleaseFormat::MP3,   new HiDriveLink('GATvNadI8')),
            new Format(ReleaseFormat::STEMS, new HiDriveLink('O6PtraldH')),
        ),
        embed: new SoundCloudEmbed(
            trackId:     2340758756,
            permalink:   'hello-world',
            secretToken: 's-D3cfG0dzbeB',
        ),
    ),
];
