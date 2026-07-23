<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Automation runtime connection
    |--------------------------------------------------------------------------
    |
    | Default = omi_seo_ai để deploy code trước cutover không đọc bảng core rỗng.
    | Sau copy+verify: set AUTOMATION_DB_CONNECTION=<DB_CORE_CONNECTION> (thường mysql).
    | Cài mới sạch: set AUTOMATION_DB_CONNECTION ngay từ đầu sang core.
    |
    */

    'connection' => env('AUTOMATION_DB_CONNECTION', 'omi_seo_ai'),

    /*
    |--------------------------------------------------------------------------
    | Data migration (automation:migrate-to-core)
    |--------------------------------------------------------------------------
    */

    'source_connection' => env('AUTOMATION_SOURCE_DB_CONNECTION', 'omi_seo_ai'),

    'target_connection' => env(
        'AUTOMATION_TARGET_DB_CONNECTION',
        env('DB_CORE_CONNECTION', env('DB_CONNECTION', 'mysql'))
    ),

    'chunk_size' => (int) env('AUTOMATION_MIGRATE_CHUNK_SIZE', 500),

    'report_directory' => 'automation-migration',

];
