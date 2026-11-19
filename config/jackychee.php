<?php

return [
    /*
     | Old WordPress install (used only by the `wp:import` commands)
     */
    'wordpress' => [
        'table_prefix' => env('WP_TABLE_PREFIX', 'jackycheedb_'),
        'uploads_path' => env('WP_UPLOADS_PATH'),
    ],

    /*
     | Portfolio image handling
     */
    'images' => [
        'disk' => 'public',
        'max_upload_kb' => 15360, // 15 MB
        'thumb_width' => 448,     // grid thumbnail (2x the 224px column for retina)
        'large_width' => 2000,    // lightbox image
        'jpeg_quality' => 85,
    ],

    /*
     | Category codes used in the original upload file names (fallback importer)
     | e.g. "021_adv_Ocean-Park.jpg" -> advertising
     */
    'filename_categories' => [
        'adv' => 'Advertising',
        'celeb' => 'Celebrity',
        'fnb' => 'Fashion & Beauty',
        'per' => 'Personal',
    ],
];
