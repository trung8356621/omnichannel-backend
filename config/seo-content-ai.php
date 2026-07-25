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
     * Full cutover — Action only. Env values ignored; MigrationMode::fromConfig luôn Action.
     */
    'automation_migration' => [
        'seo_issue_assignment' => 'action',
        'keyword_project_assignment' => 'action',
        'project_article_attach' => 'action',
        'project_task_complete' => 'action',
        'project_article_create' => 'action',
        'project_article_content_update' => 'action',
        'project_article_seo_meta_update' => 'action',
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
        /**
         * Tạm: log 1 event / cancel + snapshot busy khi build stepsForTask.
         * Tắt sau khi chốt root cause Ngắt (A/B/C/D). Không log prompt/AI output.
         */
        'cancel_debug' => (bool) env('SEO_CONTENT_PROJECT_CANCEL_DEBUG', false),

        /**
         * Phase 1 ContentProjectRunEngine — PHP owns article orchestration.
         * false = legacy JS for-loop (rollback). true = queue article jobs (global).
         * Prefer per-run/project opt-in via settings / project allowlist (Phase 1.5).
         * Never run both paths on the same run.
         */
        'php_engine' => (bool) env('CONTENT_PROJECT_PHP_ENGINE', false),

        /**
         * Comma-separated seo_projects.id allowlist for PHP engine when global flag OFF.
         * Example: CONTENT_PROJECT_PHP_ENGINE_PROJECT_IDS=12,34
         */
        'php_engine_project_ids' => array_values(array_filter(array_map(
            static fn (string $id): int => (int) trim($id),
            explode(',', (string) env('CONTENT_PROJECT_PHP_ENGINE_PROJECT_IDS', '')),
        ), static fn (int $id): bool => $id > 0)),

        /**
         * active_dispatch TTL (minutes). Release only when age ≥ TTL AND heartbeat missing/stale.
         * Job still heartbeating → không release (worker còn sống).
         */
        'active_dispatch_ttl_minutes' => (int) env('CONTENT_PROJECT_ACTIVE_DISPATCH_TTL_MINUTES', 45),

        /**
         * Heartbeat stale threshold (minutes) for WARNING + TTL release gate.
         * Quá hạn → log warning / health warn — không auto-resume ngay.
         */
        'heartbeat_stale_minutes' => (int) env('CONTENT_PROJECT_HEARTBEAT_STALE_MINUTES', 20),

        /** Queue name for RunContentProjectArticleJob. */
        'run_queue' => env('CONTENT_PROJECT_RUN_QUEUE', 'seo-content-run'),

        /**
         * Future parallel articles per run. Phase 1 engine always enforces 1.
         */
        'max_parallel_articles' => (int) env('CONTENT_PROJECT_MAX_PARALLEL_ARTICLES', 1),
    ],

    /** Log Article Editor mount/SEO bootstrap timings (no body/tokens). */
    'article_editor_perf_debug' => (bool) env('ARTICLE_EDITOR_PERF_DEBUG', false),

    /**
     * Edit Article — «Tạo gợi ý liên kết» (internal/external).
     * Không hardcode limit rải rác trong service.
     */
    'link_suggestions' => [
        'max_internal_links' => (int) env('SEO_LINK_SUGGESTIONS_MAX_INTERNAL_LINKS', 10),
        'max_display_internal' => (int) env('SEO_LINK_SUGGESTIONS_MAX_DISPLAY_INTERNAL', 10),
        'max_display_external' => (int) env('SEO_LINK_SUGGESTIONS_MAX_DISPLAY_EXTERNAL', 10),
        /** Candidates trước ranking (mỗi anchor). */
        'max_candidates' => (int) env('SEO_LINK_SUGGESTIONS_MAX_CANDIDATES', 50),
        /** Top candidates gửi AI nếu sau này bật AI ranker. */
        'max_ai_candidates' => (int) env('SEO_LINK_SUGGESTIONS_MAX_AI_CANDIDATES', 20),
        'min_accept_score' => (int) env('SEO_LINK_SUGGESTIONS_MIN_ACCEPT_SCORE', 40),
        'min_term_length' => (int) env('SEO_LINK_SUGGESTIONS_MIN_TERM_LENGTH', 3),
        'max_search_terms_per_anchor' => (int) env('SEO_LINK_SUGGESTIONS_MAX_TERMS', 12),
        'max_context_chars' => (int) env('SEO_LINK_SUGGESTIONS_MAX_CONTEXT_CHARS', 280),

        /**
         * Content-keyword fallback khi primary internal suggestions < target.
         * Deterministic — tái dụng ArticleInternalLinkSearchService (popup cùng domain).
         */
        'target_internal_suggestions' => (int) env('SEO_LINK_SUGGESTIONS_TARGET_INTERNAL', 5),
        'fallback_enabled' => (bool) env('SEO_LINK_SUGGESTIONS_FALLBACK_ENABLED', true),
        'fallback_candidate_limit' => (int) env('SEO_LINK_SUGGESTIONS_FALLBACK_CANDIDATE_LIMIT', 20),
        'fallback_phrase_limit' => (int) env('SEO_LINK_SUGGESTIONS_FALLBACK_PHRASE_LIMIT', 10),
        'fallback_min_score' => (int) env('SEO_LINK_SUGGESTIONS_FALLBACK_MIN_SCORE', 55),
        'fallback_max_words' => (int) env('SEO_LINK_SUGGESTIONS_FALLBACK_MAX_WORDS', 8),
        'fallback_min_words' => (int) env('SEO_LINK_SUGGESTIONS_FALLBACK_MIN_WORDS', 2),
        'fallback_repeated_ngram_min_count' => (int) env('SEO_LINK_SUGGESTIONS_FALLBACK_NGRAM_MIN', 2),

        /** Runtime debug — log [LINK_FALLBACK_DEBUG] + meta trong JSON response. */
        'debug' => (bool) env('LINK_SUGGESTION_DEBUG', false),

        /**
         * Stop phrases chung (primary + fallback). Không phân biệt hoa thường.
         * Keyword-only / CTA kiểu «liên hệ» không được thành Internal suggestion.
         */
        'stop_phrases' => [
            'lien he',
            'liên hệ',
            'tai day',
            'tại đây',
            'o day',
            'ở đây',
            'xem them',
            'xem thêm',
            'doc them',
            'đọc thêm',
            'xem',
            'chi tiet',
            'chi tiết',
            'click',
            'click here',
            'logo',
            'san pham',
            'sản phẩm',
            'dich vu',
            'dịch vụ',
            'chat luong',
            'chất lượng',
            'uy tin',
            'uy tín',
            'gia tot',
            'giá tốt',
            'khach hang',
            'khách hàng',
            'thong tin',
            'thông tin',
            'bai viet',
            'bài viết',
            'read more',
            'learn more',
            'contact',
            'here',
        ],

        /** @deprecated Dùng stop_phrases — giữ alias để không phá config cũ. */
        'fallback_stop_phrases' => null,
    ],
];
