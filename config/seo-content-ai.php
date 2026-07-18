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

    /** Múi giờ hiển thị lên lịch publish & queue sync trong panel SEO. */
    'display_timezone' => env('SEO_CONTENT_AI_DISPLAY_TIMEZONE', 'Asia/Ho_Chi_Minh'),

    /**
     * Phase 4A — per-caller migration mode: legacy | shadow | action.
     * Không dùng một global boolean.
     */
    'automation_migration' => [
        'seo_issue_assignment' => env('AUTOMATION_MIGRATION_SEO_ISSUE_ASSIGNMENT', 'legacy'),
        'keyword_project_assignment' => env('AUTOMATION_MIGRATION_KEYWORD_PROJECT_ASSIGNMENT', 'legacy'),
        'project_article_attach' => env('AUTOMATION_MIGRATION_PROJECT_ARTICLE_ATTACH', 'legacy'),
        'project_task_complete' => env('AUTOMATION_MIGRATION_PROJECT_TASK_COMPLETE', 'legacy'),
        'project_article_create' => env('AUTOMATION_MIGRATION_PROJECT_ARTICLE_CREATE', 'legacy'),
        'project_article_content_update' => env('AUTOMATION_MIGRATION_PROJECT_ARTICLE_CONTENT_UPDATE', 'legacy'),
        'project_article_seo_meta_update' => env('AUTOMATION_MIGRATION_PROJECT_ARTICLE_SEO_META_UPDATE', 'legacy'),
    ],

    /** Số sample parity tối thiểu trước khi promote caller sang action. */
    'automation_migration_min_parity_samples' => (int) env('AUTOMATION_MIGRATION_MIN_PARITY_SAMPLES', 20),

    /**
     * Thứ tự bật shadow staging (Group 1). Không auto-apply — ops set env từng bước.
     *
     * @var list<string>
     */
    'automation_migration_shadow_order' => [
        'seo_issue_assignment',
        'keyword_project_assignment',
        'project_article_attach',
        'project_task_complete',
    ],

    /**
     * Phase 5B — Prompt Hook runtime modes. Default legacy. Live AI shadow OFF.
     */
    'prompt_hooks' => [
        'live_shadow_enabled' => (bool) env('PROMPT_HOOK_LIVE_SHADOW_ENABLED', false),
        'live_shadow_environments' => ['local', 'staging'],
        'live_shadow_hook_allowlist' => [],
        'live_shadow_sample_rate' => (float) env('PROMPT_HOOK_LIVE_SHADOW_SAMPLE_RATE', 0),
        'live_shadow_allow_memory_budget' => (bool) env('PROMPT_HOOK_LIVE_SHADOW_ALLOW_MEMORY_BUDGET', false),
        'budget_store' => env('PROMPT_HOOK_BUDGET_STORE', 'memory'),
        // Fallback when per-hook map missing. Prefer promotion_thresholds.hooks.
        'promotion_min_samples' => (int) env('PROMPT_HOOK_PROMOTION_MIN_SAMPLES', 20),
        'promotion_thresholds' => [
            'default' => (int) env('PROMPT_HOOK_PROMOTION_MIN_SAMPLES', 20),
            'hooks' => [
                'article.outline.generate' => (int) env('PROMPT_HOOK_PROMOTION_SAMPLES_OUTLINE', 20),
                'article.faq.generate' => (int) env('PROMPT_HOOK_PROMOTION_SAMPLES_FAQ', 20),
                'keyword.discovery.structured' => (int) env('PROMPT_HOOK_PROMOTION_SAMPLES_KEYWORD', 20),
                'article.title_suggestion' => (int) env('PROMPT_HOOK_PROMOTION_SAMPLES_TITLE', 30),
                'article.meta_description_suggestion' => (int) env('PROMPT_HOOK_PROMOTION_SAMPLES_META', 30),
            ],
        ],
        'cost_rates' => [
            // Optional catalog — empty = no estimated_cost. Example:
            // 'gemini' => ['*' => ['input_per_1m' => 0.1, 'output_per_1m' => 0.4]],
        ],
        'experimental_allowed' => (bool) env('PROMPT_HOOK_EXPERIMENTAL_ALLOWED', true),
        'experimental_allowlist' => [
            'article.title_suggestion',
            'article.meta_description_suggestion',
            'article.outline.generate',
            'article.content.generate',
            'article.content.rewrite',
            'article.faq.generate',
            'keyword.discovery.structured',
        ],
        'migration' => [
            'article.title_suggestion' => env('PROMPT_HOOK_MIGRATION_ARTICLE_TITLE_SUGGESTION', 'legacy'),
            'article.meta_description_suggestion' => env('PROMPT_HOOK_MIGRATION_ARTICLE_META_DESCRIPTION_SUGGESTION', 'legacy'),
            'article.outline.generate' => env('PROMPT_HOOK_MIGRATION_ARTICLE_OUTLINE_GENERATE', 'legacy'),
            'article.content.generate' => env('PROMPT_HOOK_MIGRATION_ARTICLE_CONTENT_GENERATE', 'legacy'),
            'article.content.rewrite' => env('PROMPT_HOOK_MIGRATION_ARTICLE_CONTENT_REWRITE', 'legacy'),
            'article.faq.generate' => env('PROMPT_HOOK_MIGRATION_ARTICLE_FAQ_GENERATE', 'legacy'),
            'keyword.discovery.structured' => env('PROMPT_HOOK_MIGRATION_KEYWORD_DISCOVERY_STRUCTURED', 'legacy'),
        ],
    ],

    'content_project' => [
        /** Run item status=processing older than this (minutes) may be reclaimed. */
        'run_item_stale_minutes' => (int) env('SEO_CONTENT_PROJECT_RUN_ITEM_STALE_MINUTES', 30),
    ],
];
