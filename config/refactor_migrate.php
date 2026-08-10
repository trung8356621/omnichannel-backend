<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Destructive migrate-fresh guards
    |--------------------------------------------------------------------------
    */
    'disposable_database_patterns' => [
        '*_test',
        '*_testing',
        'test_*',
        'phpunit_*',
        'pest_*',
    ],

    /**
     * Exact names that must NEVER be dropped — even with --confirm-destroy-test-db.
     */
    'protected_database_names' => [
        'omi_channel',
        'omi_seo_ai',
        'omi_channel_real',
        'omi_seo_ai_real',
    ],

    /**
     * Name patterns treated as protected (production / real local).
     */
    'protected_database_patterns' => [
        '*_real',
        '*_prod',
        '*_production',
        'production',
        'prod',
    ],

    /*
    |--------------------------------------------------------------------------
    | Row-count verification tables (connection => tables)
    |--------------------------------------------------------------------------
    */
    'verify_tables' => [
        'mysql' => [
            'users',
            'sites',
            'site_services',
            'seo_database_connections',
            'api_connections',
        ],
        'omi_seo_ai' => [
            'articles',
            'keywords',
            'prompts',
            'seo_projects',
            'seo_project_tasks',
            'seo_media',
            'seo_article_wp_sync_jobs',
            'seo_site_sync_runs',
            'seo_keywords',
            'keyword_rank_snapshots',
        ],
    ],

];
