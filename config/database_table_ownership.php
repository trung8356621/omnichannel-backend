<?php

declare(strict_types=1);

/**
 * Ownership table theo Laravel connection name (không hard-code tên database vật lý).
 *
 * - core: resolve qua config('database.core_connection') — thường là `mysql`
 * - omi_seo_ai / wp_headless: connection name runtime của addon
 *
 * Addon có thể bổ sung qua DeclaresDatabaseTableOwnership trên ServiceProvider.
 */
return [

    /*
    | Logical owner key => Laravel connection name resolver.
    | null = dùng config('database.core_connection').
    */
    'connection_map' => [
        'core' => null,
        'omi_seo_ai' => 'omi_seo_ai',
        'wp_headless' => 'wp_headless',
    ],

    /*
    | Connections được quét (logical keys trong connection_map).
    | Chỉ quét connection đã cấu hình và kết nối được.
    */
    'scan_owners' => [
        'core',
        'omi_seo_ai',
        'wp_headless',
    ],

    'owners' => [

        'core' => [
            'tables' => [
                // Laravel / framework
                'users',
                'password_reset_tokens',
                'sessions',
                'cache',
                'cache_locks',
                'jobs',
                'job_batches',
                'failed_jobs',
                'notifications',
                'personal_access_tokens',

                // Omnichannel core
                'wallets',
                'transactions',
                'services',
                'service_plans',
                'orders',
                'invoices',
                'subscriptions',
                'usage_logs',
                'sites',
                'site_services',
                'site_meta',
                'task_jobs',
                'frontend_projects',
                'wp_options',
                'api_connections',
                'seo_ai_models',
                'seo_database_connections',
                'seo_connection_sites',
                'seo_connection_users',
                'team_messages',
                'user_meta',

                // SEO external API credentials — migration trong addon nhưng connection = mysql (core)
                'seo_gsc_master_connections',
                'seo_gsc_property_mappings',
                'seo_dataforseo_connections',
                'seo_serp_provider_connections',
                'seo_extended_provider_connections',

                // Automation core (sau cutover AUTOMATION_DB_CONNECTION → core)
                'business_events',
                'automation_action_executions',
                'automation_action_runs',
                'automation_executions',
                'automation_node_executions',
                'automation_rule_actions',
                'automation_rule_edges',
                'automation_rule_nodes',
                'automation_rule_version_edges',
                'automation_rule_version_nodes',
                'automation_rule_versions',
                'automation_rules',
                'automation_scheduler_heartbeats',
            ],
            'patterns' => [
                'automation_*',
            ],
        ],

        'omi_seo_ai' => [
            'tables' => [
                // Khai báo tối thiểu ở config; SeoContentAiServiceProvider bổ sung đầy đủ.
            ],
            'patterns' => [
                // automation_* đã chuyển ownership sang core (xem owners.core).
            ],
        ],

        'wp_headless' => [
            'tables' => [
                'wp_headless_sites',
                'wp_headless_styles',
                'wp_headless_templates',
                'wp_headless_styles_optimized',
            ],
            'patterns' => [
                'wp_headless_*',
            ],
        ],
    ],

    'ignored_tables' => [
        'migrations',
        'sqlite_sequence',
    ],

    /*
    | Pattern luôn đưa vào report REVIEW_REQUIRED (inventory), kể cả khi ownership đã rõ.
    | Empty misplaced copy vẫn có thể drop nếu owner duy nhất đã xác định.
    */
    'review_required_patterns' => [
        // automation_* đã có owner core; giữ empty hoặc pattern khác nếu cần audit thêm.
    ],

    'report_directory' => 'app/database-cleanup',
];
