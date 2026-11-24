<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Host Video Tutorial yang Diizinkan
    |--------------------------------------------------------------------------
    |
    | Hanya tautan HTTPS ke host resmi berikut (atau subdomain sahnya) yang
    | diizinkan pada karya Galeri Plasvora.
    |
    */
    'allowed_video_hosts' => [
        'youtube.com',
        'youtu.be',
        'tiktok.com',
        'instagram.com',
    ],

    'min_images'       => 1,
    'max_images'       => 5,
    'max_image_kb'     => 4096,
    'max_video_links'  => 3,
    'default_per_page' => 12,
    'max_per_page'     => 24,
];
