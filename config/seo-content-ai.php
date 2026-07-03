<?php

declare(strict_types=1);

return [
    'connection' => 'omi_seo_ai',

    'service_slug' => 'seo-content-ai',

    /** DB dùng chung khi chưa cấu hình per-site (tương thích môi trường hiện tại). */
    'legacy_shared_database' => env('SEO_CONTENT_AI_LEGACY_DB', 'omi_seo_ai'),

    /** Tiền tố DB khi db_config_type = auto (Docker production). */
    'auto_database_prefix' => env('SEO_CONTENT_AI_AUTO_DB_PREFIX', 'omi_seo_ai'),

    /**
     * true → auto dùng {prefix}_{site_id}; false → dùng legacy_shared_database.
     * Bật khi mỗi site có database riêng trên cùng MySQL host.
     */
    'auto_per_site_database' => (bool) env('SEO_CONTENT_AI_PER_SITE_DB', false),

    'migrations_path' => 'app/Addons/SeoContentAi/database/migrations',

    /** Loại cấu hình DB mặc định khi tạo connection mới: auto | manual */
    'default_connection_type' => env('SEO_CONTENT_AI_DEFAULT_CONNECTION_TYPE', 'manual'),

    /** Số bản ghi đọc mỗi lô khi export SQL thuần PHP. */
    'db_export_chunk_size' => (int) env('SEO_CONTENT_AI_DB_EXPORT_CHUNK', 750),

    /** Số dòng INSERT gom vào một câu lệnh INSERT. */
    'db_export_insert_batch_size' => (int) env('SEO_CONTENT_AI_DB_EXPORT_INSERT_BATCH', 100),

    /** Ngưỡng (bytes) chuyển import sang queue job. */
    'db_import_queue_threshold' => (int) env('SEO_CONTENT_AI_DB_IMPORT_QUEUE_THRESHOLD', 5 * 1024 * 1024),

    /** Giới hạn upload file SQL backup (kilobytes) — đồng bộ Livewire + Filament FileUpload. */
    'db_import_max_upload_kb' => (int) env('SEO_CONTENT_AI_DB_IMPORT_MAX_UPLOAD_KB', 512000),

    /** Thư mục tạm (disk local) cho backup/import SQL. */
    'db_backup_storage_dir' => 'seo-db-backups',

    /** Số bài/lần khi đồng bộ bổ sung (tránh timeout Livewire). */
    'incremental_sync_chunk_size' => (int) env('SEO_CONTENT_AI_INCREMENTAL_SYNC_CHUNK', 15),

    /** Giới hạn upload ảnh thư viện SEO (kilobytes). */
    'media_max_upload_kb' => (int) env('SEO_CONTENT_AI_MEDIA_MAX_UPLOAD_KB', 10240),
];
