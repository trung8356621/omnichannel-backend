<?php

declare(strict_types=1);

return [
    'clustering_strategy_default' => 'balanced',
    'max_topic_depth' => 4,

    // Consumed directly by KeywordIntelligenceQuotaGuard / TopicalMapBuilder /
    // KeywordWorkspaceAnalysisService / KeywordCannibalizationService.
    'limits' => [
        'max_workspaces_per_site' => 50,
        'max_keywords_per_import' => 2000,
        'max_keywords_per_workspace' => 20000,
        'max_clusters_per_convert' => 200,
        'convert_confirmation_threshold' => 10,
    ],
    'clustering' => [
        'default_strategy' => 'balanced',
    ],
    'topical_map' => [
        'max_depth' => 3,
    ],
    'cannibalization' => [
        'multi_mapping_threshold' => 2,
    ],

    'scoring' => [
        'version' => '1',
        'weights' => [
            'relevance' => 0.30,
            'business_value' => 0.25,
            'opportunity' => 0.25,
            'intent' => 0.10,
        ],
        'penalties' => [
            'cannibalization' => 15,
            'existing_coverage' => 10,
        ],
    ],
    'quotas' => [
        'workspaces_per_tenant' => 50,
        'keywords_per_workspace' => 10000,
        'keywords_per_import' => 2000,
        'analysis_operations_per_hour' => 20,
        'clusters_converted_per_project' => 200,
    ],
];
