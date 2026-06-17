<?php
declare(strict_types=1);

return [
    'hello-world' => [
        'title'       => 'hello world!',
        'bpm'         => 140,
        'key'         => 'F# major',
        'description' => 'debut single',
        'soundcloud_html'  => '<iframe width="100%" height="300" scrolling="no" frameborder="no" allow="autoplay; encrypted-media" src="https://w.soundcloud.com/player/?url=https%3A//api.soundcloud.com/tracks/soundcloud%3Atracks%3A2340758756%3Fsecret_token%3Ds-D3cfG0dzbeB&color=%23ff5500&auto_play=false&hide_related=false&show_comments=true&show_user=true&show_reposts=false&show_teaser=true&visual=true"></iframe><div style="font-size: 10px; color: #cccccc;line-break: anywhere;word-break: normal;overflow: hidden;white-space: nowrap;text-overflow: ellipsis; font-family: Interstate,Lucida Grande,Lucida Sans Unicode,Lucida Sans,Garuda,Verdana,Tahoma,sans-serif;font-weight: 100;"><a href="https://soundcloud.com/neuro-sys" title="neuro.SYS" target="_blank" style="color: #cccccc; text-decoration: none;">neuro.SYS</a> · <a href="https://soundcloud.com/neuro-sys/hello-world/s-D3cfG0dzbeB" title="hello world!" target="_blank" style="color: #cccccc; text-decoration: none;">hello world!</a></div>', // paste SoundCloud embed src here when live
        'cover_url' => 'https://my.hidrive.com/api/sharelink/download?id=PFGaSOmtM',
        'formats'     => [
            'flac'  => 'https://my.hidrive.com/api/sharelink/download?id=ebiFGBt52', // HiDrive direct-download link
//            'mp3'   => '', // HiDrive direct-download link
//            'stems' => '', // HiDrive direct-download link
        ],
    ],
];
