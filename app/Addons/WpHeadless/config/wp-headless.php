<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Next.js wp-headless app URL
    |--------------------------------------------------------------------------
    | URL của project Next.js (wp-headless) để proxy request từ /site/{slug}.
    */
    'nextjs_url' => env('WP_HEADLESS_NEXTJS_URL', 'http://127.0.0.1:3000'),
];
