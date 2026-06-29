<?php
declare(strict_types=1);

use NeuroSYS\Model\ReleaseFormat;
use NeuroSYS\Model\Format;
use NeuroSYS\Model\MusicalKey;
use NeuroSYS\Model\Release;
use NeuroSYS\Support\Collection;

/** @noinspection HtmlDeprecatedAttribute -> comes from SoundClouds embed HTML; we're not touching it. */
/** @noinspection PhpUnhandledExceptionInspection -> no need to overcomplicate handling for now; test it and it will work.*/
return [
    'hello-world' => new Release(
        'hello world!',
        140,
        MusicalKey::FSharpMajor,
        'debut single',
        '<iframe width="100%" height="300" scrolling="no" frameborder="no" allow="autoplay; encrypted-media" src="https://w.soundcloud.com/player/?url=https%3A//api.soundcloud.com/tracks/soundcloud%3Atracks%3A2340758756%3Fsecret_token%3Ds-D3cfG0dzbeB&color=%239e55e6&auto_play=true&hide_related=false&show_comments=true&show_user=true&show_reposts=false&show_teaser=true&visual=true"></iframe><div style="font-size: 10px; color: #cccccc;line-break: anywhere;word-break: normal;overflow: hidden;white-space: nowrap;text-overflow: ellipsis; font-family: Interstate,Lucida Grande,Lucida Sans Unicode,Lucida Sans,Garuda,Verdana,Tahoma,sans-serif;font-weight: 100;"><a href="https://soundcloud.com/neurosysgg" title="neuro.SYS" target="_blank" style="color: #cccccc; text-decoration: none;">neuro.SYS</a> · <a href="https://soundcloud.com/neurosysgg/hello-world/s-D3cfG0dzbeB" title="hello world!" target="_blank" style="color: #cccccc; text-decoration: none;">hello world!</a></div></iframe><div style="font-size: 10px; color: #cccccc;line-break: anywhere;word-break: normal;overflow: hidden;white-space: nowrap;text-overflow: ellipsis; font-family: Interstate,Lucida Grande,Lucida Sans Unicode,Lucida Sans,Garuda,Verdana,Tahoma,sans-serif;font-weight: 100;"><a href="https://soundcloud.com/neuro-sys" title="neuro.SYS" target="_blank" style="color: #cccccc; text-decoration: none;">neuro.SYS</a> · <a href="https://soundcloud.com/neuro-sys/hello-world/s-D3cfG0dzbeB" title="hello world!" target="_blank" style="color: #cccccc; text-decoration: none;">hello world!</a></div>',
        'https://my.hidrive.com/api/sharelink/download?id=PFGaSOmtM',
        new Collection(Format::class)->add(
            new Format(ReleaseFormat::FLAC, 'https://my.hidrive.com/api/sharelink/download?id=ebiFGBt52'),
            new Format(ReleaseFormat::WAV, 'https://my.hidrive.com/api/sharelink/download?id=QX98AVCDz'),
            new Format(ReleaseFormat::MP3, 'https://my.hidrive.com/api/sharelink/download?id=GATvNadI8'),
            new Format(ReleaseFormat::STEMS, 'https://my.hidrive.com/api/sharelink/download?id=O6PtraldH'),
        )
    )
];
