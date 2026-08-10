<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Skipped addon slugs
    |--------------------------------------------------------------------------
    */

    'skip_slugs' => array_values(array_filter(array_map(
        static fn (string $slug): string => trim($slug),
        explode(',', (string) env('ADDON_SKIP_SLUGS', 'wp-headless')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Discovery roots (order matters — later overrides earlier on same slug)
    |--------------------------------------------------------------------------
    |
    | Peer addons live in /addons. Legacy monolith still under app/Addons until
    | physical extraction completes. Core never lists SeoContentAi by class name.
    |
    */

    'discovery_roots' => [
        'app/Addons',
        'addons',
    ],

    /*
    |--------------------------------------------------------------------------
    | Entitlement hook placeholder
    |--------------------------------------------------------------------------
    */

    'entitlement' => [
        'enabled' => (bool) env('ADDON_ENTITLEMENT_CHECKS', false),
        'resolver' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Peer addon capability catalog (documentation + validator hints)
    |--------------------------------------------------------------------------
    */

    'peer_slugs' => [
        'search-foundation',
        'seo',
        'search-intelligence',
        'ai-prompt',
        'content',
        'content-projects',
        'media',
        'wordpress',
        'publishing',
        'site-sync',
        'agent',
        'social',
        'commerce',
    ],

];
