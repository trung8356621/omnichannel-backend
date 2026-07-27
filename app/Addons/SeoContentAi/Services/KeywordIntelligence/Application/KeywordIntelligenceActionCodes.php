<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\KeywordIntelligence\Application;

/**
 * Mã kết quả chuẩn cho Keyword Intelligence — Filament/API/Agent đọc code, không parse message.
 */
final class KeywordIntelligenceActionCodes
{
    public const WORKSPACE_CREATED = 'keyword.workspace_created';

    public const IMPORTED = 'keyword.imported';

    public const ANALYZED = 'keyword.analyzed';

    public const CLUSTER_CREATED = 'keyword.cluster_created';

    public const TOPICAL_MAP_BUILT = 'keyword.topical_map_built';

    public const CONTENT_PROJECT_CREATED = 'keyword.content_project_created';

    public const CLUSTERS_APPROVED = 'keyword.clusters_approved';

    public const CLUSTERS_EXCLUDED = 'keyword.clusters_excluded';

    public const KEYWORDS_REVIEWED = 'keyword.keywords_reviewed';

    public const PREVIEW_GENERATED = 'keyword.preview_generated';

    public const KEYWORDS_APPROVED = 'keyword.keywords_approved';

    public const WORKSPACE_ARCHIVED_OK = 'keyword.workspace_archived_ok';

    public const PREVIEW_READY = 'keyword.preview_ready';

    public const WORKSPACE_LIMIT_EXCEEDED = 'keyword.workspace_limit_exceeded';

    public const IMPORT_TOO_LARGE = 'keyword.import_too_large';

    public const ANALYSIS_QUOTA_EXCEEDED = 'keyword.analysis_quota_exceeded';

    public const CONVERSION_TOO_LARGE = 'keyword.conversion_too_large';

    public const WORKSPACE_ARCHIVED = 'keyword.workspace_archived';

    public const NOT_FOUND = 'keyword.not_found';

    public const VALIDATION_FAILED = 'keyword.validation_failed';

    public const FORBIDDEN = 'keyword.forbidden';

    public const CONFIRMATION_REQUIRED = 'keyword.confirmation_required';

    public const FAILED = 'keyword.failed';
}
