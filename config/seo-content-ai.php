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

    'migrations_path' => 'addons', // Phase 2: peer-owned; use AddonMigrationRegistrar for exact dirs
    'migrations_paths_note' => 'Do not rely on single path — SeoDatabaseConnectionService uses AddonMigrationRegistrar',

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

    /** Múi giờ hiển thị fallback khi chưa có seo_datetime_settings trong wp_options. Canonical: SystemDateTime + SeoDateTimeSettingsService. */
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
         * Task stuck in Writing/Generating without fresh heartbeat/active worker.
         * 0 = derive from max(run_item_stale_minutes, heartbeat_stale_minutes).
         */
        'generation_task_stale_minutes' => (int) env('SEO_CONTENT_PROJECT_GENERATION_TASK_STALE_MINUTES', 0),
        /**
         * Tạm: log 1 event / cancel + snapshot busy khi build stepsForTask.
         * Tắt sau khi chốt root cause Ngắt (A/B/C/D). Không log prompt/AI output.
         */
        'cancel_debug' => (bool) env('SEO_CONTENT_PROJECT_CANCEL_DEBUG', false),

        /**
         * Dev/recovery: Planner/Manager may override Approved ↔ Scheduled ↔ Published
         * without calling WordPress. Default OFF — never enable on production casually.
         */
        'debug_lifecycle_override' => (bool) env('CONTENT_PROJECT_DEBUG_LIFECYCLE_OVERRIDE', false),

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

    /**
     * Full article generation length contract.
     * article_length / {{article_length}} = Prompt target.
     * minimum_acceptable_words = hard OUTPUT_TRUNCATED floor (never above target).
     */
    'article_writing' => [
        'minimum_acceptable_words' => (int) env(
            'SEO_ARTICLE_WRITING_MINIMUM_ACCEPTABLE_WORDS',
            1400,
        ),
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

    /**
     * Keyword Intelligence — workspace/import/clustering/topical map/conversion.
     * Xem docs/KEYWORD_INTELLIGENCE.md.
     */
    'keyword_intelligence' => [
        'scoring' => [
            'version' => '1',
            'weights' => [
                'relevance' => 0.30,
                'business_value' => 0.25,
                'opportunity' => 0.25,
                'intent' => 0.10,
            ],
            'penalties' => [
                'cannibalization' => (float) env('SEO_KI_SCORING_PENALTY_CANNIBALIZATION', 15),
                'existing_coverage' => (float) env('SEO_KI_SCORING_PENALTY_EXISTING_COVERAGE', 10),
            ],
        ],

        'limits' => [
            'max_workspaces_per_site' => (int) env('SEO_KI_MAX_WORKSPACES_PER_SITE', 50),
            'max_keywords_per_import' => (int) env('SEO_KI_MAX_KEYWORDS_PER_IMPORT', 2000),
            'max_keywords_per_workspace' => (int) env('SEO_KI_MAX_KEYWORDS_PER_WORKSPACE', 20000),
            'max_clusters_per_convert' => (int) env('SEO_KI_MAX_CLUSTERS_PER_CONVERT', 200),
            /** Số cluster trong 1 lần convert vượt ngưỡng này bắt buộc confirmation_token. */
            'convert_confirmation_threshold' => (int) env('SEO_KI_CONVERT_CONFIRMATION_THRESHOLD', 10),
        ],

        'clustering' => [
            'default_strategy' => env('SEO_KI_DEFAULT_CLUSTERING_STRATEGY', 'balanced'),
            /** Số keyword tối đa / 1 cluster trước khi bị needs_split (KeywordClusterValidator). */
            'max_cluster_size' => (int) env('SEO_KI_CLUSTER_MAX_SIZE', 40),
        ],

        'normalization' => [
            'max_keyword_length' => (int) env('SEO_KI_NORMALIZATION_MAX_LENGTH', 255),
        ],

        'intent' => [
            'ai_confidence_threshold' => (float) env('SEO_KI_INTENT_AI_CONFIDENCE_THRESHOLD', 0.6),
            'classifier_version' => env('SEO_KI_INTENT_CLASSIFIER_VERSION', '1'),
        ],

        'near_duplicate' => [
            'threshold' => (float) env('SEO_KI_NEAR_DUPLICATE_THRESHOLD', 0.86),
            'max_candidate_pairs_per_keyword' => (int) env('SEO_KI_NEAR_DUPLICATE_MAX_PAIRS_PER_KEYWORD', 20),
            'max_bucket_size' => (int) env('SEO_KI_NEAR_DUPLICATE_MAX_BUCKET_SIZE', 200),
        ],

        'analysis' => [
            'max_keywords_per_analysis' => (int) env('SEO_KI_ANALYSIS_MAX_KEYWORDS', 5000),
            'lock_ttl_seconds' => (int) env('SEO_KI_ANALYSIS_LOCK_TTL_SECONDS', 900),
        ],

        'topical_map' => [
            'max_depth' => (int) env('SEO_KI_TOPICAL_MAP_MAX_DEPTH', 4),
            'default_mode' => env('SEO_KI_TOPICAL_MAP_DEFAULT_MODE', 'balanced'),
            'lock_ttl_seconds' => (int) env('SEO_KI_TOPICAL_MAP_LOCK_TTL_SECONDS', 900),
            'max_topics_per_workspace' => (int) env('SEO_KI_TOPICAL_MAP_MAX_TOPICS', 500),
            'max_clusters_per_map_build' => (int) env('SEO_KI_TOPICAL_MAP_MAX_CLUSTERS', 500),
            'max_link_suggestions' => (int) env('SEO_KI_TOPICAL_MAP_MAX_LINK_SUGGESTIONS', 2000),
            'max_versions_per_workspace' => (int) env('SEO_KI_TOPICAL_MAP_MAX_VERSIONS', 50),
            'map_build_operations_per_hour' => (int) env('SEO_KI_TOPICAL_MAP_BUILDS_PER_HOUR', 20),
            'modes' => [
                'conservative' => ['max_depth' => 3],
                'balanced' => ['max_depth' => 4],
                'expansive' => ['max_depth' => 5],
            ],
        ],

        'conversion' => [
            'max_items_per_project' => (int) env('SEO_KI_CONVERSION_MAX_ITEMS_PER_PROJECT', 200),
            'max_items_per_conversion' => (int) env('SEO_KI_CONVERSION_MAX_ITEMS', 200),
            'max_projects_per_conversion' => (int) env('SEO_KI_CONVERSION_MAX_PROJECTS', 1),
            'conversion_operations_per_hour' => (int) env('SEO_KI_CONVERSION_OPS_PER_HOUR', 10),
            'default_policy' => env('SEO_KI_CONVERSION_DEFAULT_POLICY', 'new_only'),
            'default_grouping' => env('SEO_KI_CONVERSION_DEFAULT_GROUPING', 'single_project'),
        ],

        'cannibalization' => [
            /** Số mapping "current_content" trên cùng keyword được coi là rủi ro. */
            'multi_mapping_threshold' => (int) env('SEO_KI_CANNIBALIZATION_MULTI_MAPPING_THRESHOLD', 2),
        ],
    ],

    /**
     * SERP Intelligence — Phase 4 core services (normalization, overlap, intent evidence, providers).
     */
    'serp_intelligence' => [
        'normalization' => [
            'max_query_length' => (int) env('SEO_SERP_MAX_QUERY_LENGTH', 500),
        ],
        'url' => [
            'trailing_slash' => (bool) env('SEO_SERP_URL_TRAILING_SLASH', false),
            'tracking_param_prefixes' => ['utm_', 'utm-'],
            'tracking_exact_params' => ['gclid', 'fbclid', 'msclkid', 'mc_cid', 'mc_eid', 'yclid', '_ga', 'ref'],
        ],
        'own_domain' => [
            'max_subdomain_depth' => (int) env('SEO_SERP_OWN_DOMAIN_MAX_SUBDOMAIN_DEPTH', 5),
        ],
        'overlap' => [
            'top_n' => (int) env('SEO_SERP_OVERLAP_TOP_N', 10),
            'min_valid' => (int) env('SEO_SERP_OVERLAP_MIN_VALID', 5),
            'position_weighted' => (bool) env('SEO_SERP_OVERLAP_POSITION_WEIGHTED', true),
            'bands' => [
                'low' => 0.15,
                'moderate' => 0.35,
                'high' => 0.55,
                'very_high' => 0.75,
            ],
        ],
        'freshness' => [
            'fresh_days' => (int) env('SEO_SERP_FRESH_DAYS', 7),
            'stale_days' => (int) env('SEO_SERP_STALE_DAYS', 30),
        ],
        'sampling' => [
            'max_queries' => (int) env('SEO_SERP_SAMPLING_MAX', 3),
            'min_queries' => (int) env('SEO_SERP_SAMPLING_MIN', 1),
        ],
        'intent' => [
            'min_evidence_confidence' => (float) env('SEO_SERP_INTENT_MIN_CONFIDENCE', 0.45),
            'compatible_mixed_groups' => [
                ['informational', 'commercial'],
                ['commercial', 'transactional'],
                ['local', 'commercial'],
            ],
        ],
        'cluster_validation' => [
            'outlier_overlap_max' => (float) env('SEO_SERP_CLUSTER_OUTLIER_MAX', 0.2),
            'split_overlap_max' => (float) env('SEO_SERP_CLUSTER_SPLIT_MAX', 0.25),
        ],
        'content_gap' => [
            'min_frequency' => (float) env('SEO_SERP_GAP_MIN_FREQUENCY', 0.3),
            'min_confidence' => (float) env('SEO_SERP_GAP_MIN_CONFIDENCE', 0.45),
            'section_min_frequency' => (float) env('SEO_SERP_GAP_SECTION_MIN_FREQUENCY', 0.4),
        ],
        'fetch' => [
            'mode' => env('SEO_SERP_FETCH_MODE', 'metadata_only'),
            'allowed_schemes' => ['http', 'https'],
            'blocked_hosts' => ['localhost', '127.0.0.1', '0.0.0.0', '::1', '169.254.169.254'],
            'redirect_limit' => (int) env('SEO_SERP_FETCH_REDIRECT_LIMIT', 3),
            'max_bytes' => (int) env('SEO_SERP_FETCH_MAX_BYTES', 1_048_576),
            'timeout_seconds' => (int) env('SEO_SERP_FETCH_TIMEOUT', 15),
        ],
        'providers' => [
            'enabled' => array_values(array_filter(array_map(
                static fn (string $key): string => trim($key),
                explode(',', (string) env('SEO_SERP_PROVIDERS_ENABLED', 'manual_import')),
            ))),
        ],
        'lock' => [
            'ttl_seconds' => (int) env('SEO_SERP_LOCK_TTL_SECONDS', 600),
        ],
    ],

    'gsc_intelligence' => [
        'normalization' => [
            'max_query_length' => (int) env('SEO_GSC_MAX_QUERY_LENGTH', 500),
        ],
        'sync' => [
            'data_delay_days' => (int) env('SEO_GSC_DATA_DELAY_DAYS', 3),
            'incremental_overlap_days' => (int) env('SEO_GSC_INCREMENTAL_OVERLAP_DAYS', 2),
            'max_days_per_chunk' => (int) env('SEO_GSC_MAX_DAYS_PER_CHUNK', 28),
        ],
        'lock' => [
            'ttl_seconds' => (int) env('SEO_GSC_LOCK_TTL_SECONDS', 600),
        ],
        'providers' => [
            'enabled' => array_values(array_filter(array_map(
                static fn (string $key): string => trim($key),
                explode(',', (string) env('SEO_GSC_PROVIDERS_ENABLED', 'manual_import')),
            ))),
        ],
        'expected_ctr' => [
            'bands' => [
                ['position_min' => 1, 'position_max' => 1, 'ctr' => 0.28],
                ['position_min' => 2, 'position_max' => 3, 'ctr' => 0.15],
                ['position_min' => 4, 'position_max' => 5, 'ctr' => 0.08],
                ['position_min' => 6, 'position_max' => 10, 'ctr' => 0.04],
                ['position_min' => 11, 'position_max' => 20, 'ctr' => 0.02],
                ['position_min' => 21, 'position_max' => 100, 'ctr' => 0.005],
            ],
        ],
        'opportunity' => [
            'min_impressions' => (int) env('SEO_GSC_OPP_MIN_IMPRESSIONS', 100),
            'min_impressions_growth_pct' => (float) env('SEO_GSC_OPP_MIN_GROWTH_PCT', 0.25),
            'near_page_one_max_position' => (float) env('SEO_GSC_OPP_NEAR_PAGE_ONE_MAX', 15),
            'low_ctr_gap_min' => (float) env('SEO_GSC_OPP_LOW_CTR_GAP_MIN', 0.02),
            'decay_clicks_drop_pct' => (float) env('SEO_GSC_OPP_DECAY_DROP_PCT', 0.30),
            'maturity' => [
                'new_days' => (int) env('SEO_GSC_OPP_MATURITY_NEW_DAYS', 14),
                'early_days' => (int) env('SEO_GSC_OPP_MATURITY_EARLY_DAYS', 60),
            ],
        ],
        'cannibalization' => [
            'min_competing_pages' => (int) env('SEO_GSC_CANNIB_MIN_PAGES', 2),
            'min_impressions_per_page' => (int) env('SEO_GSC_CANNIB_MIN_IMPRESSIONS', 10),
        ],
        'brand' => [
            'terms' => array_values(array_filter(array_map(
                static fn (string $term): string => trim($term),
                explode(',', (string) env('SEO_GSC_BRAND_TERMS', '')),
            ))),
        ],
    ],

    /**
     * Product Gallery Mode 1 — sprite validator + original fallback.
     * Nghi ngờ → fallback gốc; không chase collage hoàn hảo.
     */
    'product_gallery' => [
        'mode' => 'mode_1_validator_fallback',
        'default_mode' => 'sprite', // sprite | parent_child | auto
        'minimum_required_images' => 1,
        'sprite_validator' => [
            'confidence_threshold' => 0.8,
            'min_canvas_px' => 256,
            'min_panel_count_ratio' => 1.0,
            'soft_weights' => [
                'gutter_uniformity' => 0.25,
                'cell_squareness' => 0.2,
                'area_uniformity' => 0.2,
                'whitespace' => 0.2,
                'crop_safety' => 0.15,
            ],
        ],
        'parent_child' => [
            // GA default ON; kill switch via env false. Non-empty canary_article_ids restores allowlist.
            'enabled' => (bool) env('SEO_PRODUCT_GALLERY_PARENT_CHILD_ENABLED', true),
            'canary_article_ids' => [],
            'minimum_required_images' => 1,
            'max_shots' => 9,
            'child_retry_count' => 1,
            'fallback_to_sprite' => true,
            'supported_aspect_ratios' => ['1:1', '4:3', '3:4', '16:9', '9:16'],
        ],
        'canary' => [
            // Filament page + ListSeoProjects action (admin/manager + local/staging/flag).
            'fixture_ui_enabled' => (bool) env('SEO_PRODUCT_GALLERY_CANARY_UI', false),
            // Fixture articles (is_canary meta) pass Mode 2 allowlist when true.
            'auto_allow_fixture_articles' => true,
            'min_original_media' => 2,
        ],
    ],
];
